<?php

namespace App\Http\Requests\Cms;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class UpdateArticleRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // Gate::authorize('manage-system-config') checked explicitly in the controller.
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'string', 'in:article,news'],
            'slug' => ['sometimes', 'string', 'max:180', Rule::unique('cms_articles', 'slug')->ignore($this->route('article'))],
            'status' => ['sometimes', 'string', 'in:draft,published'],
            'published_at' => ['nullable', 'date'],
            'cover_media_id' => ['nullable', 'integer', 'exists:media,id'],
            'remove_cover' => ['sometimes', 'boolean'],

            'translations' => ['sometimes', 'array', 'min:1'],
            'translations.*.language_id' => ['required_with:translations', 'integer', 'exists:languages,id', 'distinct'],
            'translations.*.title' => ['required_with:translations', 'string', 'max:200'],
            'translations.*.excerpt' => ['nullable', 'string', 'max:300'],
            'translations.*.body' => ['required_with:translations', 'string'],
        ];
    }
}
