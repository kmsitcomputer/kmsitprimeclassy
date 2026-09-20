<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class StockRequestItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // DS-PBR-003: relationLoaded() gates derived fields — never assign
        // whenLoaded() to a variable (MissingValue is truthy when unloaded).
        $product = $this->relationLoaded('product') ? $this->product : null;
        $variation = $this->relationLoaded('variation') ? $this->variation : null;

        return [
            'id' => $this->id,
            'stock_request_id' => $this->stock_request_id,
            'order_item_id' => $this->order_item_id,
            'product_id' => $this->product_id,
            'product_variation_id' => $this->product_variation_id,
            'product' => $this->whenLoaded('product'),
            'variation' => $this->whenLoaded('variation'),
            'product_name' => $product?->name,
            'variation_label' => $variation?->label(),
            'sku' => $this->sku_snapshot ?? $variation?->sku ?? $product?->sku,
            'sku_snapshot' => $this->sku_snapshot,
            'product_image_url' => $this->primaryImageUrl(),
            'requested_qty' => $this->requested_qty,
            'fulfilled_qty' => $this->fulfilled_qty,
            'remaining_qty' => $this->remaining_qty,
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
