<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CmsArticleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'slug' => $this->slug,
            'status' => $this->status,
            'published_at' => $this->published_at,
            'cover_image_url' => $this->cover_image_path ? Storage::disk('public')->url($this->cover_image_path) : null,
            'author_name' => $this->whenLoaded('author', fn () => $this->author?->name),
            'translations' => $this->whenLoaded('translations', fn () => $this->translations->map(fn ($t) => [
                'language_id' => $t->language_id,
                'title' => $t->title,
                'excerpt' => $t->excerpt,
                'body' => $t->body,
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
