<?php

namespace App\Http\Requests\Catalog;

use App\Http\Requests\BaseFormRequest;
use App\Rules\GlobalSku;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // ProductPolicy::update checked explicitly in the controller.
    }

    public function rules(): array
    {
        // has_variations is immutable after creation (ProductController::update
        // never accepts it), so the product's own current value is always the
        // real, unchangeable answer here — never something this request's
        // own payload could spoof.
        $hasVariations = (bool) ($this->route('product')?->has_variations ?? false);

        return [
            'sku' => [Rule::requiredIf(! $hasVariations && ! $this->route('product')?->sku), 'filled', 'string', 'max:60', Rule::prohibitedIf($hasVariations), new GlobalSku('product', $this->route('product')?->id)],
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'name' => ['sometimes', 'string', 'max:150'],
            'slug' => ['sometimes', 'string', 'max:180', Rule::unique('products', 'slug')->ignore($this->route('product'))],
            'description' => ['nullable', 'string'],
            'short_description' => ['nullable', 'string', 'max:300'],
            'base_price' => ['sometimes', 'nullable', 'numeric', 'min:0', Rule::prohibitedIf($hasVariations)],
            'weight_grams' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::prohibitedIf($hasVariations)],
            'status' => ['sometimes', 'string', 'in:draft,active,inactive'],
        ];
    }
}
