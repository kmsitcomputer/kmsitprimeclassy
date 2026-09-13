<?php

namespace App\Http\Requests\Catalog;

use App\Http\Requests\BaseFormRequest;
use App\Rules\GlobalSku;

class StoreProductRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // ProductPolicy::create checked explicitly in the controller.
    }

    public function rules(): array
    {
        return [
            'sku' => ['required_if:has_variations,false', 'prohibited_if:has_variations,true', 'nullable', 'string', 'max:60', new GlobalSku('product')],
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:180', 'unique:products,slug'],
            'description' => ['nullable', 'string'],
            'short_description' => ['nullable', 'string', 'max:300'],
            'has_variations' => ['required', 'boolean'],
            // Only meaningful — and only allowed — when has_variations = false.
            // A variation product's price/weight live on each ProductVariation
            // row instead; setting these here too would leave two conflicting
            // sources of truth for the same product.
            'base_price' => ['required_if:has_variations,false', 'prohibited_if:has_variations,true', 'nullable', 'numeric', 'min:0'],
            'weight_grams' => ['required_if:has_variations,false', 'prohibited_if:has_variations,true', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'in:draft,active,inactive'],
        ];
    }
}
