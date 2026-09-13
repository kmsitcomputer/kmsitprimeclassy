<?php

namespace App\Http\Requests\Catalog;

use App\Http\Requests\BaseFormRequest;
use App\Rules\GlobalSku;
use Illuminate\Validation\Rule;

/** Attribute composition is immutable after creation — only commercial fields change. */
class UpdateProductVariationRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // ProductPolicy::update (on the parent product) checked explicitly in the controller.
    }

    public function rules(): array
    {
        return [
            'sku' => ['sometimes', 'string', 'max:60', new GlobalSku('variant', $this->route('variation')?->id), Rule::unique('product_variations', 'sku')->ignore($this->route('variation'))],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'weight_grams' => ['sometimes', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
