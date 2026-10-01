<?php

namespace App\Services\Stock;

use App\Exceptions\ApiException;
use App\Models\ProductStock;
use App\Models\ProductVariationStock;
use App\Models\StockMovement;
use App\Models\StockRequest;
use App\Models\StockRequestFulfillment;
use App\Models\StockRequestProposal;
use App\Models\StockRequestProposalItem;
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
            $locked = StockRequest::withoutGlobalScopes()->with('items')->whereKey($request->id)->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'cancelled' || $locked->status === 'fulfilled') {
                throw new ApiException('Stock Request tidak dapat diusulkan lagi.', 422);
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

            return $proposal->fresh()->load(['items.requestItem.product', 'items.requestItem.variation', 'items.requestItem.orderItem', 'requester']);
        });
    }

    /** Whole-proposal approval (legacy entry point): approves every item that is still pending. */
    public function approve(User $actor, StockRequestProposal $proposal): StockRequestProposal
    {
        return $this->approveItems($actor, $proposal, null);
    }

    /** Admin approves ONE product line of a proposal; the other lines are untouched. */
    public function approveItem(User $actor, StockRequestProposal $proposal, StockRequestProposalItem $item): StockRequestProposal
    {
        return $this->approveItems($actor, $proposal, [$item->id]);
    }

    /**
     * Approves the selected proposal items (null = every still-pending item) in ONE transaction.
     * Per-item decision (production UAT): only the selected items execute; approval moves
     * Transit -> Shipping and releases the reservation exactly as before. Idempotent per item.
     *
     * Lock order (unchanged): Proposal -> Stock Request -> per sorted target (Transit -> Shipping -> Agent row).
     *
     * @param  list<int>|null  $itemIds
     */
    private function approveItems(User $actor, StockRequestProposal $proposal, ?array $itemIds): StockRequestProposal
    {
        if (! $actor->isRole('admin')) {
            throw new ApiException('Hanya Admin yang dapat menyetujui proposal.', 403);
        }

        return DB::transaction(function () use ($actor, $proposal, $itemIds) {
            $locked = StockRequestProposal::withoutGlobalScopes()->with(['items.requestItem'])->whereKey($proposal->id)->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrFail();

            $selected = $this->selectItems($locked, $itemIds);
            if ($itemIds === null && $selected->isEmpty()) {
                if ($locked->status === 'approved') {
                    return $this->loaded($locked);
                }
                throw new ApiException('Proposal sudah diproses.', 422);
            }
            if ($itemIds !== null) {
                foreach ($selected as $row) {
                    if ($row->decision_status === 'rejected') {
                        throw new ApiException('Item proposal sudah ditolak.', 422);
                    }
                }
                $selected = $selected->where('decision_status', 'pending')->values();
                if ($selected->isEmpty()) {
                    return $this->loaded($locked); // already approved: nothing moves twice
                }
            }

            $request = StockRequest::withoutGlobalScopes()->with('items')->whereKey($locked->stock_request_id)->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrFail();
            if ($request->status === 'cancelled' || $request->status === 'fulfilled') {
                throw new ApiException('Stock Request tidak dapat dipenuhi lagi.', 422);
            }

            $lines = [];
            foreach ($selected as $proposalItem) {
                $item = $request->items->firstWhere('id', $proposalItem->stock_request_item_id);
                if (! $item || $item->stock_request_id !== $request->id || $proposalItem->quantity <= 0 || $proposalItem->quantity > $item->remaining_qty) {
                    throw new ApiException('Jumlah proposal melebihi sisa request.', 422);
                }
                $key = $item->product_variation_id ? 'v:'.$item->product_variation_id : 'p:'.$item->product_id;
                $lines[] = [$proposalItem, $item, $key];
            }
            // Two selected lines for the same request item must not jointly exceed what is left.
            $perItem = [];
            foreach ($lines as [$proposalItem, $item]) {
                $perItem[$item->id] = ($perItem[$item->id] ?? 0) + $proposalItem->quantity;
                if ($perItem[$item->id] > $item->remaining_qty) {
                    throw new ApiException('Jumlah proposal melebihi sisa request.', 422);
                }
            }
            $targetKeys = array_values(array_unique(array_map(fn ($line) => $line[2], $lines)));
            sort($targetKeys);
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

            $operationKey = $itemIds === null ? 'proposal-'.$locked->id : 'proposal-item-'.implode('-', $itemIds);
            $operation = StockRequestFulfillment::create(['stock_request_id' => $request->id, 'idempotency_key' => $operationKey, 'fulfilled_by' => $actor->id]);
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
            // Same locking-read reconciliation as StockRequestService (REPEATABLE READ snapshot safety).
            $lockedItems = $request->items()->lockForUpdate()->get();
            $remaining = (int) $lockedItems->sum('remaining_qty');
            $fulfilled = (int) $lockedItems->sum('fulfilled_qty');
            $request->update(['status' => $remaining === 0 ? 'fulfilled' : ($fulfilled > 0 ? 'partial' : 'pending'), 'fulfilled_at' => $remaining === 0 ? now() : null]);

            foreach ($selected as $proposalItem) {
                $proposalItem->update(['decision_status' => 'approved', 'decided_by' => $actor->id, 'decided_at' => now()]);
            }
            $this->syncHeader($locked, $actor);

            return $this->loaded($locked);
        });
    }

    /** Whole-proposal rejection (legacy entry point): rejects every item that is still pending. */
    public function reject(User $actor, StockRequestProposal $proposal, string $reason): StockRequestProposal
    {
        return $this->rejectItems($actor, $proposal, null, $reason);
    }

    /** Admin rejects ONE proposed fulfilment line. The order demand (requested/fulfilled/remaining) is untouched. */
    public function rejectItem(User $actor, StockRequestProposal $proposal, StockRequestProposalItem $item, string $reason): StockRequestProposal
    {
        return $this->rejectItems($actor, $proposal, [$item->id], $reason);
    }

    /**
     * Rejecting a PROPOSED FULFILMENT is not cancelling ORDER DEMAND: no StockRequestItem quantity
     * changes, so Gudang can make a new valid proposal for the same remaining demand.
     *
     * @param  list<int>|null  $itemIds
     */
    private function rejectItems(User $actor, StockRequestProposal $proposal, ?array $itemIds, string $reason): StockRequestProposal
    {
        if (! $actor->isRole('admin')) {
            throw new ApiException('Hanya Admin yang dapat menolak proposal.', 403);
        }

        return DB::transaction(function () use ($actor, $proposal, $itemIds, $reason) {
            $locked = StockRequestProposal::withoutGlobalScopes()->with('items')->whereKey($proposal->id)->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrFail();

            $selected = $this->selectItems($locked, $itemIds);
            if ($itemIds === null && $selected->isEmpty()) {
                if ($locked->status === 'rejected') {
                    return $this->loaded($locked);
                }
                throw new ApiException('Proposal sudah diproses.', 422);
            }
            if ($itemIds !== null) {
                foreach ($selected as $row) {
                    if ($row->decision_status === 'approved') {
                        throw new ApiException('Item proposal sudah disetujui.', 422);
                    }
                }
                $selected = $selected->where('decision_status', 'pending')->values();
                if ($selected->isEmpty()) {
                    return $this->loaded($locked); // already rejected
                }
            }

            foreach ($selected as $proposalItem) {
                $proposalItem->update(['decision_status' => 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_reason' => $reason]);
            }
            $this->syncHeader($locked, $actor, $reason);

            return $this->loaded($locked);
        });
    }

    /**
     * Items to act on: every still-pending item (whole-proposal action) or the explicitly requested ones
     * (which must belong to this proposal).
     *
     * @param  list<int>|null  $itemIds
     */
    private function selectItems(StockRequestProposal $locked, ?array $itemIds)
    {
        if ($itemIds === null) {
            return $locked->items->where('decision_status', 'pending')->values();
        }
        $rows = $locked->items->whereIn('id', $itemIds)->values();
        if ($rows->count() !== count(array_unique($itemIds))) {
            throw new ApiException('Item proposal tidak ditemukan.', 404);
        }

        return $rows;
    }

    /** Header status is DERIVED from the item decisions — never an independent truth. */
    private function syncHeader(StockRequestProposal $locked, User $actor, ?string $reason = null): void
    {
        $locked->load('items');
        $states = $locked->items->pluck('decision_status')->unique();
        $status = $states->count() === 1 ? (string) $states->first() : 'partial';

        $changes = ['status' => $status];
        if ($status === 'approved') {
            $changes += ['approved_by' => $actor->id, 'approved_at' => now()];
        } elseif ($status === 'rejected') {
            $changes += ['rejected_by' => $actor->id, 'rejected_at' => now(), 'rejection_reason' => $reason];
        }
        $locked->update($changes);
    }

    private function loaded(StockRequestProposal $locked): StockRequestProposal
    {
        return $locked->fresh()->load(['items.requestItem.product', 'items.requestItem.variation', 'items.requestItem.orderItem', 'requester']);
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
