<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
use App\Models\InventoryCancellationReversal;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockMovement;
use App\Models\StockRequest;
use App\Models\WarehouseStock;
use App\Models\User;
use App\Services\Stock\StockService;
use App\Services\Stock\SubStockService;

class InventoryCancellationService
{
    public function __construct(private readonly StockService $stockService) {}

    public function reverseOrder(Order $order, int $actorId): void
    {
        $request = StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->lockForUpdate()->first();
        $this->lockAgentCapacityForReversal($order);
        foreach ($order->items as $item) {
            if (! $item->canTransitionTo('dibatalkan')) {
                continue;
            }
            $this->reverseItem($order, $item, $actorId, $request);
        }
        if ($request && $request->status !== 'cancelled') {
            $request->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        }
    }

    /**
     * Canonical Agent capacity prelock for a reversal. Every non-Sub item's release touches the Agent
     * commitment row, and a fulfilled item also touches Transit/Shipping, so this acquires the Agent
     * capacity locks in the SAME canonical target order checkout and Transit -> Sub replenishment use
     * — BEFORE the per-item reversal loop runs. The loop still executes in its original item order.
     *
     * Sub-sourced items reserve nothing in Agent stock, so they are excluded (they release in the Sub
     * ledger). Locking the commitment and Transit/Plan rows here also fixes the ordering relative to
     * reverseShippingToTransit(): the Transit row is already held, so the later Shipping lock can never
     * invert against a concurrent Transit -> Sub execution.
     */
    private function lockAgentCapacityForReversal(Order $order): void
    {
        $targets = [];
        foreach ($order->items as $item) {
            if ($item->isSubSourced()) {
                continue;
            }
            $targets[] = [
                'product_id' => $item->product_variation_id ? null : $item->product_id,
                'product_variation_id' => $item->product_variation_id,
            ];
        }
        if ($targets !== []) {
            $this->stockService->lockReservationTargets($order->agent_id, $targets);
        }
    }

    private function reverseItem(Order $order, OrderItem $item, int $actorId, ?StockRequest $request): void
    {
        if (InventoryCancellationReversal::where('order_item_id', $item->id)->lockForUpdate()->exists()) {
            return;
        }
        if ($item->isSubSourced()) {
            // Sub-sourced: release the Sub reservation (physical Sub stock unchanged); nothing to do in Agent stock.
            app(SubStockService::class)->release($item, User::query()->find($actorId), 'order_cancellation');
            InventoryCancellationReversal::create(['agent_id' => $order->agent_id, 'order_id' => $order->id, 'order_item_id' => $item->id, 'stock_request_id' => null, 'fulfilled_quantity' => 0, 'released_quantity' => 0, 'processed_by' => $actorId]);

            return;
        }
        $requestItem = $request?->items()->where('order_item_id', $item->id)->lockForUpdate()->first();
        $fulfilled = $requestItem ? (int) $requestItem->fulfilled_qty : 0;
        $remaining = $requestItem
            ? (int) $requestItem->remaining_qty
            : max(0, $item->fulfilled_quantity - $item->cancelled_quantity - $item->returned_quantity);
        $released = $remaining;
        if ($fulfilled > 0) {
            $this->reverseShippingToTransit($order, $item, $fulfilled, $actorId);
        }
        if ($released > 0) {
            if ($item->product_variation_id) {
                $this->stockService->releaseVariation($order->agent_id, $item->product_variation_id, $released, 'order_cancellation', $order->id, $actorId);
            } else {
                $this->stockService->releaseProduct($order->agent_id, $item->product_id, $released, 'order_cancellation', $order->id, $actorId);
            }
        }
        InventoryCancellationReversal::create(['agent_id' => $order->agent_id, 'order_id' => $order->id, 'order_item_id' => $item->id, 'stock_request_id' => $requestItem?->stock_request_id, 'fulfilled_quantity' => $fulfilled, 'released_quantity' => $released, 'processed_by' => $actorId]);
    }

    private function reverseShippingToTransit(Order $order, OrderItem $item, int $qty, int $actorId): void
    {
        $transit = $this->warehouse($order->agent_id, $item, 'transit')->lockForUpdate()->first();
        $shipping = $this->warehouse($order->agent_id, $item, 'shipping')->lockForUpdate()->first();
        if (! $shipping || $shipping->quantity < $qty) {
            throw new ApiException('Stok Shipping sudah tidak tersedia untuk pembatalan; gunakan return.', 422);
        }
        $shipping->decrement('quantity', $qty);
        $transit ??= WarehouseStock::create(['agent_id' => $order->agent_id, 'product_id' => $item->product_id, 'product_variation_id' => $item->product_variation_id, 'stock_type' => 'transit', 'quantity' => 0]);
        $transit->increment('quantity', $qty);
        $common = ['agent_id' => $order->agent_id, 'product_id' => $item->product_id, 'product_variation_id' => $item->product_variation_id, 'type' => 'cancellation_release', 'reference_type' => Order::class, 'reference_id' => $order->id, 'created_by' => $actorId, 'note' => 'order_item='.$item->id];
        StockMovement::create($common + ['stock_type' => 'shipping', 'quantity' => -$qty]);
        StockMovement::create($common + ['stock_type' => 'transit', 'quantity' => $qty]);
    }

    private function warehouse(int $agentId, OrderItem $item, string $type)
    {
        return WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('stock_type', $type)->whereNull('sub_location_id')->when($item->product_id, fn ($q) => $q->where('product_id', $item->product_id)->whereNull('product_variation_id'))->when($item->product_variation_id, fn ($q) => $q->where('product_variation_id', $item->product_variation_id)->whereNull('product_id'));
    }
}
