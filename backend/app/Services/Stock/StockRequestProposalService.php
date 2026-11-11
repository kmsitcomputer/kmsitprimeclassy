<?php

namespace App\Services\Stock;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\ProductStock;
use App\Models\ProductVariationStock;
use App\Models\StockMovement;
use App\Models\StockRequest;
use App\Models\StockRequestFulfillment;
use App\Models\StockRequestProposal;
use App\Models\User;
use App\Models\WarehouseStock;
use Illuminate\Support\Facades\DB;

class StockRequestProposalService
{
    /**
     * Gudang's fulfilment of the Koordinator Kurir's request: the quantity Gudang CAN hand over per item
     * (`$items`), the items it can hand over NOTHING of (`$shortages`, quantity 0), and the handover
     * evidence. Any item handed over for less than its outstanding quantity — or not at all — needs a
     * reason; that shortage is recorded on the demand line (audit trail) and raises ONE "PEMENUHAN KURANG"
     * notification for the branch Admin/Agen. No stock moves here: the Koordinator's physical receipt
     * ({@see receive()}) remains the single authoritative stock event. Admin takes no part in this step.
     *
     * @param  array<int, array{item_id:int, quantity:int, shortage_reason?:?string}>  $items
     * @param  array<int, array{item_id:int, reason:string}>  $shortages
     */
    public function propose(User $actor, StockRequest $request, array $items, array $shortages = [], ?\Illuminate\Http\UploadedFile $proof = null): StockRequestProposal
    {
        if (! $actor->isRole('gudang') || ! $actor->agent_id) {
            throw new ApiException('Hanya Gudang dengan network valid.', 403);
        }
        if ($items === []) {
            throw new ApiException('Proposal harus memiliki item.', 422);
        }

        return DB::transaction(function () use ($actor, $request, $items, $shortages, $proof) {
            // A1-04: canonical serialization boundary — lock the ORDER row
            // first. CourierService::assignCourier locks the same Order row
            // first too, so a Gudang proposal and a dispatch assignment can
            // never interleave: whichever transaction wins the Order lock sees
            // the other's committed result (assignment won → this recheck
            // rejects a now-handled order; proposal won first → assignment
            // simply targets an order whose fulfillment work is being
            // proposed — the intended flow, no deadlock because we never lock
            // the Shipment rows here and assignment never locks StockRequest).
            $proposalOrder = Order::query()->whereKey($request->order_id)->lockForUpdate()->firstOrFail();
            if ((int) $proposalOrder->agent_id !== (int) $actor->agent_id) {
                throw new ApiException('Hanya Gudang dengan network valid.', 403);
            }

            $locked = StockRequest::withoutGlobalScopes()->with(['items'])->whereKey($request->id)
                ->where('order_id', $proposalOrder->id)
                ->lockForUpdate()->firstOrFail();
            if ($locked->status === 'cancelled' || $locked->status === 'fulfilled') {
                throw new ApiException('Stock Request tidak dapat diusulkan lagi.', 422);
            }

            // IMP-001 UAT remediation (Gap 2) — the exact visibility invariant,
            // enforced server-side HERE too (in addition to OrderPolicy::view and
            // the WarehouseOrderController query): a Gudang proposal is only valid
            // while the order is still in the warehouse work queue, i.e. status is
            // exactly 'diproses' AND no courier has been assigned yet (no shipment
            // carries courier_id, and no self_sub shipment has a self-delivering
            // Sales-Kurir-Sub). A stale/concurrent courier assignment or status
            // change makes the proposal invalid — Gudang can never bypass the scope
            // rule by calling the stock-request endpoint directly. Re-checked NOW
            // under the Order lock just taken, so a concurrent assignment can no
            // longer slip past (A1-04).
            // REM-001: scope = legacy queue rule OR outstanding Agent demand of a later batch
            // ({@see Order::isInWarehouseScope()}), re-checked under the Order lock just taken.
            if (! in_array($proposalOrder->status, ['diproses', 'dikirim'], true) || ! $proposalOrder->isInWarehouseScope()) {
                throw new ApiException('Order tidak lagi dalam antrean Gudang; tidak dapat mengusulkan fulfillment.', 422);
            }

            $byId = $locked->items->keyBy('id');
            $seen = [];
            $shortageLines = [];
            $proposal = StockRequestProposal::create([
                'agent_id' => $actor->agent_id, 'stock_request_id' => $locked->id,
                'status' => 'pending', 'requested_by' => $actor->id,
            ]);
            foreach ($items as $input) {
                $item = $byId->get((int) ($input['item_id'] ?? 0));
                $qty = (int) ($input['quantity'] ?? 0);
                if (! $item || $qty <= 0 || $qty > $item->remaining_qty - (int) $item->damaged_hold_qty) { // F07: held damaged units cannot be re-prepared
                    throw new ApiException('Jumlah proposal melebihi sisa request.', 422);
                }
                if (isset($seen[$item->id])) {
                    throw new ApiException('Item proposal tidak boleh duplikat.', 422);
                }
                $seen[$item->id] = true;
                $proposal->items()->create(['stock_request_item_id' => $item->id, 'quantity' => $qty]);
                $available = $item->remaining_qty - (int) $item->damaged_hold_qty;
                if ($qty < $available) {
                    $reason = trim((string) ($input['shortage_reason'] ?? ''));
                    if (mb_strlen($reason) < 3) {
                        throw new ApiException(__('messages.fulfillment.shortage_reason_required'), 422);
                    }
                    $shortageLines[] = [$item, $qty, $reason];
                }
            }
            foreach ($shortages as $input) {
                $item = $byId->get((int) ($input['item_id'] ?? 0));
                $reason = trim((string) ($input['reason'] ?? ''));
                if (! $item || $item->remaining_qty <= 0) {
                    throw new ApiException(__('messages.fulfillment.shortage_item_invalid'), 422);
                }
                if (isset($seen[$item->id])) {
                    throw new ApiException(__('messages.fulfillment.shortage_item_duplicate'), 422);
                }
                if (mb_strlen($reason) < 3) {
                    throw new ApiException(__('messages.fulfillment.shortage_reason_required'), 422);
                }
                $seen[$item->id] = true;
                $shortageLines[] = [$item, 0, $reason];
            }

            if ($proof !== null) {
                app(\App\Services\Media\MediaService::class)->store($proof, 'fulfillment_handover_proof', $proposal, $actor);
            }

            if ($shortageLines !== []) {
                $this->recordShortages($actor, $proposalOrder, $proposal, $shortageLines);
            }

            return $proposal->fresh()->load(['items.requestItem.product', 'items.requestItem.variation', 'requester']);
        });
    }

