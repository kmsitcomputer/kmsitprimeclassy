<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
use App\Models\DeliveryRefusal;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductStock;
use App\Models\ProductVariationStock;
use App\Models\Shipment;
use App\Models\StockMovement;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * REM-003 — delivery refusal with reverse custody.
 *
 *   Kurir records refusal (item stays `dikirim`, in the courier's hands)
 *   → Kurir confirms hand-back → Koordinator confirms physical receipt   (two-party custody)
 *   → Koordinator decides: REDELIVERY (item returns to `diproses` on a fresh pending shipment, the
 *     accepted stock stays put) or RETURN TO GUDANG (accepted units go Shipping → Transit, their
 *     reservation and outstanding demand are restored, the line waits for a new fulfilment).
 *
 * Money is never touched here: cancelling/refunding a refused line stays the Admin's existing
 * adjustment path. Order → Shipment → item → refusal → Stock Request → inventory lock order.
 */
class DeliveryRefusalService
{
    public function __construct(
        private readonly CourierService $courierService,
        private readonly OrderFulfillmentService $fulfillmentService,
        private readonly InventoryCancellationService $inventoryReversal,
    ) {}

    /** The courier executing this shipment (recorded executor) — the only actor who may refuse/hand back. */
    private function assertExecutor(Shipment $shipment, User $actor): void
    {
        $isExecutor = $shipment->isSelfDelivery()
            ? $shipment->isSelfPickupAuthorizedFor($actor)
            : ($shipment->courier?->user_id !== null && (int) $shipment->courier->user_id === (int) $actor->id);
        if (! $isExecutor || ($actor->status ?? null) !== 'active') {
            throw new ApiException(__('messages.courier.not_your_delivery'), 403);
        }
    }

    public function record(Shipment $shipment, OrderItem $item, User $actor, string $reason, ?int $quantity = null): DeliveryRefusal
    {
        return DB::transaction(function () use ($shipment, $item, $actor, $reason, $quantity) {
            $order = Order::query()->whereKey($shipment->order_id)->lockForUpdate()->firstOrFail();
            $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $item = OrderItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ((int) $item->shipment_id !== (int) $shipment->id || (int) $item->order_id !== (int) $order->id) {
                throw new ApiException(__('messages.system.unauthorized_action'), 404);
            }
            // SKS isolation: a self_sub (Sub-owned) delivery keeps its own workflow — refusal/reverse custody via the
            // Koordinator is a Kurir Toko concept and is never available there.
            if ($shipment->isSelfDelivery() || $item->isSubSourced()) {
                throw new ApiException(__('messages.refusal.not_applicable'), 422);
            }
            $this->assertExecutor($shipment->load('courier'), $actor);
            if ($item->status !== 'dikirim') {
                throw new ApiException(__('messages.refusal.not_in_delivery'), 422);
            }
            $open = DeliveryRefusal::withoutGlobalScopes()->where('order_item_id', $item->id)->whereIn('status', DeliveryRefusal::OPEN_STATUSES)->lockForUpdate()->exists();
            if ($open) {
                throw new ApiException(__('messages.refusal.already_open'), 422);
            }

            // Partial refusal: the customer keeps part of the line. The refused units are carved off into
            // their own item on the SAME shipment (still `dikirim`, in the courier's hands) so delivered and
            // refused quantities never overlap; the remainder stays on the original item to be delivered.
            $refusedQty = (int) $item->fulfilled_quantity;
            if ($quantity !== null) {
                if ($quantity < 1 || $quantity > $refusedQty) {
                    throw new ApiException(__('messages.fulfillment.invalid_quantity'), 422);
                }
                if ($quantity < $refusedQty) {
                    if ($item->isSubSourced()) {
                        throw new ApiException(__('messages.order.line_addition_sub_not_supported'), 422);
                    }
                    $item = $this->splitOutRefusedUnits($order, $item, $quantity, $actor);
                    $refusedQty = $quantity;
                }
            }

            $refusal = DeliveryRefusal::withoutGlobalScopes()->create([
                'agent_id' => $order->agent_id, 'order_id' => $order->id, 'order_item_id' => $item->id, 'shipment_id' => $shipment->id,
                'quantity' => $refusedQty, 'reason' => $reason, 'status' => 'with_courier',
                'recorded_by' => $actor->id, 'recorded_at' => now(),
            ]);
            ActivityLogger::log($actor->id, $refusal, 'delivery_refusal.recorded', $reason, ['order_id' => $order->id, 'order_item_id' => $item->id, 'quantity' => $refusal->quantity]);

            return $refusal;
        });
    }

    /**
     * Carve `$qty` refused units (already dispatched) off `$item` into a sibling item on the same shipment.
     * Money/fees are apportioned like the canonical item split; the accepted warehouse demand follows the
     * units (requested and fulfilled move together, nothing becomes outstanding); the frozen financial
     * snapshot is NOT rewritten (the child simply has no frozen units, exactly like any split child).
     */
    private function splitOutRefusedUnits(Order $order, OrderItem $item, int $qty, User $actor): OrderItem
    {
        $request = StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->lockForUpdate()->first();
        $demand = $request ? StockRequestItem::query()->where('stock_request_id', $request->id)->where('order_item_id', $item->id)->lockForUpdate()->first() : null;
        if ($demand && ((int) $demand->fulfilled_qty < $qty || (int) $demand->requested_qty < $qty)) {
            throw new ApiException(__('messages.refusal.stock_state_mismatch'), 422);
        }

        $perUnitFee = $item->fulfilled_quantity > 0 ? (float) $item->courier_fee_amount / $item->fulfilled_quantity : 0.0;
        $movedFee = round($perUnitFee * $qty, 2);
        $movedSubtotal = round((float) $item->unit_price_snapshot * $qty, 2);

        $child = OrderItem::create([
            'order_id' => $item->order_id, 'shipment_id' => $item->shipment_id, 'split_from_order_item_id' => $item->id,
            'product_id' => $item->product_id, 'product_variation_id' => $item->product_variation_id,
            'stock_source' => $item->stock_source, 'sub_location_id' => $item->sub_location_id,
            'product_name_snapshot' => $item->product_name_snapshot, 'variation_label_snapshot' => $item->variation_label_snapshot, 'sku_snapshot' => $item->sku_snapshot,
            'unit_price_snapshot' => $item->unit_price_snapshot, 'agent_fee_amount' => 0, 'sales_fee_amount' => 0,
            'courier_fee_amount' => $movedFee, 'subtotal_snapshot' => $movedSubtotal,
            'original_quantity' => $qty, 'fulfilled_quantity' => $qty,
            'requested_delivery_date' => $item->requested_delivery_date, 'status' => $item->status, 'handed_over_at' => $item->handed_over_at,
        ]);
        $item->update([
            'original_quantity' => $item->original_quantity - $qty, 'fulfilled_quantity' => $item->fulfilled_quantity - $qty,
            'subtotal_snapshot' => (float) $item->subtotal_snapshot - $movedSubtotal,
            'courier_fee_amount' => round((float) $item->courier_fee_amount - $movedFee, 2),
        ]);
        if ($demand) {
            $demand->update(['requested_qty' => $demand->requested_qty - $qty, 'fulfilled_qty' => $demand->fulfilled_qty - $qty]);
            StockRequestItem::create([
                'stock_request_id' => $demand->stock_request_id, 'order_item_id' => $child->id,
                'product_id' => $child->product_id, 'product_variation_id' => $child->product_variation_id, 'sku_snapshot' => $child->sku_snapshot,
                'requested_qty' => $qty, 'fulfilled_qty' => $qty, 'remaining_qty' => 0,
            ]);
        }
        ActivityLogger::log($actor->id, $child, 'order_item.split_for_refusal', null, ['order_id' => $order->id, 'from_order_item_id' => $item->id, 'quantity' => $qty]);

        return $child;
    }

    public function handBack(DeliveryRefusal $refusal, User $actor): DeliveryRefusal
    {
        return DB::transaction(function () use ($refusal, $actor) {
            $refusal = DeliveryRefusal::withoutGlobalScopes()->whereKey($refusal->id)->lockForUpdate()->firstOrFail();
            $this->assertExecutor(Shipment::query()->with('courier')->findOrFail($refusal->shipment_id), $actor);
            if ($refusal->status === 'handed_back') {
                return $refusal; // idempotent replay
            }
            if ($refusal->status !== 'with_courier') {
                throw new ApiException(__('messages.refusal.invalid_state'), 422);
            }
            $refusal->update(['status' => 'handed_back', 'handed_back_by' => $actor->id, 'handed_back_at' => now()]);
            ActivityLogger::log($actor->id, $refusal, 'delivery_refusal.handed_back', null, ['order_item_id' => $refusal->order_item_id]);

            return $refusal->fresh();
        });
    }

    public function receive(DeliveryRefusal $refusal, User $actor): DeliveryRefusal
    {
        $this->assertCoordinator($refusal, $actor);

        return DB::transaction(function () use ($refusal, $actor) {
            $refusal = DeliveryRefusal::withoutGlobalScopes()->whereKey($refusal->id)->lockForUpdate()->firstOrFail();
            if ($refusal->status === 'received') {
                return $refusal;
            }
            if ($refusal->status !== 'handed_back') {
                throw new ApiException(__('messages.refusal.invalid_state'), 422);
            }
            $refusal->update(['status' => 'received', 'received_by' => $actor->id, 'received_at' => now()]);
            ActivityLogger::log($actor->id, $refusal, 'delivery_refusal.received', null, ['order_item_id' => $refusal->order_item_id]);

            return $refusal->fresh();
        });
    }

    public function dispose(DeliveryRefusal $refusal, User $actor, string $action, ?string $note): DeliveryRefusal
    {
        $this->assertCoordinator($refusal, $actor);
        if (! in_array($action, ['redelivery', 'return_to_warehouse'], true)) {
            throw new ApiException(__('messages.refusal.invalid_state'), 422);
        }

        return DB::transaction(function () use ($refusal, $actor, $action, $note) {
            $order = Order::query()->whereKey($refusal->order_id)->lockForUpdate()->firstOrFail();
            $shipment = Shipment::query()->whereKey($refusal->shipment_id)->lockForUpdate()->firstOrFail();
            $item = OrderItem::query()->whereKey($refusal->order_item_id)->lockForUpdate()->firstOrFail();
            $refusal = DeliveryRefusal::withoutGlobalScopes()->whereKey($refusal->id)->lockForUpdate()->firstOrFail();

            $final = $action === 'redelivery' ? 'redelivery' : 'returned_to_warehouse';
            if ($refusal->status === $final) {
                return $refusal; // idempotent replay
            }
            if ($refusal->status !== 'received') {
                throw new ApiException(__('messages.refusal.invalid_state'), 422);
            }

            if ($action === 'return_to_warehouse' && ! $item->isSubSourced()) {
                $this->returnToWarehouse($order, $item, (int) $refusal->quantity, $actor);
            }

            // The goods are no longer in transit: the line goes back to `diproses` on a fresh pending
            // shipment (to be re-assigned by the Koordinator, or re-fulfilled first after a Gudang return).
            $item->update(['status' => 'diproses', 'handed_over_at' => null]);
            $this->fulfillmentService->assignFreshShipment($item, $order, $actor, 'shipment.refusal_'.$final);
            $this->courierService->syncShipmentAggregate($shipment->fresh());

            $refusal->update(['status' => $final, 'disposition_by' => $actor->id, 'disposition_at' => now(), 'disposition_note' => $note]);
            ActivityLogger::log($actor->id, $refusal, 'delivery_refusal.'.$final, $note, ['order_item_id' => $item->id, 'quantity' => $refusal->quantity]);

            return $refusal->fresh();
        });
    }

    /** Accepted units: Shipping → Transit, reservation + outstanding demand restored (they are owed again). */
    private function returnToWarehouse(Order $order, OrderItem $item, int $qty, User $actor): void
    {
        $request = StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->lockForUpdate()->first();
        $demand = $request ? StockRequestItem::query()->where('stock_request_id', $request->id)->where('order_item_id', $item->id)->lockForUpdate()->first() : null;
        if (! $demand || (int) $demand->fulfilled_qty < $qty) {
            throw new ApiException(__('messages.refusal.stock_state_mismatch'), 422);
        }

        $this->inventoryReversal->reverseAcceptedUnits($order, $item, $qty, $actor->id);

        $reservation = $item->product_variation_id
            ? ProductVariationStock::withoutGlobalScopes()->where('agent_id', $order->agent_id)->where('product_variation_id', $item->product_variation_id)->lockForUpdate()->first()
            : ProductStock::withoutGlobalScopes()->where('agent_id', $order->agent_id)->where('product_id', $item->product_id)->lockForUpdate()->first();
        if (! $reservation) {
            throw new ApiException(__('messages.refusal.stock_state_mismatch'), 422);
        }
        $reservation->increment('quantity_reserved', $qty);
        StockMovement::create([
            'agent_id' => $order->agent_id, 'product_id' => $item->product_variation_id ? null : $item->product_id, 'product_variation_id' => $item->product_variation_id,
            'type' => 'reserve', 'quantity' => $qty, 'reference_type' => Order::class, 'reference_id' => $order->id,
            'created_by' => $actor->id, 'note' => 'delivery_refusal_return;order_item='.$item->id,
        ]);

        $demand->update(['fulfilled_qty' => $demand->fulfilled_qty - $qty, 'remaining_qty' => $demand->remaining_qty + $qty]);
        $items = StockRequestItem::query()->where('stock_request_id', $request->id)->lockForUpdate()->get();
        $remaining = (int) $items->sum('remaining_qty');
        $fulfilled = (int) $items->sum('fulfilled_qty');
        $request->update(['status' => $remaining === 0 ? 'fulfilled' : ($fulfilled > 0 ? 'partial' : 'pending'), 'fulfilled_at' => $remaining === 0 ? ($request->fulfilled_at ?? now()) : null]);
    }

    private function assertCoordinator(DeliveryRefusal $refusal, User $actor): void
    {
        if (! $actor->isRole('koordinator-kurir') || (int) $actor->agent_id !== (int) $refusal->agent_id) {
            throw new ApiException(__('messages.courier.coordinator_only'), 403);
        }
    }

    /** True while an item's refusal is unresolved — it must never be marked delivered. */
    public static function hasOpenRefusal(int $orderItemId): bool
    {
        return DeliveryRefusal::withoutGlobalScopes()->where('order_item_id', $orderItemId)->whereIn('status', DeliveryRefusal::OPEN_STATUSES)->exists();
    }
}
