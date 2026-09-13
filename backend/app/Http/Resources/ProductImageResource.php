<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_variation_id' => $this->product_variation_id,
            'url' => Storage::disk('public')->url($this->path),
            'is_primary' => $this->is_primary,
            'sort_order' => $this->sort_order,
        ];
    }
}
