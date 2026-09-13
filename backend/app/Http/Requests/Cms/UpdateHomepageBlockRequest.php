<?php

namespace App\Http\Requests\Cms;

use App\Http\Requests\BaseFormRequest;
use App\Models\CmsHomepageBlock;
use App\Support\HomepageBlockTypes;

/** Block `type` is immutable after creation — its content shape is type-specific. */
class UpdateHomepageBlockRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // Gate::allows('manage-system-config') checked explicitly in the controller.
    }

    public function rules(): array
    {
        /** @var CmsHomepageBlock $block */
        $block = $this->route('block');

        $contentRules = collect(HomepageBlockTypes::contentRules($block->type))
            ->mapWithKeys(fn ($rules, $field) => [$field => array_map(
                fn ($rule) => $rule === 'required' ? 'sometimes' : $rule,
                $rules
            )])
            ->all();

        return array_merge([
            'content' => ['nullable', 'array'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_image' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ], $contentRules);
    }

    public function metaData(): array
    {
        $meta = [];

        if ($this->has('sort_order')) {
            $meta['sort_order'] = $this->integer('sort_order');
        }
        if ($this->has('is_active')) {
            $meta['is_active'] = $this->boolean('is_active');
        }
        if ($this->has('starts_at')) {
            $meta['starts_at'] = $this->input('starts_at');
        }
        if ($this->has('ends_at')) {
            $meta['ends_at'] = $this->input('ends_at');
        }

        return $meta;
    }
}
