<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Models\OrderItem;

/**
 * Canonical order-total recalculation — the ONLY place `Order.subtotal_amount`/
 * `total_amount` are ever recomputed after order creation (creation itself
 * uses the same formula inline in OrderService::createOrder, subtotal +
 * shipping + admin - discount). Reused by OrderFulfillmentService whenever an
 * item's fulfilled_quantity changes, so every caller gets one authoritative
 * total instead of each re-deriving its own.
 *
 * Per-item current value is `unit_price_snapshot * fulfilled_quantity` — NOT
 * `subtotal_snapshot`, which is deliberately never rewritten by fulfillment
 * adjustments (it stays the historical as-ordered value). fulfilled_quantity
 * is exactly "how much of this line is still active/billed" (a cancelled-to-
 * zero item naturally contributes 0). shipping_fee_amount/admin_fee_amount/
 * discount_amount are left untouched — this task deliberately does not
 * re-trigger a shipping quote or discount re-evaluation on item changes.
 */
class OrderTotalCalculator
{
    public function recalculate(Order $order): Order
    {
        $subtotal = round(
            (float) OrderItem::query()->where('order_id', $order->id)
                ->selectRaw('COALESCE(SUM(unit_price_snapshot * fulfilled_quantity), 0) as total')
                ->value('total'),
            2
        );

        $total = round(
            $subtotal - (float) $order->discount_amount + (float) $order->shipping_fee_amount + (float) $order->admin_fee_amount,
            2
        );

        $order->update(['subtotal_amount' => $subtotal, 'total_amount' => $total]);

        return $order->fresh();
    }
}
