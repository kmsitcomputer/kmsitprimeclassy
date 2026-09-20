<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * PBR-003 Screen A — product-oriented identity for warehouse bucket rows.
 * Keeps the existing nested product/variation/sub_location objects (so no
 * consumer breaks) and adds flat human-readable fields: product_name,
 * variant label, sku, and the product's primary image. IDs stay present
 * but secondary — staff identify rows by name/variant/SKU.
 */
class WarehouseStockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // DS-PBR-003: never assign whenLoaded() to a variable — the
        // MissingValue it returns when unloaded is truthy and misleads
        // conditionals. relationLoaded() gates derived fields; the nested
        // objects themselves use the whenLoaded(key) pass-through form.
        $product = $this->relationLoaded('product') ? $this->product : null;
        $variation = $this->relationLoaded('variation') ? $this->variation : null;

        return [
            'id' => $this->id,
            'agent_id' => $this->agent_id,
            'product_id' => $this->product_id,
            'product_variation_id' => $this->product_variation_id,
            'stock_type' => $this->stock_type,
            'sub_location_id' => $this->sub_location_id,
            'quantity' => $this->quantity,
            'product' => $this->whenLoaded('product'),
            'variation' => $this->whenLoaded('variation'),
            'sub_location' => $this->whenLoaded('subLocation'),
            'product_name' => $product?->name,
            'variation_label' => $variation?->label(),
            'sku' => $variation?->sku ?? $product?->sku,
            'product_image_url' => $this->primaryImageUrl(),
            'updated_at' => $this->updated_at,
        ];
    }

    private function primaryImageUrl(): ?string
    {
        if (! $this->relationLoaded('product') || ! $this->product) {
            return null;
        }
        // DS-PBR-002: resolve from the eager-loaded product.images
        // collection in memory — never a per-row images() query. Primary
        // first, otherwise lowest sort_order; null when imageless.
        $images = $this->product->relationLoaded('images') ? $this->product->images : collect();
        $primary = $images->firstWhere('is_primary', true) ?? $images->sortBy('sort_order')->first();
        $path = $primary?->path;

        return $path ? Storage::disk('public')->url($path) : null;
    }
}
