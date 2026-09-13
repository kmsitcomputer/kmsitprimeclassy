<?php

namespace App\Http\Requests\Cms;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class UpdatePageRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // Gate::authorize('manage-system-config') checked explicitly in the controller.
    }

    public function rules(): array
    {
        return [
            'slug' => ['sometimes', 'string', 'max:150', Rule::unique('cms_pages', 'slug')->ignore($this->route('page'))],
            'status' => ['sometimes', 'string', 'in:draft,published'],
            'published_at' => ['nullable', 'date'],
            'cover_media_id' => ['nullable', 'integer', 'exists:media,id'],
            'remove_cover' => ['sometimes', 'boolean'],

            'translations' => ['sometimes', 'array', 'min:1'],
            'translations.*.language_id' => ['required_with:translations', 'integer', 'exists:languages,id', 'distinct'],
            'translations.*.title' => ['required_with:translations', 'string', 'max:200'],
            'translations.*.body' => ['nullable', 'string'],
            'translations.*.seo_title' => ['nullable', 'string', 'max:200'],
            'translations.*.seo_description' => ['nullable', 'string', 'max:300'],
        ];
    }
}
