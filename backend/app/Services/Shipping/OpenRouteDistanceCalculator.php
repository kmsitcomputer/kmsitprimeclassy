<?php

namespace App\Services\Shipping;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Real road-distance via OpenRouteService's Directions API (driving-car
 * profile). Reads its API key/base URL from $config when given (Super
 * Admin's DB-stored ShippingProvider config takes precedence — see
 * ShippingProviders\OpenRouteProvider); falls back to `services.openroute.*`
 * (OPENROUTE_API_KEY env) when $config is omitted, for backward
 * compatibility with anything still calling this directly. Any HTTP failure
 * (timeout, quota, invalid coordinates) falls back to the Haversine
 * straight-line distance rather than failing checkout outright.
 */
class OpenRouteDistanceCalculator implements DistanceCalculatorInterface
{
    public function __construct(private readonly HaversineDistanceCalculator $fallback) {}

    public function calculate(float $originLat, float $originLng, float $destLat, float $destLng, ?array $config = null): float
    {
        $baseUrl = rtrim((string) ($config['base_url'] ?? config('services.openroute.base_url')), '/');
        $key = $config['api_key'] ?? config('services.openroute.key');

        try {
            $response = Http::withHeaders(['Authorization' => $key])
                ->timeout(5)
                ->post("{$baseUrl}/v2/directions/driving-car", [
                    'coordinates' => [
                        [$originLng, $originLat],
                        [$destLng, $destLat],
                    ],
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException('OpenRouteService returned '.$response->status());
            }

            $meters = $response->json('routes.0.summary.distance');

            if (! is_numeric($meters)) {
                throw new \RuntimeException('OpenRouteService response missing routes.0.summary.distance');
            }

            return round($meters / 1000, 2);
        } catch (\Throwable $e) {
            Log::warning('shipping.openroute_failed_fallback_haversine', ['error' => $e->getMessage()]);

            return $this->fallback->calculate($originLat, $originLng, $destLat, $destLng);
        }
    }
}
