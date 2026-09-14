<?php

namespace App\Services\Shipping;

use App\DataTransferObjects\OpenRouteResult;
use App\Exceptions\ShippingQuoteException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Single origin-to-destination road route through ORS Directions V2. */
class OpenRouteDistanceCalculator
{
    public function route(float $originLat, float $originLng, float $destLat, float $destLng, array $config): OpenRouteResult
    {
        $this->validateCoordinates($originLat, $originLng, $destLat, $destLng);

        $baseUrl = rtrim((string) config('services.openroute.base_url'), '/');
        $profile = (string) ($config['profile'] ?? config('services.openroute.profile'));
        $apiKey = (string) ($config['api_key'] ?? config('services.openroute.key'));
        $started = microtime(true);

        if ($apiKey === '') {
            throw new ShippingQuoteException('OpenRouteService API key is missing.');
        }

        try {
            $response = Http::withHeaders(['Authorization' => $apiKey])->acceptJson()->asJson()->timeout(12)
                ->post("{$baseUrl}/v2/directions/{$profile}/json", [
                    // ORS requires [longitude, latitude]. Database storage remains lat/lng.
                    'coordinates' => [[$originLng, $originLat], [$destLng, $destLat]],
                    'instructions' => false,
                ]);
        } catch (ConnectionException) {
            $this->log('failed', $profile, $originLat, $originLng, $destLat, $destLng, null, $started, 'connection_error');
            throw new ShippingQuoteException('OpenRouteService tidak dapat dihubungi. Coba lagi beberapa saat.');
        }

        if (! $response->successful()) {
            $error = match ($response->status()) {
                401, 403 => 'invalid_credential',
                429 => 'rate_limited',
                default => 'provider_error',
            };
            $this->log('failed', $profile, $originLat, $originLng, $destLat, $destLng, $response->status(), $started, $error);
            throw new ShippingQuoteException('OpenRouteService menolak permintaan rute. Periksa konfigurasi dan koordinat.');
        }

        $distanceMeters = $response->json('routes.0.summary.distance');
        $durationSeconds = $response->json('routes.0.summary.duration');
        if (! is_numeric($distanceMeters) || ! is_numeric($durationSeconds) || $distanceMeters < 0 || $durationSeconds < 0) {
            $this->log('failed', $profile, $originLat, $originLng, $destLat, $destLng, $response->status(), $started, 'invalid_response');
            throw new ShippingQuoteException('OpenRouteService tidak mengembalikan ringkasan rute yang valid.');
        }

        $result = new OpenRouteResult(
            distanceMeters: (float) $distanceMeters,
            distanceKm: round((float) $distanceMeters / 1000, 2),
            durationSeconds: (float) $durationSeconds,
            profile: $profile,
        );
        $this->log('success', $profile, $originLat, $originLng, $destLat, $destLng, $response->status(), $started, null, $result);

        return $result;
    }

    private function validateCoordinates(float $originLat, float $originLng, float $destLat, float $destLng): void
    {
        if ($originLat < -90 || $originLat > 90 || $destLat < -90 || $destLat > 90
            || $originLng < -180 || $originLng > 180 || $destLng < -180 || $destLng > 180) {
            throw new ShippingQuoteException('Koordinat pengiriman tidak valid.');
        }
    }

    private function log(string $status, string $profile, float $originLat, float $originLng, float $destLat, float $destLng, ?int $httpStatus, float $started, ?string $error, ?OpenRouteResult $result = null): void
    {
        Log::info('shipping.openrouteservice_route', [
            'provider' => 'openrouteservice', 'profile' => $profile,
            'origin' => [round($originLng, 5), round($originLat, 5)],
            'destination' => [round($destLng, 5), round($destLat, 5)],
            'distance_meters' => $result?->distanceMeters, 'duration_seconds' => $result?->durationSeconds,
            'status' => $status, 'http_status' => $httpStatus,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'error' => $error,
        ]);
    }
}
