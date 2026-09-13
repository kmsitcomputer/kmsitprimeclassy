<?php

namespace App\Http\Requests\Cms;

use App\Http\Requests\BaseFormRequest;
use App\Support\HomepageBlockTypes;

class StoreHomepageBlockRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // Gate::allows('manage-system-config') checked explicitly in the controller.
    }

    public function rules(): array
    {
        $type = $this->input('type');

        return array_merge([
            'type' => ['required', 'string', 'in:'.implode(',', HomepageBlockTypes::slugs())],
            'content' => ['nullable', 'array'],
            // A real uploaded file only — never a URL string as a substitute.
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ], is_string($type) ? HomepageBlockTypes::contentRules($type) : []);
    }

    public function metaData(): array
    {
        return array_filter([
            'sort_order' => $this->has('sort_order') ? $this->integer('sort_order') : null,
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : null,
            'starts_at' => $this->input('starts_at'),
            'ends_at' => $this->input('ends_at'),
        ], fn ($v) => $v !== null);
    }
}
