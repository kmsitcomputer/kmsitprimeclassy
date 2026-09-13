<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Public-facing — deliberately never includes fee columns (see ProductFee/ProductVariationFee). */
class ProductVariationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'label' => $this->label(),
            'price' => $this->price,
            'weight_grams' => $this->weight_grams,
            'is_active' => $this->is_active,
            'agent_available_quantity' => $this->when(
                isset($this->agent_available_quantity),
                fn () => $this->agent_available_quantity
            ),
        ];
    }
}
