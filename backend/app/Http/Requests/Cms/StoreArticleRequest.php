<?php

namespace App\Http\Requests\Cms;

use App\Http\Requests\BaseFormRequest;

class StoreArticleRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // Gate::authorize('manage-system-config') checked explicitly in the controller.
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:article,news'],
            'slug' => ['required', 'string', 'max:180', 'unique:cms_articles,slug'],
            'status' => ['sometimes', 'string', 'in:draft,published'],
            'published_at' => ['nullable', 'date'],
            'cover_media_id' => ['nullable', 'integer', 'exists:media,id'],

            'translations' => ['required', 'array', 'min:1'],
            'translations.*.language_id' => ['required', 'integer', 'exists:languages,id', 'distinct'],
            'translations.*.title' => ['required', 'string', 'max:200'],
            'translations.*.excerpt' => ['nullable', 'string', 'max:300'],
            'translations.*.body' => ['required', 'string'],
        ];
    }
}
