<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariationStockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agent_id' => $this->agent_id,
            'product_variation_id' => $this->product_variation_id,
            'sku' => $this->whenLoaded('variation', fn () => $this->variation->sku),
            'variation_label' => $this->whenLoaded('variation', fn () => $this->variation->label()),
            'quantity_on_hand' => $this->quantity_on_hand,
            'quantity_reserved' => $this->quantity_reserved,
            'quantity_available' => $this->availableQuantity(),
            'updated_at' => $this->updated_at,
        ];
    }
}
