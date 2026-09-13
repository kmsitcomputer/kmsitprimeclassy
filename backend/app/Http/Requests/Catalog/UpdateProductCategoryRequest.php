<?php

namespace App\Http\Requests\Catalog;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class UpdateProductCategoryRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // ProductCategoryPolicy::update checked explicitly in the controller.
    }

    public function rules(): array
    {
        return [
            'parent_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'name' => ['sometimes', 'string', 'max:100'],
            'slug' => ['sometimes', 'string', 'max:120', Rule::unique('product_categories', 'slug')->ignore($this->route('category'))],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
