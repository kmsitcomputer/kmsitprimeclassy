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
            'accepted_qty' => $this->accepted_qty,
            'short_qty' => $this->short_qty,
            'damaged_qty' => $this->damaged_qty,
            'request_item' => new StockRequestItemResource($this->whenLoaded('requestItem')),
        ];
    }
}
