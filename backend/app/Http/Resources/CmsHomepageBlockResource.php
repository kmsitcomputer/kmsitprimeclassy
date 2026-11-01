<?php

namespace App\Http\Resources;

use App\Support\HomepageBlockTypes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** Admin-facing shape — raw content plus a resolved image URL for convenience. */
class CmsHomepageBlockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $content = $this->content;
        if ($this->type === HomepageBlockTypes::BANNER_SLIDER && is_array($content)) {
            $content['slides'] = HomepageBlockTypes::resolveSlides($content['slides'] ?? [], true);
        }

        return [
            'id' => $this->id,
            'type' => $this->type,
            'content' => $content,
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
