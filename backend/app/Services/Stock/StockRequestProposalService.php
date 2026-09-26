<?php

namespace App\Services\Stock;

use App\Exceptions\ApiException;
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
                $key = $item->product_variation_id ? 'v:'.$item->product_variation_id : 'p:'.$item->product_id;
                $lines[] = [$proposalItem, $item, $key];
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
            $remaining = $request->items()->sum('remaining_qty');
            $fulfilled = $request->items()->sum('fulfilled_qty');
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
