<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** Admin-facing shape — raw content plus a resolved image URL for convenience. */
class CmsHomepageBlockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'content' => $this->content,
            'image_url' => isset($this->content['image_path'])
                ? Storage::disk('public')->url($this->content['image_path'])
                : null,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
