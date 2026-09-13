<?php

namespace App\Http\Requests\Agent;

use App\Http\Requests\BaseFormRequest;
use App\Models\ShippingProvider;
use App\Support\ShippingProviderFields;

/**
 * An agen's own credentials (+ OpenRoute rate settings) for a shipping
 * provider — mirrors the old (now removed) Admin\UpdateShippingProviderConfigRequest,
 * scoped to the authenticated agen.
 */
class UpdateAgentShippingProviderConfigRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isRole('agen') ?? false;
    }

    public function rules(): array
    {
        /** @var ShippingProvider $provider */
        $provider = $this->route('provider');

        $rules = [
            'config' => ['required', 'array'],
            ...ShippingProviderFields::rulesFor($provider->code),
        ];

        if ($provider->code === 'openroute') {
            $rules['price_per_km'] = ['required', 'numeric', 'min:0'];
            $rules['minimum_distance_km'] = ['required', 'numeric', 'min:0'];
            $rules['minimum_charge'] = ['nullable', 'numeric', 'min:0'];
            $rules['free_shipping_enabled'] = ['sometimes', 'boolean'];
            $rules['free_shipping_min_amount'] = ['nullable', 'numeric', 'min:0'];
        }

        return $rules;
    }
}
