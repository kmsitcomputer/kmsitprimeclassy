<?php

namespace App\Http\Requests\Catalog;

use App\Http\Requests\BaseFormRequest;

class StoreProductImageRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // ProductPolicy::update (on the parent product) checked explicitly in the controller.
    }

    public function rules(): array
    {
        return [
            // 4MB, re-validated as a real image (not just extension); mimes: excludes SVG,
            // which Laravel's bare "image" rule otherwise allows through despite it being
            // able to carry an embedded <script> (stored-XSS risk if ever rendered inline).
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'product_variation_id' => ['nullable', 'integer', 'exists:product_variations,id'],
            'is_primary' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
