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
    public function propose(User $actor, StockRequest $request, array $items): StockRequestProposal
    {
        if (! $actor->isRole('gudang') || ! $actor->agent_id) {
            throw new ApiException('Hanya Gudang dengan network valid.', 403);
        }
        if ($items === []) {
            throw new ApiException('Proposal harus memiliki item.', 422);
        }

        return DB::transaction(function () use ($actor, $request, $items) {
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
            if ($proposalOrder->status !== 'diproses') {
                throw new ApiException('Order tidak lagi diproses; tidak dapat mengusulkan fulfillment.', 422);
            }
            $hasCourier = DB::table('shipments')
                ->where('order_id', $proposalOrder->id)
                ->where(fn ($q) => $q->whereNotNull('courier_id')->orWhereNotNull('self_delivered_by_user_id'))
                ->lockForUpdate()->get(['id'])->isNotEmpty();
            if ($hasCourier) {
                throw new ApiException('Order sudah memiliki kurir; tidak dapat mengusulkan fulfillment.', 422);
            }

            $byId = $locked->items->keyBy('id');
            $seen = [];
            $proposal = StockRequestProposal::create([
                'agent_id' => $actor->agent_id, 'stock_request_id' => $locked->id,
                'status' => 'pending', 'requested_by' => $actor->id,
            ]);
            foreach ($items as $input) {
                $item = $byId->get((int) ($input['item_id'] ?? 0));
                $qty = (int) ($input['quantity'] ?? 0);
                if (! $item || $qty <= 0 || $qty > $item->remaining_qty) {
                    throw new ApiException('Jumlah proposal melebihi sisa request.', 422);
                }
                if (isset($seen[$item->id])) {
                    throw new ApiException('Item proposal tidak boleh duplikat.', 422);
                }
                $seen[$item->id] = true;
                $proposal->items()->create(['stock_request_item_id' => $item->id, 'quantity' => $qty]);
            }

            return $proposal->fresh()->load(['items.requestItem.product', 'items.requestItem.variation', 'requester']);
        });
    }

    public function approve(User $actor, StockRequestProposal $proposal): StockRequestProposal
    {
        if (! $actor->isRole('admin')) {
            throw new ApiException('Hanya Admin yang dapat menyetujui proposal.', 403);
        }

        return DB::transaction(function () use ($actor, $proposal) {
            $locked = StockRequestProposal::withoutGlobalScopes()->with(['items.requestItem', 'request.items'])->whereKey($proposal->id)->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrFail();
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

            $lines = [];
            foreach ($locked->items as $proposalItem) {
                $item = $request->items->firstWhere('id', $proposalItem->stock_request_item_id);
                if (! $item || $item->stock_request_id !== $request->id || $proposalItem->quantity <= 0 || $proposalItem->quantity > $item->remaining_qty) {
                    throw new ApiException('Jumlah proposal melebihi sisa request.', 422);
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
                $totals[$key] = ($totals[$key] ?? 0) + $proposalItem->quantity;
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

            $operation = StockRequestFulfillment::create(['stock_request_id' => $request->id, 'idempotency_key' => 'proposal-'.$locked->id, 'fulfilled_by' => $actor->id]);
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
                $qty = $proposalItem->quantity;
                $item->update(['fulfilled_qty' => $item->fulfilled_qty + $qty, 'remaining_qty' => $item->remaining_qty - $qty]);
            }
            // Locking read: a plain sum() would use the REPEATABLE READ snapshot taken before this
            // transaction waited on the Stock Request lock and could miss a line SC-03 appended and
            // committed meanwhile (marking the request fulfilled while demand is still outstanding).
            $lockedItems = $request->items()->lockForUpdate()->get();
            $remaining = (int) $lockedItems->sum('remaining_qty');
            $fulfilled = (int) $lockedItems->sum('fulfilled_qty');
            $request->update(['status' => $remaining === 0 ? 'fulfilled' : ($fulfilled > 0 ? 'partial' : 'pending'), 'fulfilled_at' => $remaining === 0 ? now() : null]);
            $locked->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);

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
}
