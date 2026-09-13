<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShippingProvider;
use App\Services\Logging\ActivityLogger;

/**
 * Super Admin-only shipping provider administration — GLOBAL on/off switch
 * only. Credentials and rate settings are configured per-agen instead (see
 * Agent\AgentShippingProviderController) — super_admin never sees or sets
 * them.
 */
class ShippingProviderController extends Controller
{
    public function index()
    {
        $providers = ShippingProvider::query()->whereIn('code', ['rajaongkir', 'openroute'])->orderBy('id')->get();

        return $this->ok($providers->map(fn (ShippingProvider $provider) => $this->present($provider))->values());
    }

    public function toggle(ShippingProvider $provider)
    {
        $provider->update(['is_active' => ! $provider->is_active]);

        ActivityLogger::log(request()->user()->id, $provider, 'shipping_provider.toggled', null, ['is_active' => $provider->is_active]);

        return $this->ok($this->present($provider->fresh()), __('messages.shipping.provider_toggled'));
    }

    private function present(ShippingProvider $provider): array
    {
        return [
            'id' => $provider->id,
            'code' => $provider->code,
            'name' => $provider->name,
            'is_active' => $provider->is_active,
        ];
    }
}
