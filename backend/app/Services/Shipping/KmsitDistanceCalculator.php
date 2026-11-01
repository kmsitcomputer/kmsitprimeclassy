<?php

namespace App\Services\Shipping;

use App\DataTransferObjects\OpenRouteResult;
use App\Exceptions\KmsitRouteException;
use App\Exceptions\ShippingQuoteException;
use App\Support\EndpointGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Single origin-to-destination road route through the KMSIT Routing GATEWAY (OpenAPI v1.1.0, packages/api-contracts/openapi.yaml):
 *
 *   GET {base}/api/v1/routes?profile=car|motorcycle&coordinates=lon,lat;lon,lat     header  X-API-Key: <service key>
 *   200 {request_id, profile, distance_m, duration_s, geometry, waypoints, warnings}
 *   4xx/5xx {error:{code,message,request_id}}  — VALIDATION_ERROR (incl. outside the Bandung coverage bbox), UNAUTHENTICATED/AUTH_FAILED,
 *   FORBIDDEN (key lacks routes:read), ROUTE_NOT_FOUND (NoRoute / cannot snap), RATE_LIMITED, UPSTREAM_UNAVAILABLE/TIMEOUT.
 *
 * This is NOT the native OSRM API and NOT Bearer auth. `base_url` is the gateway origin (a trailing /api/v1 is tolerated, never doubled).
 */
class KmsitDistanceCalculator
{
    public const PROFILES = ['car', 'motorcycle'];

    /** Profiles saved before the Gateway contract was known ('driving' = a car) keep working without a re-save. */
    public static function normalizeProfile(?string $profile): string
    {
        return match ($profile) {
            null, '', 'driving' => 'car',
            default => $profile,
        };
    }

    public function route(float $originLat, float $originLng, float $destLat, float $destLng, array $config): OpenRouteResult
    {
        $this->validateCoordinates($originLat, $originLng, $destLat, $destLng);

        $profile = self::normalizeProfile($config['profile'] ?? null);
        if (! in_array($profile, self::PROFILES, true)) {
            throw new KmsitRouteException('Profil rute KMSIT tidak valid.', 'invalid_profile');
        }
        try {
            $vetted = EndpointGuard::vet((string) ($config['base_url'] ?? ''));
        } catch (KmsitRouteException $e) {
            throw $e;
        } catch (ShippingQuoteException) {
            throw new KmsitRouteException('Endpoint KMSIT ditolak oleh kebijakan keamanan.', 'endpoint_blocked');
        }
        $token = (string) ($config['token'] ?? '');
        if ($token === '') {
            throw new KmsitRouteException('API key KMSIT belum diisi.', 'missing_credential');
        }
        $started = microtime(true);

        $origin = preg_replace('#/api/v1$#', '', $vetted['url']);
        // Gateway order is lon,lat (the application stores lat/lng); `;` separator per the contract.
        $coordinates = "{$originLng},{$originLat};{$destLng},{$destLat}";

        try {
            $response = Http::withOptions(EndpointGuard::pin($vetted))->acceptJson()->timeout(8)->connectTimeout(4)
                ->retry(2, 200, throw: false)->withHeaders(['X-API-Key' => $token])
                ->get("{$origin}/api/v1/routes", ['profile' => $profile, 'coordinates' => $coordinates]);
        } catch (ConnectionException) {
            $this->log('failed', $profile, null, $started, 'connection_error');
            throw new KmsitRouteException('KMSIT Routing Server tidak dapat dihubungi.', 'connection_error');
        }

        if (! $response->successful()) {
            $reason = $this->reasonFor($response->status(), $response->json('error.code'));
            $this->log('failed', $profile, $response->status(), $started, $reason, $response->json('error.request_id'));
            throw new KmsitRouteException('KMSIT Routing Server menolak atau tidak dapat memberi rute.', $reason, $response->status());
        }

        $distance = $response->json('distance_m');
        $duration = $response->json('duration_s');
        if (! is_numeric($distance) || ! is_numeric($duration) || ! is_finite((float) $distance) || ! is_finite((float) $duration) || $distance < 0 || $duration < 0) {
            $this->log('failed', $profile, $response->status(), $started, 'invalid_response');
            throw new KmsitRouteException('KMSIT Routing Server tidak mengembalikan rute yang valid.', 'invalid_response', $response->status());
        }

        $result = new OpenRouteResult((float) $distance, round((float) $distance / 1000, 2), (float) $duration, $profile);
        $this->log('success', $profile, $response->status(), $started, null, $response->json('request_id'));

        return $result;
    }

    /** Safe machine reason from the contract's error envelope; an unknown envelope falls back to the HTTP status (404 = wrong path). */
    private function reasonFor(int $status, mixed $code): string
    {
        return match (is_string($code) ? $code : null) {
            'ROUTE_NOT_FOUND' => 'route_not_found',
            'VALIDATION_ERROR' => 'validation_error',
            'UNAUTHENTICATED', 'AUTH_FAILED' => 'invalid_credential',
            'FORBIDDEN' => 'forbidden',
            'RATE_LIMITED' => 'rate_limited',
            'UPSTREAM_UNAVAILABLE', 'UPSTREAM_TIMEOUT' => 'upstream_unavailable',
            default => match ($status) {
                401 => 'invalid_credential', 403 => 'forbidden', 404 => 'not_found', 429 => 'rate_limited', 502, 503, 504 => 'upstream_unavailable',
                default => 'provider_error',
            },
        };
    }

    private function validateCoordinates(float $originLat, float $originLng, float $destLat, float $destLng): void
    {
        foreach ([$originLat, $originLng, $destLat, $destLng] as $v) {
            if (! is_finite($v)) {
                throw new ShippingQuoteException('Koordinat pengiriman tidak valid.');
            }
        }
        if (abs($originLat) > 90 || abs($destLat) > 90 || abs($originLng) > 180 || abs($destLng) > 180) {
            throw new ShippingQuoteException('Koordinat pengiriman tidak valid.');
        }
    }

    /** Never logs the endpoint URL, key or exact coordinates; the gateway's request_id is safe and aids support. */
    private function log(string $status, string $profile, ?int $http, float $started, ?string $error, mixed $requestId = null): void
    {
        Log::info('shipping.kmsit_route', [
            'provider' => 'kmsit', 'contract' => 'gateway-v1', 'profile' => $profile, 'status' => $status, 'http_status' => $http,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'error' => $error,
            'request_id' => is_string($requestId) ? substr($requestId, 0, 64) : null,
        ]);
    }
}
