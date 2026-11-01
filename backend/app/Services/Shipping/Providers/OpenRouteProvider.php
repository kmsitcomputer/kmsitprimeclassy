<?php

namespace App\Services\Shipping\Providers;

use App\Contracts\Shipping\ShippingCostProviderInterface;
use App\DataTransferObjects\ShippingQuoteContext;
use App\DataTransferObjects\ShippingQuoteResult;
use App\Exceptions\ShippingQuoteException;
use App\Models\AgentProfile;
use App\Models\AgentShippingProviderConfig;
use App\Models\AgentShippingProviderSetting;
use App\Models\ShippingConfiguration;
use App\Models\ShippingProvider;
use App\Services\Shipping\OpenRouteDistanceCalculator;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Distance-based shipping using OpenRouteService for the actual road
 * distance, then the agent's configured pricing rule:
 *   chargeable_distance_km = max(0, distance_km - minimum_distance_km)
 *   cost = chargeable_distance_km * price_per_km
 * minimum_distance_km is a free allowance subtracted from the route
 * distance, not a free/paid threshold — distance <= minimum_distance_km
 * always yields cost 0 as a natural consequence of the max(0, ...) floor.
 * A subtotal-based free-shipping override (free_shipping_enabled +
 * free_shipping_min_amount) is checked first, same as before.
 */
