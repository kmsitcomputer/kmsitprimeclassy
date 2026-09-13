<?php

namespace App\Services\Shipping\Providers;

use App\Contracts\Shipping\ShippingCostProviderInterface;
use App\DataTransferObjects\ShippingQuoteContext;
use App\DataTransferObjects\ShippingQuoteResult;
use App\Exceptions\ShippingQuoteException;
use App\Models\AgentShippingProviderConfig;
use App\Models\Regency;
use App\Models\ShippingProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * RajaOngkir Cost API (https://rajaongkir.com/dokumentasi). Config keys
 * required: api_key, account_type ('starter'|'basic'|'pro'), origin_city_id
 * (RajaOngkir's own city id for the store — a "kebutuhan provider" Super
 * Admin must supply, distinct from our own province/regency/district/village
 * codes), couriers (array of courier codes, e.g. ['jne','pos']).
 *
 * The destination is resolved from the order's regency via
 * Regency::rajaongkir_city_id — a nullable mapping column, since RajaOngkir
 * uses its own unrelated city numbering. A regency with no mapping simply
 * can't be quoted by RajaOngkir (ShippingQuoteException — the caller falls
 * back to OpenRoute or free shipping, never blocks checkout).
 */
class RajaOngkirProvider implements ShippingCostProviderInterface
{
    private const CACHE_TTL_SECONDS = 600;

    public function quote(ShippingQuoteContext $context, ShippingProvider $providerRow): ShippingQuoteResult
    {
        if (! $context->agentId) {
            throw new ShippingQuoteException('RajaOngkir requires an agen context to resolve credentials.');
        }

        $config = AgentShippingProviderConfig::query()
            ->where('agent_id', $context->agentId)->where('shipping_provider_id', $providerRow->id)
            ->value('config') ?? [];
        $apiKey = $config['api_key'] ?? null;
        $originCityId = $config['origin_city_id'] ?? null;
        $couriers = $config['couriers'] ?? [];

        if (! $apiKey || ! $originCityId || empty($couriers)) {
            throw new ShippingQuoteException('RajaOngkir is not fully configured (api_key/origin_city_id/couriers).');
        }

        if (! $context->destRegencyId) {
            throw new ShippingQuoteException('No destination regency to resolve a RajaOngkir city id from.');
        }

        $destinationCityId = Regency::query()->where('id', $context->destRegencyId)->value('rajaongkir_city_id');

        if (! $destinationCityId) {
            throw new ShippingQuoteException("Regency {$context->destRegencyId} has no rajaongkir_city_id mapping.");
        }

        $cacheKey = "rajaongkir:cost:{$originCityId}:{$destinationCityId}:{$context->weightGrams}:".implode(',', $couriers);

        // Rates don't move minute-to-minute — a short cache avoids paying for
        // a fresh API call on every quote/order for the same route+weight,
        // without staying stale long enough to matter for pricing accuracy.
        $best = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($config, $apiKey, $originCityId, $destinationCityId, $context, $couriers) {
            return $this->cheapestAcrossCouriers($config, $apiKey, $originCityId, $destinationCityId, $context->weightGrams, $couriers);
        });

        if ($best === null) {
            throw new ShippingQuoteException('RajaOngkir returned no usable cost for this route.');
        }

        return new ShippingQuoteResult(
            cost: (float) $best['value'],
            distanceKm: null,
            ratePerKm: null,
            providerCode: 'rajaongkir',
            meta: ['courier' => $best['courier'], 'service' => $best['service'], 'etd' => $best['etd'] ?? null],
        );
    }

    /** @return array{value: float, courier: string, service: string, etd: ?string}|null */
    private function cheapestAcrossCouriers(array $config, string $apiKey, string $originCityId, string $destinationCityId, int $weightGrams, array $couriers): ?array
    {
        $baseUrl = $this->baseUrlFor($config['account_type'] ?? 'starter');
        $best = null;

        foreach ($couriers as $courier) {
            try {
                $response = Http::withHeaders(['key' => $apiKey])
                    ->asForm()
                    ->timeout(8)
                    ->post("{$baseUrl}/cost", [
                        'origin' => $originCityId,
                        'destination' => $destinationCityId,
                        'weight' => max(1, $weightGrams),
                        'courier' => $courier,
                    ]);

                if (! $response->successful()) {
                    Log::warning('shipping.rajaongkir_http_error', ['status' => $response->status(), 'courier' => $courier]);

                    continue;
                }

                $results = $response->json('rajaongkir.results.0.costs', []);

                foreach ($results as $service) {
                    $value = (float) ($service['cost'][0]['value'] ?? 0);

                    if ($value > 0 && ($best === null || $value < $best['value'])) {
                        $best = [
                            'value' => $value,
                            'courier' => $courier,
                            'service' => $service['service'] ?? '-',
                            'etd' => $service['cost'][0]['etd'] ?? null,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('shipping.rajaongkir_request_failed', ['courier' => $courier, 'error' => $e->getMessage()]);
            }
        }

        return $best;
    }

    private function baseUrlFor(string $accountType): string
    {
        return match ($accountType) {
            'pro' => 'https://pro.rajaongkir.com/api',
            'basic' => 'https://api.rajaongkir.com/basic',
            default => 'https://api.rajaongkir.com/starter',
        };
    }
}
