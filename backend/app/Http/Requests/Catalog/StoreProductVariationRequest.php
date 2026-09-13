<?php

namespace App\Http\Requests\Catalog;

use App\Http\Requests\BaseFormRequest;
use App\Rules\GlobalSku;

class StoreProductVariationRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // ProductPolicy::update (on the parent product) checked explicitly in the controller.
    }

    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:60', new GlobalSku('variant', $this->route('variation')?->id), 'unique:product_variations,sku'],
            'price' => ['required', 'numeric', 'min:0'],
            'weight_grams' => ['required', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'attributes' => ['required', 'array', 'min:1'],
            'attributes.*' => ['required', 'string', 'max:100'],
        ];
    }
}
