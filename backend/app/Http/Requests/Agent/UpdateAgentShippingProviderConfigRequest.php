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

    /** Lowercases courier codes before validation — matches the dedicated couriers-checkbox endpoint's normalization, so the same code always compares the same way against the supported master list. */
    protected function prepareForValidation(): void
    {
        $config = $this->input('config');

        if (is_array($config) && isset($config['couriers']) && is_array($config['couriers'])) {
            $config['couriers'] = array_map(fn ($c) => is_string($c) ? strtolower(trim($c)) : $c, $config['couriers']);
            $this->merge(['config' => $config]);
        }

        if (is_array($config) && $this->route('provider')?->code === 'openroute' && empty($config['profile'])) {
            $config['profile'] = config('services.openroute.profile');
            $this->merge(['config' => $config]);
        }
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
