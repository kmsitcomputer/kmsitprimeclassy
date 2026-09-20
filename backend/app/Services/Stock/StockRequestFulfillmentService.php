<?php

namespace App\Services\Stock;

use App\Exceptions\ApiException;
use App\Models\ProductStock;
use App\Models\ProductVariationStock;
use App\Models\StockMovement;
use App\Models\StockRequest;
use App\Models\StockRequestFulfillment;
use App\Models\StockRequestItem;
use App\Models\User;
use App\Models\WarehouseStock;
use Illuminate\Support\Facades\DB;

class StockRequestFulfillmentService
{
    public function fulfill(User $actor, StockRequest $request, array $quantities, string $idempotencyKey): StockRequest
    {
        if (! $actor->isRole('gudang') || $request->agent_id !== $actor->agent_id) {
            throw new ApiException('Hanya Gudang pada network yang sama.', 403);
        }

        return DB::transaction(function () use ($actor, $request, $quantities, $idempotencyKey) {
            $locked = StockRequest::withoutGlobalScopes()->with('items')->whereKey($request->id)->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrFail();
            if (StockRequestFulfillment::where('stock_request_id', $locked->id)->where('idempotency_key', $idempotencyKey)->exists()) {
                return $locked->load('items');
            }
            if ($locked->status === 'cancelled' || $locked->status === 'fulfilled') {
                throw new ApiException('Stock Request tidak dapat dipenuhi lagi.', 422);
            }
            $operation = StockRequestFulfillment::create(['stock_request_id' => $locked->id, 'idempotency_key' => $idempotencyKey, 'fulfilled_by' => $actor->id]);
            $items = $locked->items->keyBy('id');
            foreach ($quantities as $input) {
                $item = $items->get((int) ($input['item_id'] ?? 0));
                $qty = (int) ($input['quantity'] ?? 0);
                if (! $item || $qty <= 0 || $qty > $item->remaining_qty) {
                    throw new ApiException('Jumlah fulfillment melebihi sisa request.', 422);
                }
                $this->movePhysicalAndReleaseReservation($locked, $item, $qty, $actor->id, $operation->id);
                $item->update(['fulfilled_qty' => $item->fulfilled_qty + $qty, 'remaining_qty' => $item->remaining_qty - $qty]);
            }
            $remaining = $locked->items()->sum('remaining_qty');
            $fulfilled = $locked->items()->sum('fulfilled_qty');
            $locked->update(['status' => $remaining === 0 ? 'fulfilled' : ($fulfilled > 0 ? 'partial' : 'pending'), 'fulfilled_at' => $remaining === 0 ? now() : null]);

            return $locked->fresh()->load('items');
        });
    }

    private function movePhysicalAndReleaseReservation(StockRequest $request, StockRequestItem $item, int $qty, int $actorId, int $operationId): void
    {
        $source = WarehouseStock::withoutGlobalScopes()->where('agent_id', $request->agent_id)->where('product_id', $item->product_id)->whereNull('product_variation_id')->where('stock_type', 'transit')->whereNull('sub_location_id')->lockForUpdate()->first();
        $destination = WarehouseStock::withoutGlobalScopes()->where('agent_id', $request->agent_id)->where('product_id', $item->product_id)->whereNull('product_variation_id')->where('stock_type', 'shipping')->whereNull('sub_location_id')->lockForUpdate()->first();
        if ($item->product_variation_id) {
            $source = WarehouseStock::withoutGlobalScopes()->where('agent_id', $request->agent_id)->where('product_variation_id', $item->product_variation_id)->whereNull('product_id')->where('stock_type', 'transit')->whereNull('sub_location_id')->lockForUpdate()->first();
            $destination = WarehouseStock::withoutGlobalScopes()->where('agent_id', $request->agent_id)->where('product_variation_id', $item->product_variation_id)->whereNull('product_id')->where('stock_type', 'shipping')->whereNull('sub_location_id')->lockForUpdate()->first();
        }
        if (! $source || $source->quantity < $qty) {
            throw new ApiException('Stok fisik Transit tidak mencukupi.', 422);
        }
        $reservation = $item->product_variation_id
            ? ProductVariationStock::withoutGlobalScopes()->where('agent_id', $request->agent_id)->where('product_variation_id', $item->product_variation_id)->lockForUpdate()->first()
            : ProductStock::withoutGlobalScopes()->where('agent_id', $request->agent_id)->where('product_id', $item->product_id)->lockForUpdate()->first();
        if (! $reservation || $reservation->quantity_reserved < $qty) {
            throw new ApiException('Reservasi tidak mencukupi untuk fulfillment.', 422);
        }
        $source->decrement('quantity', $qty);
        $destination ??= WarehouseStock::create(['agent_id' => $request->agent_id, 'product_id' => $item->product_id, 'product_variation_id' => $item->product_variation_id, 'stock_type' => 'shipping', 'quantity' => 0]);
        $destination->increment('quantity', $qty);
        $reservation->decrement('quantity_reserved', $qty);
        $common = ['agent_id' => $request->agent_id, 'product_id' => $item->product_id, 'product_variation_id' => $item->product_variation_id, 'type' => 'fulfillment', 'reference_type' => StockRequest::class, 'reference_id' => $request->id, 'created_by' => $actorId, 'note' => 'fulfillment_operation='.$operationId];
        StockMovement::create($common + ['stock_type' => 'transit', 'quantity' => -$qty]);
        StockMovement::create($common + ['stock_type' => 'shipping', 'quantity' => $qty]);
    }
}
