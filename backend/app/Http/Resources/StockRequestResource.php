<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agent_id' => $this->agent_id,
            'order_id' => $this->order_id,
            'request_number' => $this->request_number,
            'status' => $this->status,
            'items' => StockRequestItemResource::collection($this->whenLoaded('items')),
            'order' => $this->whenLoaded('order', fn () => [
                'id' => $this->order->id, 'order_no' => $this->order->order_no, 'status' => $this->order->status,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
