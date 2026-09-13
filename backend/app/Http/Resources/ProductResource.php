<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public catalog shape. Fee amounts (product_fees / product_variation_fees)
 * are never included here — see ProductPolicy::viewFees() for who may see
 * them, exposed only through a separate, role-gated endpoint/resource.
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'short_description' => $this->short_description,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),
            'has_variations' => $this->has_variations,
            'sku' => $this->sku,
            'base_price' => $this->when(! $this->has_variations, $this->base_price),
            'weight_grams' => $this->when(! $this->has_variations, $this->weight_grams),
            // "Agent product availability" — only present when the resolver upstream
            // (ProductController) attached it for the viewer's own network agent.
            // Never a global/summed figure across agents (see StockService docs).
            'agent_available_quantity' => $this->when(
                isset($this->agent_available_quantity),
                fn () => $this->agent_available_quantity
            ),
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
            'variations' => ProductVariationResource::collection($this->whenLoaded('variations')),
            'status' => $this->status,
        ];
    }
}
