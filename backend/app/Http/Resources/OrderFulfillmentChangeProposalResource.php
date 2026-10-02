<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderFulfillmentChangeProposalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $item = $this->orderItem;

        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'order_no' => $this->order?->order_no,
            'order_item_id' => $this->order_item_id,
            'order_item' => $item ? [
                'id' => $item->id,
                'product_name' => $item->product_name_snapshot,
                'variation_label' => $item->variation_label_snapshot,
                'sku' => $item->sku_snapshot,
            ] : null,
            'status' => $this->status,
            'current' => [
                'fulfilled_quantity' => $item?->fulfilled_quantity,
                'requested_delivery_date' => $item?->requested_delivery_date?->toDateString(),
            ],
            'proposed' => [
                'fulfilled_quantity' => $this->proposed_fulfilled_quantity,
                'requested_delivery_date' => $this->proposed_requested_delivery_date?->toDateString(),
            ],
            'reason' => $this->reason,
            'decision_reason' => $this->decision_reason,
            'proposer' => $this->whenLoaded('proposer', fn () => ['id' => $this->proposer->id, 'name' => $this->proposer->name]),
            'decider' => $this->whenLoaded('decider', fn () => $this->decider ? ['id' => $this->decider->id, 'name' => $this->decider->name] : null),
            'created_at' => $this->created_at,
            'decided_at' => $this->decided_at,
        ];
    }
}