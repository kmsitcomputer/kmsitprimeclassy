<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\UpdateAgentShippingProviderConfigRequest;
use App\Models\AgentShippingProviderConfig;
use App\Models\AgentShippingProviderSetting;
use App\Models\ShippingConfiguration;
use App\Models\ShippingProvider;
use App\Services\Logging\ActivityLogger;
use Illuminate\Http\Request;

/**
 * An Agen's own settings for RajaOngkir/OpenRoute, scoped to their own
 * branch only:
 *   - on/off override (never widens what super_admin's global
 *     ShippingProvider::is_active already allows, only narrows it further)
 *   - their own credentials (RajaOngkir api_key/account_type/origin_city_id/
 *     couriers, or OpenRoute api_key) plus, for OpenRoute, their own
 *     per-km rate settings.
 */
class AgentShippingProviderController extends Controller
{
    public function index(Request $request)
    {
        $agentId = $request->user()->id;

        $providers = ShippingProvider::query()->whereIn('code', ['rajaongkir', 'openroute'])->orderBy('id')->get();
        $overrides = AgentShippingProviderSetting::query()->where('agent_id', $agentId)->get()->keyBy('shipping_provider_id');
        $configuredProviderIds = AgentShippingProviderConfig::query()
            ->where('agent_id', $agentId)->pluck('shipping_provider_id');

        return $this->ok($providers->map(fn (ShippingProvider $provider) => [
            'id' => $provider->id,
            'code' => $provider->code,
            'name' => $provider->name,
            'globally_active' => $provider->is_active,
            'is_active' => $overrides->get($provider->id)?->is_active ?? true,
            'configured' => $configuredProviderIds->contains($provider->id),
        ])->values());
    }

    public function toggle(Request $request, ShippingProvider $provider)
    {
        $agentId = $request->user()->id;
        $current = AgentShippingProviderSetting::query()
            ->where('agent_id', $agentId)->where('shipping_provider_id', $provider->id)->first();
        $newState = ! ($current?->is_active ?? true);

        $setting = AgentShippingProviderSetting::query()->updateOrCreate(
            ['agent_id' => $agentId, 'shipping_provider_id' => $provider->id],
            ['is_active' => $newState],
        );

        ActivityLogger::log($agentId, $setting, 'agent_shipping_provider.toggled', null, [
            'shipping_provider' => $provider->code, 'is_active' => $newState,
        ]);

        return $this->ok([
            'id' => $provider->id,
            'code' => $provider->code,
            'name' => $provider->name,
            'globally_active' => $provider->is_active,
            'is_active' => $newState,
        ], __('messages.shipping.provider_toggled'));
    }

    public function updateConfig(UpdateAgentShippingProviderConfigRequest $request, ShippingProvider $provider)
    {
        $agentId = $request->user()->id;

        AgentShippingProviderConfig::query()->updateOrCreate(
            ['agent_id' => $agentId, 'shipping_provider_id' => $provider->id],
            ['config' => $request->input('config')],
        );

        if ($provider->code === 'openroute') {
            ShippingConfiguration::query()->updateOrCreate(
                ['agent_id' => $agentId],
                [
                    'shipping_provider_id' => $provider->id,
                    'price_per_km' => $request->input('price_per_km'),
                    'minimum_distance_km' => $request->input('minimum_distance_km'),
                    'minimum_charge' => $request->input('minimum_charge', 0),
                    'free_shipping_enabled' => $request->boolean('free_shipping_enabled'),
                    'free_shipping_min_amount' => $request->input('free_shipping_min_amount'),
                    'is_active' => true,
                ]
            );
        }

        ActivityLogger::log($agentId, $provider, 'agent_shipping_provider.config_updated', null, [
            'shipping_provider' => $provider->code,
            // Only field NAMES are logged — never their values.
            'fields' => array_keys($request->input('config')),
        ]);

        return $this->ok(['configured' => true], __('messages.shipping.provider_config_saved'));
    }
}
