<?php

namespace App\Services\Geocode;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Backend-only, user-triggered lookup. No retries or personal address text. */
class NominatimReverseGeocoder implements ReverseGeocoder
{
    public function reverse(float $latitude, float $longitude): ?array
    {
        if (! is_finite($latitude) || ! is_finite($longitude) || abs($latitude) > 90 || abs($longitude) > 180) {
            return null;
        }

        $lock = null;
        try {
            $base = rtrim((string) config('services.geocode.base_url'), '/');
            if (! filter_var($base, FILTER_VALIDATE_URL) || parse_url($base, PHP_URL_SCHEME) !== 'https') {
                return null;
            }
            $cache = Cache::store(config('services.geocode.cache_store'));
            $prefix = 'geocode:nominatim:'.hash('sha256', $base);
            $key = $prefix.':'.hash('sha256', sprintf('%.5f,%.5f', $latitude, $longitude));
            $cached = $cache->get($key);
            if (is_array($cached) && array_key_exists('result', $cached)) {
                return $cached['result'];
            }

            // Shared across users/processes. Busy/rate-limited requests fall back immediately,
            // never queue a burst, sleep, or retry the public provider.
            $timeout = max(1, min(10, (float) config('services.geocode.timeout', 4)));
            $lock = $cache->lock($prefix.':lock', 15);
            if (! $lock->get()) {
                return null;
            }
            $now = microtime(true);
            if ($now - (float) $cache->get($prefix.':last-request', 0) < max(1, (float) config('services.geocode.min_interval', 1))) {
                return null;
            }
            $cache->put($prefix.':last-request', $now, 60);
            $response = Http::acceptJson()
                ->withUserAgent((string) config('services.geocode.user_agent'))
                ->timeout($timeout)
                ->connectTimeout(min($timeout, max(1, (float) config('services.geocode.connect_timeout', 2))))
                ->withoutRedirecting()
                ->get($base.'/reverse', [
                    'lat' => $latitude, 'lon' => $longitude, 'format' => 'jsonv2',
                    'addressdetails' => 1, 'zoom' => 18, 'layer' => 'address', 'accept-language' => 'id',
                ]);
            $result = $response->successful() ? $this->normalizeAddress($response->json()) : null;
            $cache->put($key, ['result' => $result], $result ? 86400 : 60);

            return $result;
        } catch (Throwable) {
            // Includes connection/timeout, malformed JSON, cache/storage failure.
            // No credentials, coordinates or provider payload are written to logs.
            return null;
        } finally {
            try {
                $lock?->release();
            } catch (Throwable) {
                // Cache failure must not turn graceful provider fallback into an API error.
            }
        }
    }

    private function normalizeAddress(mixed $payload): ?array
    {
        if (! is_array($payload) || isset($payload['error']) || ! is_array($payload['address'] ?? null)) {
            return null;
        }
        $address = $payload['address'];
        if (($address['country_code'] ?? null) !== 'id') {
            return null;
        }
        $label = static function (array $keys) use ($address): ?string {
            foreach ($keys as $key) {
                if (isset($address[$key])) {
                    if (! is_string($address[$key]) || trim($address[$key]) === '' || mb_strlen($address[$key]) > 200) {
                        return null;
                    }

                    return trim($address[$key]);
                }
            }

            return null;
        };
        $result = [
            'province' => $label(['state']),
            'regency' => $label(['county', 'city', 'town']),
            'district' => $label(['city_district', 'municipality', 'district']),
            'village' => $label(['village', 'suburb', 'quarter']),
            'postal_code' => $label(['postcode']),
        ];
        if (! $result['province'] || ! $result['regency'] || ! $result['village']) {
            return null;
        }
        if ($result['postal_code'] !== null && ! preg_match('/^\d{5}$/', $result['postal_code'])) {
            $result['postal_code'] = null;
        }

        return $result;
    }
}
