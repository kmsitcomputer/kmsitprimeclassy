<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class WarehouseStockRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $product = $this->relationLoaded('product') ? $this->product : null;
        $variation = $this->relationLoaded('variation') ? $this->variation : null;
        $variationLabel = $variation
            ? $variation->relationLoaded('compositions')
                ? $variation->compositions->pluck('option.value')->filter()->implode(' / ') ?: null
                : $variation->label()
            : null;

        return [
            'id' => $this->id,
            'agent_id' => $this->agent_id,
            'request_type' => $this->request_type,
            'product_id' => $this->product_id,
            'product_variation_id' => $this->product_variation_id,
            'target_stock_type' => $this->target_stock_type,
            'sub_location_id' => $this->sub_location_id,
            'quantity' => $this->quantity,
            'reference' => $this->reference,
            'note' => $this->note,
            'status' => $this->status,
            'requested_by' => $this->requested_by,
            'requester' => $this->whenLoaded('requester', fn () => [
                'id' => $this->requester->id, 'name' => $this->requester->name,
            ]),
            'sub_location' => $this->whenLoaded('subLocation', fn () => [
                'id' => $this->subLocation->id, 'code' => $this->subLocation->code, 'name' => $this->subLocation->name,
            ]),
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at,
            'rejected_by' => $this->rejected_by,
            'rejected_at' => $this->rejected_at,
            'rejection_reason' => $this->rejection_reason,
            'product_name' => $product?->name,
            'variation_label' => $variationLabel,
            'sku' => $variation?->sku ?? $product?->sku,
            'product_image_url' => $this->primaryImageUrl(),
            'current_stock' => $this->current_stock_quantity ?? null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function primaryImageUrl(): ?string
    {
        if (! $this->relationLoaded('product') || ! $this->product) {
            return null;
        }
        $images = $this->product->relationLoaded('images') ? $this->product->images : collect();
        $primary = $images->firstWhere('is_primary', true) ?? $images->sortBy('sort_order')->first();
        $path = $primary?->path;

        return $path ? Storage::disk('public')->url($path) : null;
    }
}
