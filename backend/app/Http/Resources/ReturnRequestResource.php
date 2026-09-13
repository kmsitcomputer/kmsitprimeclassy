<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** Powers the Admin "Return" dashboard list — item/quantity/amount/customer/order/reason/status/refund status. */
class ReturnRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'order_no' => $this->order->order_no,
            'customer_name' => $this->order->konsumen->name,
            'reason' => $this->reason,
            'evidence_url' => $this->evidence_path ? Storage::disk('public')->url($this->evidence_path) : null,
            'status' => $this->status,
            'total_refund_amount' => $this->total_refund_amount,
            'requested_by' => $this->requestedBy?->name,
            'reviewed_by' => $this->reviewedBy?->name,
            'reviewed_at' => $this->reviewed_at,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'order_item_id' => $item->order_item_id,
                'product_name' => $item->orderItem->product_name_snapshot,
                'variation_label' => $item->orderItem->variation_label_snapshot,
                'sku' => $item->orderItem->sku_snapshot,
                'quantity_returned' => $item->quantity_returned,
                'refund_amount' => $item->refund_amount,
                'restock' => $item->restock,
                'status' => $item->status,
                'refund_status' => $item->refund_status,
                'condition_note' => $item->condition_note,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
