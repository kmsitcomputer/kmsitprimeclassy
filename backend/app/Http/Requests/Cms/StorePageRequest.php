<?php

namespace App\Http\Requests\Cms;

use App\Http\Requests\BaseFormRequest;

class StorePageRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // Gate::authorize('manage-system-config') checked explicitly in the controller.
    }

    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:150', 'unique:cms_pages,slug'],
            'status' => ['sometimes', 'string', 'in:draft,published'],
            'published_at' => ['nullable', 'date'],
            'cover_media_id' => ['nullable', 'integer', 'exists:media,id'],

            'translations' => ['required', 'array', 'min:1'],
            'translations.*.language_id' => ['required', 'integer', 'exists:languages,id', 'distinct'],
            'translations.*.title' => ['required', 'string', 'max:200'],
            'translations.*.body' => ['nullable', 'string'],
            'translations.*.seo_title' => ['nullable', 'string', 'max:200'],
            'translations.*.seo_description' => ['nullable', 'string', 'max:300'],
        ];
    }
}
