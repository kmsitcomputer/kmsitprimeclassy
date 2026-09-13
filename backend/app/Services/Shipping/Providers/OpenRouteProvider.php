<?php

namespace App\Services\Shipping\Providers;

use App\Contracts\Shipping\ShippingCostProviderInterface;
use App\DataTransferObjects\ShippingQuoteContext;
use App\DataTransferObjects\ShippingQuoteResult;
use App\Exceptions\ShippingQuoteException;
use App\Models\AgentShippingProviderConfig;
use App\Models\ShippingConfiguration;
use App\Models\ShippingProvider;
use App\Services\Shipping\OpenRouteDistanceCalculator;
use Illuminate\Support\Facades\Cache;

/**
 * Distance-based shipping using OpenRouteService for the actual road
 * distance, then a simple threshold rule Super Admin controls via the
 * global ShippingConfiguration row linked to this provider:
 *   - distance < minimum_distance_km -> FREE (0 cost) — "dalam radius bebas ongkir"
 *   - distance >= minimum_distance_km -> cost = distance * price_per_km
 * A subtotal-based free-shipping override (free_shipping_enabled +
 * free_shipping_min_amount) is checked first, same as before.
 */
class OpenRouteProvider implements ShippingCostProviderInterface
{
    private const DISTANCE_CACHE_TTL_SECONDS = 300;

    public function __construct(private readonly OpenRouteDistanceCalculator $distanceCalculator) {}

    public function quote(ShippingQuoteContext $context, ShippingProvider $providerRow): ShippingQuoteResult
    {
        if (! $context->agentId) {
            throw new ShippingQuoteException('OpenRoute requires an agen context to resolve credentials.');
        }

        $config = AgentShippingProviderConfig::query()
            ->where('agent_id', $context->agentId)->where('shipping_provider_id', $providerRow->id)
            ->value('config') ?? [];

        if (empty($config['api_key'])) {
            throw new ShippingQuoteException('OpenRoute is not configured (api_key missing).');
        }

        $rateConfig = ShippingConfiguration::query()
            ->where('shipping_provider_id', $providerRow->id)
            ->where('agent_id', $context->agentId)
            ->where('is_active', true)
            ->first();

        if (! $rateConfig) {
            throw new ShippingQuoteException('OpenRoute has no active rate configuration (price_per_km/minimum_distance_km).');
        }

        if ($rateConfig->free_shipping_enabled
            && $rateConfig->free_shipping_min_amount !== null
            && $context->subtotal >= (float) $rateConfig->free_shipping_min_amount) {
            return ShippingQuoteResult::free('subtotal_threshold_met');
        }

        // Distance between two fixed coordinates doesn't change — a short
        // cache avoids paying for a fresh routing call on every quote/order
        // for the same origin+destination pair, without staying stale long
        // enough to matter (this is not live traffic-aware routing).
        $cacheKey = 'openroute:distance:'.round($context->originLat, 4).','.round($context->originLng, 4)
            .'-'.round($context->destLat, 4).','.round($context->destLng, 4);

        $distanceKm = Cache::remember($cacheKey, self::DISTANCE_CACHE_TTL_SECONDS, fn () => $this->distanceCalculator->calculate(
            $context->originLat, $context->originLng, $context->destLat, $context->destLng, $config
        ));

        $ratePerKm = (float) $rateConfig->price_per_km;
        $minimumDistance = (float) $rateConfig->minimum_distance_km;

        if ($distanceKm < $minimumDistance) {
            return new ShippingQuoteResult(
                cost: 0.0, distanceKm: $distanceKm, ratePerKm: $ratePerKm, providerCode: 'openroute',
                meta: ['rule' => 'below_minimum_distance', 'minimum_distance_km' => $minimumDistance],
            );
        }

        $cost = round($distanceKm * $ratePerKm, 2);

        if ((float) $rateConfig->minimum_charge > 0) {
            $cost = max($cost, (float) $rateConfig->minimum_charge);
        }

        return new ShippingQuoteResult(
            cost: $cost, distanceKm: $distanceKm, ratePerKm: $ratePerKm, providerCode: 'openroute',
            meta: ['rule' => 'distance_rate_applied'],
        );
    }
}
