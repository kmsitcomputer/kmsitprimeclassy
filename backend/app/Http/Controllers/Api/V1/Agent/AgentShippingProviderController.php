<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Exceptions\ShippingQuoteException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\UpdateAgentShippingProviderConfigRequest;
use App\Models\AgentProfile;
use App\Models\AgentShippingProviderConfig;
use App\Models\AgentShippingProviderSetting;
use App\Models\ShippingConfiguration;
use App\Models\ShippingCourier;
use App\Models\ShippingProvider;
use App\Services\Logging\ActivityLogger;
use App\Services\Shipping\Providers\OpenRouteProvider;
use App\Services\Shipping\Providers\RajaOngkirProvider;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;

/**
 * An Agen's own settings for RajaOngkir/OpenRoute, scoped to their own
 * branch only:
 *   - on/off override (never widens what super_admin's global
 *     ShippingProvider::is_active already allows, only narrows it further)
 *   - their own credentials (RajaOngkir api_key/origin_destination_id/
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
        $configs = AgentShippingProviderConfig::query()->where('agent_id', $agentId)->get()->keyBy('shipping_provider_id');
        $rates = ShippingConfiguration::query()->where('agent_id', $agentId)->get()->keyBy('shipping_provider_id');
        $agentProfile = AgentProfile::query()->where('user_id', $agentId)->first();

        return $this->ok($providers->map(function (ShippingProvider $provider) use ($overrides, $configs, $rates, $agentProfile) {
            $configRow = $configs->get($provider->id);
            try {
                $config = $configRow?->config ?? [];
            } catch (DecryptException) {
                $config = [];
            }

            return [
                'id' => $provider->id,
                'code' => $provider->code,
                'name' => $provider->name,
                'globally_active' => $provider->is_active,
                'is_active' => $overrides->get($provider->id)?->is_active ?? true,
                'configured' => (bool) $configRow,
                'api_version' => $provider->code === 'rajaongkir' ? ($config['api_version'] ?? 'legacy') : null,
                'origin' => $provider->code === 'rajaongkir' ? ($config['origin_label'] ?? null) : null,
                'couriers' => $provider->code === 'rajaongkir' ? ($config['couriers'] ?? []) : [],
                'profile' => $provider->code === 'openroute' ? ($config['profile'] ?? config('services.openroute.profile')) : null,
                'origin_coordinates' => $provider->code === 'openroute' && $agentProfile ? [
                    'latitude' => (float) $agentProfile->latitude,
                    'longitude' => (float) $agentProfile->longitude,
                ] : null,
                'pricing' => $provider->code === 'openroute' && $rates->get($provider->id) ? [
                    'price_per_km' => (float) $rates->get($provider->id)->price_per_km,
                    'minimum_distance_km' => (float) $rates->get($provider->id)->minimum_distance_km,
                    'minimum_charge' => (float) $rates->get($provider->id)->minimum_charge,
                ] : null,
            ];
        })->values());
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

    public function updateConfig(UpdateAgentShippingProviderConfigRequest $request, ShippingProvider $provider, RajaOngkirProvider $rajaOngkir)
    {
        $agentId = $request->user()->id;
        $config = $request->input('config');

        if ($provider->code === 'rajaongkir') {
            try {
                $origin = $rajaOngkir->validateOriginSelection(
                    $config['api_key'],
                    $config['origin_search'],
                    (string) $config['origin_destination_id'],
                );
            } catch (ShippingQuoteException) {
                return $this->fail('Origin RajaOngkir tidak valid atau API key tidak dapat digunakan.', null, 422);
            }

            $config['origin_destination_id'] = $origin['id'];
            $config['origin_label'] = $origin['label'];
        }

        AgentShippingProviderConfig::replaceConfig($agentId, $provider->id, $config);

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
            'fields' => array_keys($config),
        ]);

        return $this->ok(['configured' => true], __('messages.shipping.provider_config_saved'));
    }

    /**
     * AVAILABLE couriers for this agen's checkbox UI = every provider-
     * SUPPORTED courier (Super Admin's shipping_couriers master list, see
     * its migration), each flagged with whether THIS agen currently has it
     * ENABLED (their own config.couriers, unrelated to any other agen's).
     */
    public function couriers(Request $request, ShippingProvider $provider)
    {
        abort_unless($provider->code === 'rajaongkir', 404);
        $agentId = $request->user()->id;

        $supported = ShippingCourier::query()->where('is_active', true)->orderBy('name')->get(['code', 'name']);
        $enabled = collect($this->currentConfig($agentId, $provider)['couriers'] ?? [])
            ->map(fn ($c) => strtolower((string) $c));

        return $this->ok($supported->map(fn (ShippingCourier $c) => [
            'code' => $c->code,
            'name' => $c->name,
            'supported' => true,
            'enabled' => $enabled->contains($c->code),
        ])->values());
    }

    /**
     * Saves ONLY the enabled-courier checkboxes — merged into the agen's
     * existing RajaOngkir config (api_key/origin untouched) rather than
     * requiring the full credential form to be resubmitted. Every submitted
     * code must be in the provider-supported master list; anything else is
     * rejected here — never trusted from the client (Blueprint §Security),
     * and this is what actually keeps a disabled/unsupported courier out of
     * checkout (RajaOngkirProvider only ever queries agen-enabled couriers).
     */
    public function updateCouriers(Request $request, ShippingProvider $provider)
    {
        abort_unless($provider->code === 'rajaongkir', 404);
        $agentId = $request->user()->id;

        $validated = $request->validate([
            'couriers' => ['present', 'array'],
            'couriers.*' => ['string', 'max:30'],
        ]);

        $supportedCodes = ShippingCourier::query()->where('is_active', true)->pluck('code')
            ->map(fn ($c) => strtolower($c));
        $requested = collect($validated['couriers'])->map(fn ($c) => strtolower(trim($c)))->filter()->unique()->values();

        $unsupported = $requested->diff($supportedCodes);
        if ($unsupported->isNotEmpty()) {
            return $this->fail(
                __('messages.shipping.unsupported_couriers', ['couriers' => $unsupported->implode(', ')]),
                ['couriers' => [__('messages.shipping.unsupported_couriers', ['couriers' => $unsupported->implode(', ')])]],
                422,
            );
        }

        try {
            $config = $this->readConfig($agentId, $provider);
        } catch (DecryptException) {
            return $this->fail(
                'Credential lama tidak dapat dibaca. Simpan ulang konfigurasi dan API key RajaOngkir terlebih dahulu.',
                ['config' => ['Konfigurasi terenkripsi tidak cocok dengan APP_KEY server.']],
                422,
            );
        }
        $before = $config['couriers'] ?? [];
        $config['couriers'] = $requested->all();

        AgentShippingProviderConfig::replaceConfig($agentId, $provider->id, $config);

        ActivityLogger::log($agentId, $provider, 'agent_shipping_provider.couriers_updated', null, [
            'shipping_provider' => $provider->code, 'old' => $before, 'new' => $config['couriers'],
        ]);

        return $this->ok(['couriers' => $config['couriers']], __('messages.shipping.couriers_saved'));
    }

    /** @return array<string, mixed> Never throws on an undecryptable/missing row — treated as "nothing configured yet". */
    private function currentConfig(int $agentId, ShippingProvider $provider): array
    {
        try {
            return $this->readConfig($agentId, $provider);
        } catch (DecryptException) {
            return [];
        }
    }

    /** @return array<string, mixed> */
    private function readConfig(int $agentId, ShippingProvider $provider): array
    {
        return AgentShippingProviderConfig::query()
            ->where('agent_id', $agentId)->where('shipping_provider_id', $provider->id)
            ->value('config') ?? [];
    }

    public function destinations(Request $request, ShippingProvider $provider, RajaOngkirProvider $rajaOngkir)
    {
        abort_unless($provider->code === 'rajaongkir', 404);
        $validated = $request->validate([
            'search' => ['required', 'string', 'min:3', 'max:100'],
            'api_key' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            return $this->ok($rajaOngkir->searchForAgent(
                $request->user()->id,
                $provider,
                $validated['search'],
                $validated['api_key'] ?? null,
            ));
        } catch (ShippingQuoteException $e) {
            return $this->fail('Koneksi atau pencarian RajaOngkir gagal.', null, 422);
        }
    }

    public function testConnection(Request $request, ShippingProvider $provider, RajaOngkirProvider $rajaOngkir, OpenRouteProvider $openRoute)
    {
        try {
            if ($provider->code === 'rajaongkir') {
                return $this->ok($rajaOngkir->testForAgent($request->user()->id, $provider));
            }
            abort_unless($provider->code === 'openroute', 404);
            $validated = $request->validate([
                'destination_latitude' => ['required', 'numeric', 'between:-90,90'],
                'destination_longitude' => ['required', 'numeric', 'between:-180,180'],
            ]);

            return $this->ok($openRoute->testForAgent(
                $request->user()->id,
                $provider,
                (float) $validated['destination_latitude'],
                (float) $validated['destination_longitude'],
            ));
        } catch (ShippingQuoteException $e) {
            return $this->fail('Koneksi provider pengiriman gagal. Periksa API key, profile, dan koordinat.', null, 422);
        }
    }
}
