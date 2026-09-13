<?php

namespace App\Http\Requests\Catalog;

use App\Http\Requests\BaseFormRequest;

class StoreProductCategoryRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // ProductCategoryPolicy::create checked explicitly in the controller.
    }

    public function rules(): array
    {
        return [
            'parent_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:120', 'unique:product_categories,slug'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
