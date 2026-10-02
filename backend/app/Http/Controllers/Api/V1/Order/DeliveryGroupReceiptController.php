<?php

namespace App\Http\Controllers\Api\V1\Order;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Logging\ActivityLogger;
use App\Services\Order\DeliveryGroupInvoiceService;
use Illuminate\Http\Request;

class DeliveryGroupReceiptController extends Controller
{
    public function __invoke(Request $request, Order $order, string $deliveryDate, DeliveryGroupInvoiceService $groups)
    {
        $this->authorize('view', $order);
        $group = $groups->forDate($order, $deliveryDate);
        ActivityLogger::log($request->user()->id, $order, 'delivery_group.receipt_printed', null, [
            'delivery_date' => $deliveryDate,
            'shipment_ids' => $group['shipment_ids'],
            'actor_role' => $request->user()->role?->slug,
        ]);

        return $this->ok([
            'order_no' => $order->order_no,
            'delivery_date' => $deliveryDate,
            'recipient_name' => $order->recipient_name_snapshot,
            'recipient_phone' => $order->recipient_phone_snapshot,
            'address_line' => $order->address_snapshot,
            'items' => array_map(fn ($item) => [
                'sku' => $item['sku'],
                'product_name' => $item['product_name'],
                'variation_label' => $item['variation_label'],
                'quantity' => $item['quantity'],
            ], $group['items']),
            'total_item_count' => $group['total_item_count'],
            'shipping_fee_amount' => $group['shipping_fee_amount'],
            'shipping_methods' => $group['delivery_methods'],
            'courier_names' => $group['courier_names'],
            'tracking_numbers' => $group['tracking_numbers'],
            'shipment_statuses' => $group['shipment_statuses'],
            'payment' => [
                'is_cod' => $order->paymentMethod?->type === 'cod',
                'cod_amount_due' => $order->paymentMethod?->type === 'cod' && $group['active_delivery_group_count'] === 1 && $order->payment_status !== 'paid'
                    ? $order->total_amount : null,
                'initial_dp_amount' => $group['payment']['requested_dp'],
                'initial_dp_credit' => $group['payment']['verified_dp_credit'],
                'dp_credit_date' => $group['payment']['dp_credit_date'],
                'order_has_multiple_delivery_groups' => $group['active_delivery_group_count'] > 1,
            ],
        ]);
    }
}