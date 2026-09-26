<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockRequestProposalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agent_id' => $this->agent_id,
            'stock_request_id' => $this->stock_request_id,
            'status' => $this->status,
            'requested_by' => $this->requested_by,
            'requester' => $this->whenLoaded('requester', fn () => [
                'id' => $this->requester->id, 'name' => $this->requester->name,
            ]),
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at,
            'rejected_by' => $this->rejected_by,
            'rejected_at' => $this->rejected_at,
            'rejection_reason' => $this->rejection_reason,
            'items' => StockRequestProposalItemResource::collection($this->whenLoaded('items')),
            'request' => new StockRequestResource($this->whenLoaded('request')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