class OpenRouteProvider implements ShippingCostProviderInterface
{
    private const DISTANCE_CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly OpenRouteDistanceCalculator $distanceCalculator,
        private readonly KmsitProvider $kmsit,
    ) {}

    /** ORS globally active and not switched off by this Agen (credentials are checked at quote time). */
    public function isOrsEligible(?int $agentId, ShippingProvider $providerRow): bool
    {
        if (! $providerRow->is_active) {
            return false;
        }

        return ! ($agentId && AgentShippingProviderSetting::query()
            ->where('agent_id', $agentId)->where('shipping_provider_id', $providerRow->id)->where('is_active', false)->exists());
    }

    /** Kurir Toko is offerable when ORS is eligible OR the Agen's KMSIT fallback is. */
    public function isAvailable(?int $agentId, ShippingProvider $providerRow): bool
    {
        return $this->isOrsEligible($agentId, $providerRow) || $this->kmsit->isEligible($agentId);
    }

    public function quote(ShippingQuoteContext $context, ShippingProvider $providerRow): ShippingQuoteResult
    {
        if (! $context->agentId) {
            throw new ShippingQuoteException('OpenRoute requires an agen context to resolve credentials.');
        }

        // Shared Kurir Toko tariff (independent of whether ORS or KMSIT supplies the distance, and of the ORS toggle).
        $rateConfig = \App\Services\Shipping\KurirTokoTariff::activeFor($context->agentId);

        if (! $rateConfig) {
            throw new ShippingQuoteException('OpenRoute has no active rate configuration (price_per_km/minimum_distance_km).');
        }

        [$route, $source] = $this->resolveRoute($context, $providerRow);

        $distanceKm = $route->distanceKm;
        $ratePerKm = (float) $rateConfig->price_per_km;
        $minimumDistance = (float) $rateConfig->minimum_distance_km;
        $meta = [
            'api_version' => $source === 'kmsit' ? 'osrm' : 'v2',
            'routing_source' => $source,
            'distance_meters' => $route->distanceMeters,
            'duration_seconds' => $route->durationSeconds,
            'routing_profile' => $route->profile,
            'origin' => ['latitude' => $context->originLat, 'longitude' => $context->originLng],
            'destination' => ['latitude' => $context->destLat, 'longitude' => $context->destLng],
            'pricing' => [
                'price_per_km' => $ratePerKm,
                'minimum_distance_km' => $minimumDistance,
                'minimum_charge' => (float) $rateConfig->minimum_charge,
                'free_shipping_enabled' => $rateConfig->free_shipping_enabled,
                'free_shipping_min_amount' => $rateConfig->free_shipping_min_amount !== null ? (float) $rateConfig->free_shipping_min_amount : null,
            ],
        ];

        if ($rateConfig->free_shipping_enabled
            && $rateConfig->free_shipping_min_amount !== null
            && $context->subtotal >= (float) $rateConfig->free_shipping_min_amount) {
            return new ShippingQuoteResult(
                cost: 0.0, distanceKm: $distanceKm, ratePerKm: $ratePerKm, providerCode: 'openroute',
                meta: [...$meta, 'rule' => 'subtotal_threshold_met'],
            );
        }

        $chargeableDistanceKm = max(0.0, $distanceKm - $minimumDistance);
        $meta['chargeable_distance_km'] = $chargeableDistanceKm;

        if ($chargeableDistanceKm <= 0.0) {
            return new ShippingQuoteResult(
                cost: 0.0, distanceKm: $distanceKm, ratePerKm: $ratePerKm, providerCode: 'openroute',
                meta: [...$meta, 'rule' => 'below_minimum_distance'],
            );
        }

        $cost = round($chargeableDistanceKm * $ratePerKm, 2);

        if ((float) $rateConfig->minimum_charge > 0) {
            $cost = max($cost, (float) $rateConfig->minimum_charge);
        }

        return new ShippingQuoteResult(
            cost: $cost, distanceKm: $distanceKm, ratePerKm: $ratePerKm, providerCode: 'openroute',
            meta: [...$meta, 'rule' => 'distance_rate_applied'],
        );
    }

    /**
     * ORS first (eligible + credentialed + healthy), else the Agen's KMSIT OSRM server, else fail closed.
     * The tariff below is identical for both sources; the provider code stays 'openroute' (Kurir Toko).
     *
     * @return array{0: \App\DataTransferObjects\OpenRouteResult, 1: string}
     */
    private function resolveRoute(ShippingQuoteContext $context, ShippingProvider $providerRow): array
    {
        if ($this->isOrsEligible($context->agentId, $providerRow)) {
            try {
                $config = $this->resolveConfig($context->agentId, $providerRow);
                if (! empty($config['api_key'])) {
                    // Distance between two fixed coordinates doesn't change — a short cache avoids paying for a fresh routing
                    // call on every quote for the same pair (not live traffic-aware routing).
                    $profile = (string) ($config['profile'] ?? config('services.openroute.profile'));
                    $cacheKey = 'openroute:route:'.$profile.':'.round($context->originLat, 5).','.round($context->originLng, 5)
                        .'-'.round($context->destLat, 5).','.round($context->destLng, 5);

                    return [Cache::remember($cacheKey, self::DISTANCE_CACHE_TTL_SECONDS, fn () => $this->distanceCalculator->route(
                        $context->originLat, $context->originLng, $context->destLat, $context->destLng, $config
                    )), 'openrouteservice'];
                }
            } catch (ShippingQuoteException $e) {
                Log::warning('shipping.openroute_route_failed_trying_fallback', ['error' => $e->getMessage()]);
            }
        }

        if ($this->kmsit->isEligible($context->agentId)) {
            return [$this->kmsit->route($context), 'kmsit'];
        }

        throw new ShippingQuoteException('No routing source is available for Kurir Toko.');
    }

    public function testForAgent(int $agentId, ShippingProvider $providerRow, float $destLat, float $destLng): array
    {
        $config = $this->resolveConfig($agentId, $providerRow);
        $profile = AgentProfile::where('user_id', $agentId)->first();
        if (! $profile) {
            throw new ShippingQuoteException('Lokasi origin Agent belum dikonfigurasi.');
        }

        $route = $this->distanceCalculator->route(
            (float) $profile->latitude,
            (float) $profile->longitude,
            $destLat,
            $destLng,
            $config,
        );

        return [
            'connected' => true,
            'profile' => $route->profile,
            'distance_km' => $route->distanceKm,
            'duration_seconds' => $route->durationSeconds,
        ];
    }

    /**
     * @return array{api_key?:string}
     *
     * See RajaOngkirProvider::resolveConfig() — same APP_KEY-rotation risk,
     * same graceful-degradation treatment.
     */
    private function resolveConfig(int $agentId, ShippingProvider $providerRow): array
    {
        try {
            return AgentShippingProviderConfig::query()
                ->where('agent_id', $agentId)->where('shipping_provider_id', $providerRow->id)
                ->value('config') ?? [];
        } catch (DecryptException $e) {
            Log::warning('shipping.openroute_config_undecryptable', ['agent_id' => $agentId, 'error' => $e->getMessage()]);

            throw new ShippingQuoteException('OpenRoute config could not be decrypted (APP_KEY changed since it was saved) — ask the agent to re-save their credentials.');
        }
    }
}
