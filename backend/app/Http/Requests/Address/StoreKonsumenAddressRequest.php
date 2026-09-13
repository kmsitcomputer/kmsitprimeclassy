<?php

namespace App\Http\Requests\Address;

use App\Http\Requests\BaseFormRequest;

/**
 * Only village_id is required — the district/regency/province chain is
 * always derived server-side from it (never trusted separately from the
 * client, which could otherwise submit a mismatched hierarchy).
 */
class StoreKonsumenAddressRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isRole('konsumen') ?? false;
    }

    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:50'],
            'recipient_name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:20'],
            'address_line' => ['required', 'string', 'max:1000'],
            'village_id' => ['required', 'string', 'exists:villages,id'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
