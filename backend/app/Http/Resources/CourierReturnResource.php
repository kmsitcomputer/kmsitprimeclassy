<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Kurir's "pengembalian" queue — same no-money rule as CourierOrderResource: refund_amount/total_refund_amount never appear here. */
class CourierReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'order_no' => $this->order->order_no,
            'reason' => $this->reason,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'order_item_id' => $item->order_item_id,
                'product_name' => $item->orderItem->product_name_snapshot,
                'variation_label' => $item->orderItem->variation_label_snapshot,
                'sku' => $item->orderItem->sku_snapshot,
                'quantity_returned' => $item->quantity_returned,
                'status' => $item->orderItem->status,
                'condition_note' => $item->condition_note,
                // Whether THIS kurir already claimed the pickup — null means still open to anyone.
                'picked_up_by_me' => $item->courier_id !== null && $item->courier_id === $request->user()?->courierProfile?->id,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
