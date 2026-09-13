<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Powers the Admin "Refund" dashboard menu — fulfillment-shortfall refunds. */
class OrderItemAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->orderItem->order_id,
            'order_no' => $this->orderItem->order->order_no,
            'customer_name' => $this->orderItem->order->konsumen->name,
            'item' => $this->orderItem->product_name_snapshot,
            'variation_label' => $this->orderItem->variation_label_snapshot,
            'sku' => $this->orderItem->sku_snapshot,
            'quantity_reduced' => $this->quantity_reduced,
            'refund_amount' => $this->refund_amount,
            'reason' => $this->reason,
            'refund_status' => $this->refund_status,
            'adjusted_by' => $this->adjustedBy?->name,
            'created_at' => $this->created_at,
        ];
    }
}
