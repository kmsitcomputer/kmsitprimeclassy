<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CmsPageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'status' => $this->status,
            'published_at' => $this->published_at,
            'cover_image_url' => $this->cover_image_path ? Storage::disk('public')->url($this->cover_image_path) : null,
            'translations' => $this->whenLoaded('translations', fn () => $this->translations->map(fn ($t) => [
                'language_id' => $t->language_id,
                'title' => $t->title,
                'body' => $t->body,
                'seo_title' => $t->seo_title,
                'seo_description' => $t->seo_description,
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
