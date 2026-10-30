<?php

namespace App\Services\Report;

use App\Models\Order;
use App\Models\OrderInitialReportItem;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderInitialSnapshotService
{
    /**
     * Must run inside the same transaction as the original checkout.
     * Never call this for subsequent order-line additions or legacy orders.
     */
    public function capture(Order $order): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Initial order snapshot requires an active database transaction.');
        }

        $items = OrderItem::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            throw new RuntimeException('Cannot snapshot an order without items.');
        }

        // Fail closed: partial snapshots must not be silently accepted.
        if (OrderInitialReportItem::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->exists()) {
            throw new RuntimeException('Initial order snapshot already exists.');
        }

        $products = Product::with('category')
            ->whereIn('id', $items->pluck('product_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $order->refresh();
        $order->loadMissing('paymentMethod');
        $paymentMethod = $order->paymentMethod;

        // "Sisa" is a derived value: it must never read orders.remaining_amount
        // (that column stays 0 for every non-DP order that has not been verified
        // yet). The canonical formula is max(0, total - paid).
        $invoiceTotal = (float) $order->total_amount;
        $paidAmount = (float) ($order->paid_amount ?? 0);
        $remainingAmount = max(0.0, round($invoiceTotal - $paidAmount, 2));

        foreach ($items as $position => $item) {
            $product = $products->get($item->product_id);

            OrderInitialReportItem::create([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'order_no' => $order->order_no,
                'order_created_at' => $order->created_at,
                'agent_id' => $order->agent_id,
                'korsal_id' => $order->korsal_id,
                'sales_id' => $order->sales_id,
                'konsumen_id' => $order->konsumen_id,
                'product_id' => $item->product_id,
                'product_variation_id' => $item->product_variation_id,
                'sku_snapshot' => $item->sku_snapshot,
                'product_name_snapshot' => $item->product_name_snapshot,
                'variation_label_snapshot' => $item->variation_label_snapshot,
                'category_name_snapshot' => $product?->category?->name,
                'initial_quantity' => $item->original_quantity,
                'unit_price_snapshot' => $item->unit_price_snapshot,
                'item_subtotal_snapshot' => $item->subtotal_snapshot,
                'recipient_name_snapshot' => $order->recipient_name_snapshot,
                'recipient_phone_snapshot' => $order->recipient_phone_snapshot,
                'address_snapshot' => $order->address_snapshot,
                'village_snapshot' => $order->village_snapshot,
                'district_snapshot' => $order->district_snapshot,
                'regency_snapshot' => $order->regency_snapshot,
                'province_snapshot' => $order->province_snapshot,
                'invoice_subtotal_snapshot' => $order->subtotal_amount,
                'invoice_discount_snapshot' => $order->discount_amount,
                'invoice_shipping_snapshot' => $order->shipping_fee_amount,
                'invoice_admin_fee_snapshot' => $order->admin_fee_amount,
                'invoice_total_snapshot' => $order->total_amount,
                'initial_paid_snapshot' => $paidAmount,
                'initial_remaining_snapshot' => $remainingAmount,
                'initial_payment_status' => $order->payment_status,
                'payment_method_code_snapshot' => $paymentMethod?->code,
                'payment_method_name_snapshot' => $paymentMethod?->name,
                'item_position' => $position + 1,
            ]);
        }
    }
}