    /**
     * Gudang can hand over NOTHING for the lines it lists: the shortage (with reason) is still recorded and
     * the Admin notified, but no proposal exists (there is nothing to receive) and no stock moves. Same lock
     * order and queue-scope check as {@see propose()}.
     *
     * @param  array<int, array{item_id:int, reason:string}>  $shortages
     * @return array<int, array{stock_request_item_id:int, reason:string}>
     */
    public function reportShortageOnly(User $actor, StockRequest $request, array $shortages): array
    {
        if (! $actor->isRole('gudang') || ! $actor->agent_id) {
            throw new ApiException('Hanya Gudang dengan network valid.', 403);
        }
        if ($shortages === []) {
            throw new ApiException(__('messages.fulfillment.shortage_item_invalid'), 422);
        }

        return DB::transaction(function () use ($actor, $request, $shortages) {
            $order = Order::query()->whereKey($request->order_id)->lockForUpdate()->firstOrFail();
            if ((int) $order->agent_id !== (int) $actor->agent_id) {
                throw new ApiException('Hanya Gudang dengan network valid.', 403);
            }
            $locked = StockRequest::withoutGlobalScopes()->with('items')->whereKey($request->id)->where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['cancelled', 'fulfilled'], true)
                || ! in_array($order->status, ['diproses', 'dikirim'], true) || ! $order->isInWarehouseScope()) {
                throw new ApiException('Order tidak lagi dalam antrean Gudang; tidak dapat mengusulkan fulfillment.', 422);
            }

            $byId = $locked->items->keyBy('id');
            $seen = [];
            $lines = [];
            foreach ($shortages as $input) {
                $item = $byId->get((int) ($input['item_id'] ?? 0));
                $reason = trim((string) ($input['reason'] ?? ''));
                if (! $item || $item->remaining_qty <= 0) {
                    throw new ApiException(__('messages.fulfillment.shortage_item_invalid'), 422);
                }
                if (isset($seen[$item->id])) {
                    throw new ApiException(__('messages.fulfillment.shortage_item_duplicate'), 422);
                }
                if (mb_strlen($reason) < 3) {
                    throw new ApiException(__('messages.fulfillment.shortage_reason_required'), 422);
                }
                $seen[$item->id] = true;
                $lines[] = [$item, 0, $reason];
            }
            $this->recordShortages($actor, $order, null, $lines);

            return array_map(fn ($l) => ['stock_request_item_id' => $l[0]->id, 'reason' => $l[2]], $lines);
        });
    }

    /**
     * Records what Gudang could NOT hand over, per demand line, with the reason, and notifies the branch
     * Admin/Agen once per proposal (deduplicated). Everything is inside the caller's transaction and under
     * the Order/Stock Request locks, so a retry or a concurrent proposal cannot double-record it.
     *
     * @param  array<int, array{0:\App\Models\StockRequestItem, 1:int, 2:string}>  $lines
     */
    private function recordShortages(User $actor, Order $order, ?StockRequestProposal $proposal, array $lines): void
    {
        foreach ($lines as [$item, $handedOver, $reason]) {
            \App\Services\Logging\ActivityLogger::log($actor->id, $item, 'stock_request.fulfillment_shortage', $reason, [
                'order_id' => $order->id, 'order_item_id' => $item->order_item_id, 'proposal_id' => $proposal?->id,
                'requested_qty' => (int) $item->requested_qty, 'outstanding_before' => (int) $item->remaining_qty,
                'handed_over_qty' => $handedOver, 'short_qty' => (int) $item->remaining_qty - $handedOver,
            ]);
        }

        [$subjectType, $subjectId] = $proposal ? ['stock_request_proposal', $proposal->id] : ['stock_request', $lines[0][0]->stock_request_id];
        app(\App\Services\Notifications\AdminNotificationService::class)->fulfillmentShortage($order, $subjectType, (int) $subjectId, count($lines));
    }

    /**
     * Admin exception path (legacy approval). Routine fulfilment is settled by the Koordinator Kurir's
     * physical receipt ({@see receive()}); the Admin keeps this as the exceptional override and it
     * settles the whole prepared quantity as accepted. Both paths share one settlement routine and
     * one status gate (pending → approved), so a proposal can only ever move stock once.
     */
    public function approve(User $actor, StockRequestProposal $proposal, ?string $reason = null): StockRequestProposal
    {
        if (! $actor->isRole('admin')) {
            throw new ApiException('Hanya Admin yang dapat menyetujui proposal.', 403);
        }

        // F07: the Admin path is an EXCEPTION to the Koordinator receipt — its reason is recorded in the audit log.
        return $this->settle($actor, $proposal, null, $reason);
    }

    /**
     * REM-002 — the Koordinator Kurir confirms physical receipt of what Gudang handed over. Each
     * proposal item must be fully accounted for: accepted + short + damaged = prepared quantity
     * (accepted can never exceed prepared). Only accepted units move stock and count as fulfilled;
     * short/damaged units stay in Transit with their reservation intact, remain outstanding demand,
     * and are NOT sellable/shippable — resolution is an Admin exception, never automatic.
     *
     * @param  array<int, array{item_id:int, accepted:int, short:int, damaged:int}>  $lines
     */
    public function receive(User $actor, StockRequestProposal $proposal, array $lines, ?string $note = null): StockRequestProposal
    {
        if (! $actor->isRole('koordinator-kurir') || ! $actor->agent_id) {
            throw new ApiException('Hanya Koordinator Kurir yang dapat mengonfirmasi penerimaan.', 403);
        }

        return $this->settle($actor, $proposal, $lines, $note);
    }

    private function settle(User $actor, StockRequestProposal $proposal, ?array $receipt, ?string $note): StockRequestProposal
    {
        return DB::transaction(function () use ($actor, $proposal, $receipt, $note) {
            $locked = StockRequestProposal::withoutGlobalScopes()->with(['items.requestItem'])->whereKey($proposal->id)->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'approved') {
                return $locked->load(['items.requestItem.product', 'items.requestItem.variation', 'requester']);
            }
            if ($locked->status !== 'pending') {
                throw new ApiException('Proposal sudah diproses.', 422);
            }
            $request = StockRequest::withoutGlobalScopes()->with('items')->whereKey($locked->stock_request_id)->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrFail();
            if ($request->status === 'cancelled' || $request->status === 'fulfilled') {
                throw new ApiException('Stock Request tidak dapat dipenuhi lagi.', 422);
            }

            // Read current demand under its lock, including holds committed while this proposal waited.
            $request->setRelation('items', \App\Models\StockRequestItem::query()
                ->where('stock_request_id', $request->id)->orderBy('id')->lockForUpdate()->get());

            // Accepted quantity per proposal item. Admin exception = everything prepared; Koordinator
            // receipt = what was physically accepted (validated to account for every prepared unit).
            $accepted = [];
            $byProposalItem = $receipt === null ? null : collect($receipt)->keyBy(fn ($l) => (int) ($l['item_id'] ?? 0));
            if ($byProposalItem !== null && $byProposalItem->count() !== $locked->items->count()) {
                throw new ApiException('Penerimaan harus mencakup semua item proposal.', 422);
            }
            foreach ($locked->items as $proposalItem) {
                if ($receipt === null) {
                    $accepted[$proposalItem->id] = (int) $proposalItem->quantity;

                    continue;
                }
                $line = $byProposalItem->get($proposalItem->id);
                $a = (int) ($line['accepted'] ?? -1);
                $s = (int) ($line['short'] ?? -1);
                $d = (int) ($line['damaged'] ?? -1);
                if (! $line || $a < 0 || $s < 0 || $d < 0 || $a + $s + $d !== (int) $proposalItem->quantity) {
                    throw new ApiException('Diterima + kurang + rusak harus sama dengan jumlah yang diserahkan Gudang.', 422);
                }
                $accepted[$proposalItem->id] = $a;
            }

            $lines = [];
            foreach ($locked->items as $proposalItem) {
                $item = $request->items->firstWhere('id', $proposalItem->stock_request_item_id);
                if (! $item || $item->stock_request_id !== $request->id || $proposalItem->quantity <= 0 || $proposalItem->quantity > $item->remaining_qty - (int) $item->damaged_hold_qty) {
                    throw new ApiException('Jumlah proposal melebihi sisa request.', 422);
                }
                if ($accepted[$proposalItem->id] === 0) {
                    continue; // nothing accepted → no stock movement, request demand unchanged
                }
                $key = StockService::canonicalTargetKey($item->product_id, $item->product_variation_id);
                $lines[] = [$proposalItem, $item, $key];
            }
            $targets = StockService::canonicalReservationTargets(array_map(fn ($line) => [
                'product_id' => $line[1]->product_id,
                'product_variation_id' => $line[1]->product_variation_id,
            ], $lines));
            // Same Agent commitment → Transit/Plan order as checkout and SC-03.
            app(StockService::class)->lockReservationTargets($request->agent_id, $targets);
            $targetKeys = array_map(fn ($target) => StockService::canonicalTargetKey($target['product_id'], $target['product_variation_id']), $targets);
            $lockedBuckets = [];
            foreach ($targetKeys as $key) {
                [, $item] = current(array_filter($lines, fn ($line) => $line[2] === $key));
                $source = $this->transit($request->agent_id, $item)->lockForUpdate()->first();
                $destination = $this->shipping($request->agent_id, $item)->lockForUpdate()->first();
                $reservation = $item->product_variation_id
                    ? ProductVariationStock::withoutGlobalScopes()->where('agent_id', $request->agent_id)->where('product_variation_id', $item->product_variation_id)->lockForUpdate()->first()
                    : ProductStock::withoutGlobalScopes()->where('agent_id', $request->agent_id)->where('product_id', $item->product_id)->lockForUpdate()->first();
                $lockedBuckets[$key] = [$source, $destination, $reservation];
            }

            $totals = [];
            foreach ($lines as [$proposalItem, $item, $key]) {
                $totals[$key] = ($totals[$key] ?? 0) + $accepted[$proposalItem->id];
            }
            foreach ($totals as $key => $total) {
                [$source, , $reservation] = $lockedBuckets[$key];
                if (! $source || $source->quantity < $total) {
                    throw new ApiException('Stok fisik Transit tidak mencukupi.', 422);
                }
                if (! $reservation || $reservation->quantity_reserved < $total) {
                    throw new ApiException('Reservasi tidak mencukupi untuk fulfillment.', 422);
                }
            }

            $operation = $totals === [] ? null : StockRequestFulfillment::create(['stock_request_id' => $request->id, 'idempotency_key' => 'proposal-'.$locked->id, 'fulfilled_by' => $actor->id]);
            foreach ($totals as $key => $total) {
                [$source, $destination, $reservation] = $lockedBuckets[$key];
                [, $item] = current(array_filter($lines, fn ($line) => $line[2] === $key));
                $source->decrement('quantity', $total);
                $destination ??= WarehouseStock::create(['agent_id' => $request->agent_id, 'product_id' => $item->product_variation_id ? null : $item->product_id, 'product_variation_id' => $item->product_variation_id, 'stock_type' => 'shipping', 'quantity' => 0]);
                $destination->increment('quantity', $total);
                $reservation->decrement('quantity_reserved', $total);
                $common = ['agent_id' => $request->agent_id, 'product_id' => $item->product_variation_id ? null : $item->product_id, 'product_variation_id' => $item->product_variation_id, 'type' => 'fulfillment', 'reference_type' => StockRequest::class, 'reference_id' => $request->id, 'created_by' => $actor->id, 'note' => 'fulfillment_operation='.$operation->id.';proposal='.$locked->id];
                StockMovement::create($common + ['stock_type' => 'transit', 'quantity' => -$total, 'sub_location_id' => null, 'counterpart_stock_type' => 'shipping']);
                StockMovement::create($common + ['stock_type' => 'shipping', 'quantity' => $total, 'sub_location_id' => null, 'counterpart_stock_type' => 'transit']);
            }
            foreach ($lines as [$proposalItem, $item]) {
                $qty = $accepted[$proposalItem->id];
                $item->update(['fulfilled_qty' => $item->fulfilled_qty + $qty, 'remaining_qty' => $item->remaining_qty - $qty]);
            }
            // Locking read: a plain sum() would use the REPEATABLE READ snapshot taken before this
            // transaction waited on the Stock Request lock and could miss a line SC-03 appended and
            // committed meanwhile (marking the request fulfilled while demand is still outstanding).
            $lockedItems = $request->items()->lockForUpdate()->get();
            $remaining = (int) $lockedItems->sum('remaining_qty');
            $fulfilled = (int) $lockedItems->sum('fulfilled_qty');
            $request->update(['status' => $remaining === 0 ? 'fulfilled' : ($fulfilled > 0 ? 'partial' : 'pending'), 'fulfilled_at' => $remaining === 0 ? now() : null]);
            if ($receipt === null) {
                $locked->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'receipt_note' => $note]);
                \App\Services\Logging\ActivityLogger::log($actor->id, $locked, 'stock_request.admin_exception_approval', $note, ['stock_request_id' => $request->id]);
            } else {
                // F07: damaged units are NOT accepted and NOT sellable — hold them on the line until an Admin resolves them.
                foreach ($locked->items as $proposalItem) {
                    $damaged = (int) $byProposalItem->get($proposalItem->id)['damaged'];
                    if ($damaged > 0) {
                        $requestItem = $request->items->firstWhere('id', $proposalItem->stock_request_item_id);
                        $requestItem?->increment('damaged_hold_qty', $damaged);
                    }
                }
                foreach ($locked->items as $proposalItem) {
                    $line = $byProposalItem->get($proposalItem->id);
                    $proposalItem->update(['accepted_qty' => (int) $line['accepted'], 'short_qty' => (int) $line['short'], 'damaged_qty' => (int) $line['damaged']]);
                }
                $locked->update(['status' => 'approved', 'received_by' => $actor->id, 'received_at' => now(), 'receipt_note' => $note]);
            }

            return $locked->fresh()->load(['items.requestItem.product', 'items.requestItem.variation', 'requester']);
        });
    }

    public function reject(User $actor, StockRequestProposal $proposal, string $reason): StockRequestProposal
    {
        if (! $actor->isRole('admin')) {
            throw new ApiException('Hanya Admin yang dapat menolak proposal.', 403);
        }

        return DB::transaction(function () use ($actor, $proposal, $reason) {
            $locked = StockRequestProposal::withoutGlobalScopes()->whereKey($proposal->id)->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'rejected') {
                return $locked->load(['items.requestItem.product', 'items.requestItem.variation', 'requester']);
            }
            if ($locked->status !== 'pending') {
                throw new ApiException('Proposal sudah diproses.', 422);
            }
            $locked->update(['status' => 'rejected', 'rejected_by' => $actor->id, 'rejected_at' => now(), 'rejection_reason' => $reason]);

            return $locked->fresh()->load(['items.requestItem.product', 'items.requestItem.variation', 'requester']);
        });
    }

    private function transit(int $agentId, $item)
    {
        return WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('stock_type', 'transit')->whereNull('sub_location_id')
            ->when($item->product_variation_id, fn ($q) => $q->where('product_variation_id', $item->product_variation_id)->whereNull('product_id'))
            ->when(! $item->product_variation_id, fn ($q) => $q->where('product_id', $item->product_id)->whereNull('product_variation_id'));
    }

    private function shipping(int $agentId, $item)
    {
        return WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('stock_type', 'shipping')->whereNull('sub_location_id')
            ->when($item->product_variation_id, fn ($q) => $q->where('product_variation_id', $item->product_variation_id)->whereNull('product_id'))
            ->when(! $item->product_variation_id, fn ($q) => $q->where('product_id', $item->product_id)->whereNull('product_variation_id'));
    }

    /**
     * F07 — Admin resolves held damaged units of one demand line. Restock is blocked until the Owner
     * approves a verifiable physical-inspection workflow. `write_off`: Transit is reduced by an
     * `adjustment` movement and the hold is lifted; the line still owes those units (its reservation stays), so
     * Gudang prepares replacements. Nothing else may release a held unit.
     */
    public function resolveDiscrepancy(User $actor, \App\Models\StockRequestItem $item, string $action, int $quantity, string $reason, string $idempotencyKey): \App\Models\StockRequestItem
    {
        if (! $actor->isRole('admin') || ! $actor->agent_id) {
            throw new ApiException('Hanya Admin yang dapat menyelesaikan selisih stok.', 403);
        }
        if (! in_array($action, ['restock', 'write_off'], true)) {
            throw new ApiException('Aksi tidak valid.', 422);
        }

        if ($action === 'restock') {
            throw new ApiException('Restock ditahan sampai prosedur inspeksi fisik disetujui Owner.', 422);
        }
        if (! \Illuminate\Support\Str::isUuid($idempotencyKey)) {
            throw new ApiException('Kunci idempotensi UUID wajib diisi.', 422);
        }

        return DB::transaction(function () use ($actor, $item, $action, $quantity, $reason, $idempotencyKey) {
            $request = StockRequest::withoutGlobalScopes()->whereKey($item->stock_request_id)->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrFail();
            $locked = \App\Models\StockRequestItem::query()->whereKey($item->id)->where('stock_request_id', $request->id)->lockForUpdate()->firstOrFail();
            // The item lock serializes all resolutions. A locking read sees a committed retry key
            // even under REPEATABLE READ; persist it atomically with the movement and hold change.
            $previous = \App\Models\ActivityLog::query()->where('subject_type', $locked::class)
                ->where('subject_id', $locked->id)->where('event', 'stock_request.discrepancy_resolved')
                ->where('properties->idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($previous) {
                throw new ApiException('Resolusi dengan kunci ini sudah diproses.', 409);
            }
            if ($quantity < 1 || $quantity > (int) $locked->damaged_hold_qty) {
                throw new ApiException('Jumlah melebihi selisih rusak yang tertahan.', 422);
            }
            if ($action === 'write_off') {
                $transit = $this->transit($request->agent_id, $locked)->lockForUpdate()->first();
                if (! $transit || $transit->quantity < $quantity) {
                    throw new ApiException('Stok Transit tidak mencukupi untuk penghapusan.', 422);
                }
                $transit->decrement('quantity', $quantity);
                StockMovement::create([
                    'agent_id' => $request->agent_id, 'product_id' => $locked->product_variation_id ? null : $locked->product_id, 'product_variation_id' => $locked->product_variation_id,
                    'type' => 'adjustment', 'stock_type' => 'transit', 'quantity' => -$quantity, 'reference_type' => StockRequest::class, 'reference_id' => $request->id,
                    'created_by' => $actor->id, 'note' => 'damaged_write_off;item='.$locked->id.';reason='.mb_substr($reason, 0, 120),
                ]);
            }
            $locked->update(['damaged_hold_qty' => $locked->damaged_hold_qty - $quantity]);
            \App\Services\Logging\ActivityLogger::log($actor->id, $locked, 'stock_request.discrepancy_resolved', $reason, ['action' => $action, 'quantity' => $quantity, 'idempotency_key' => $idempotencyKey]);

            return $locked->fresh();
        });
    }
}
