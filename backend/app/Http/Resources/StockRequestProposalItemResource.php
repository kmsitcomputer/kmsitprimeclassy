<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockRequestProposalItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stock_request_proposal_id' => $this->stock_request_proposal_id,
            'stock_request_item_id' => $this->stock_request_item_id,
            'quantity' => $this->quantity,
            'decision_status' => $this->decision_status,
            'decided_by' => $this->decided_by,
            'decided_at' => $this->decided_at,
            'decision_reason' => $this->decision_reason,
            'request_item' => new StockRequestItemResource($this->whenLoaded('requestItem')),
        ];
    }
}
