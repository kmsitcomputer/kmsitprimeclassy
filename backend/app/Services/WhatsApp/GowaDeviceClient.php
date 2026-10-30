<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Device-slot management on the central GOWA server. Endpoints are exactly those of the project's OpenAPI (docs/openapi.yaml):
 *   POST   /devices {device_id}                 create a slot
 *   GET    /devices/{id}/status                 -> results.is_connected / is_logged_in
 *   GET    /devices/{id}                        -> results.phone_number (shown masked only), state
 *   GET    /devices/{id}/login                  -> results.qr_link, qr_duration     (QR pairing)
 *   POST   /devices/{id}/login/code?phone=      -> results.pair_code                (pairing code — supported by v9)
 *   POST   /devices/{id}/reconnect | /logout    keep the slot
 *   DELETE /devices/{id}                        remove the slot
 * The Basic-auth credential never leaves this class; callers only receive plain arrays. Every call is bounded by the console timeout and
 * never retried here. Every method returns ['ok'=>bool, 'error'=>?string, ...] and never throws.
 */
class GowaDeviceClient
{
    /**
     * The QR image is served from one of these paths by GOWA; anything else is refused (no arbitrary fetch on behalf of a client).
     * Verified LIVE against sapakita/gowa:v9.6.0: the image lives under `/statics/qrcode/` (its OpenAPI text says `/statics/images/qrcode/`).
     */
    private const QR_PATH_PREFIXES = ['/statics/qrcode/', '/statics/images/qrcode/'];

    public function __construct(private readonly GowaClient $gowa) {}

    /** @return array{ok:bool, error:?string} */
    public function create(string $deviceId): array
    {
        return $this->call(fn (PendingRequest $h) => $h->post('/devices', ['device_id' => $deviceId]), okOn: [409]); // 409/exists = already there
    }

    /** @return array{ok:bool, error:?string, connected:bool, logged_in:bool, exists:bool} */
    public function status(string $deviceId): array
    {
        $r = $this->call(fn (PendingRequest $h) => $h->get('/devices/'.rawurlencode($deviceId).'/status'), keep404: true);
        if (! $r['ok']) {
            return $r + ['connected' => false, 'logged_in' => false, 'exists' => $r['error'] !== 'not_found'];
        }
        $res = (array) ($r['json']['results'] ?? []);

        return ['ok' => true, 'error' => null, 'connected' => (bool) ($res['is_connected'] ?? false), 'logged_in' => (bool) ($res['is_logged_in'] ?? false), 'exists' => true];
    }

    /** WhatsApp number of the paired account, digits only (the caller masks it). */
    public function phoneNumber(string $deviceId): ?string
    {
        $r = $this->call(fn (PendingRequest $h) => $h->get('/devices/'.rawurlencode($deviceId)));
        $raw = (string) ($r['json']['results']['phone_number'] ?? $r['json']['results']['jid'] ?? '');
        $digits = preg_replace('/\D+/', '', explode('@', explode(':', $raw)[0])[0]);

        return $r['ok'] && $digits !== '' ? $digits : null;
    }

    /** @return array{ok:bool, error:?string, image:?string, duration:?int} `image` is a data: URI built by the server (never GOWA's own URL) */
    public function qr(string $deviceId): array
    {
        $r = $this->call(fn (PendingRequest $h) => $h->get('/devices/'.rawurlencode($deviceId).'/login'));
        if (! $r['ok']) {
            return ['ok' => false, 'error' => $r['error'], 'image' => null, 'duration' => null];
        }

        $link = (string) ($r['json']['results']['qr_link'] ?? '');
        $path = (string) (parse_url($link, PHP_URL_PATH) ?? '');
        // Only the image path is used; the host in the link is whatever GOWA believes its own address is (often localhost).
        if (! collect(self::QR_PATH_PREFIXES)->contains(fn (string $p) => str_starts_with($path, $p)) || str_contains($path, '..') || ! str_ends_with($path, '.png')) {
            return ['ok' => false, 'error' => 'qr_unavailable', 'image' => null, 'duration' => null];
        }

        $http = $this->gowa->request($deviceId);
        try {
            $img = $http?->get($path);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'unreachable', 'image' => null, 'duration' => null];
        }
        if (! $img || ! $img->successful() || $img->body() === '') {
            return ['ok' => false, 'error' => 'qr_unavailable', 'image' => null, 'duration' => null];
        }

        $mime = str_starts_with((string) $img->header('Content-Type'), 'image/') ? strtok((string) $img->header('Content-Type'), ';') : 'image/png';

        return ['ok' => true, 'error' => null, 'image' => 'data:'.$mime.';base64,'.base64_encode($img->body()), 'duration' => isset($r['json']['results']['qr_duration']) ? (int) $r['json']['results']['qr_duration'] : null];
    }

    /** @return array{ok:bool, error:?string, code:?string} */
    public function pairCode(string $deviceId, string $msisdn): array
    {
        $r = $this->call(fn (PendingRequest $h) => $h->post('/devices/'.rawurlencode($deviceId).'/login/code?phone='.rawurlencode($msisdn)));

        return ['ok' => $r['ok'], 'error' => $r['error'], 'code' => $r['ok'] ? (string) ($r['json']['results']['pair_code'] ?? '') ?: null : null];
    }

    /** @return array{ok:bool, error:?string} */
    public function reconnect(string $deviceId): array
    {
        return $this->call(fn (PendingRequest $h) => $h->post('/devices/'.rawurlencode($deviceId).'/reconnect'));
    }

    /** @return array{ok:bool, error:?string} */
    public function logout(string $deviceId): array
    {
        $r = $this->call(fn (PendingRequest $h) => $h->post('/devices/'.rawurlencode($deviceId).'/logout'), okOn: [404]);

        return ['ok' => $r['ok'] || $r['error'] === 'not_found', 'error' => $r['ok'] || $r['error'] === 'not_found' ? null : $r['error']];
    }

    /** @return array{ok:bool, error:?string} */
    public function delete(string $deviceId): array
    {
        $r = $this->call(fn (PendingRequest $h) => $h->delete('/devices/'.rawurlencode($deviceId)), okOn: [404]);

        return ['ok' => $r['ok'] || $r['error'] === 'not_found', 'error' => $r['ok'] || $r['error'] === 'not_found' ? null : $r['error']];
    }

    /**
     * @param  \Closure(PendingRequest): Response  $do
     * @param  array<int,int>  $okOn  HTTP statuses that count as success for this operation
     * @return array{ok:bool, error:?string, json:array<string,mixed>}
     */
    private function call(\Closure $do, array $okOn = [], bool $keep404 = false): array
    {
        $http = $this->gowa->request(null);
        if (! $http) {
            return ['ok' => false, 'error' => 'not_configured', 'json' => []];
        }

        try {
            $res = $do($http);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return ['ok' => false, 'error' => 'unreachable', 'json' => []];
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'gateway_error', 'json' => []];
        }

        $json = (array) ($res->json() ?? []);
        if ($res->successful() || in_array($res->status(), $okOn, true)) {
            return ['ok' => true, 'error' => null, 'json' => $json];
        }
        // GOWA v9.6 answers a removed/unknown device with HTTP 500 "device <id> not found" (verified live), not 404.
        $notFound = $res->status() === 404 || ($res->status() >= 500 && preg_match('/\bnot found\b/i', (string) ($json['message'] ?? '')));
        $error = match (true) {
            in_array($res->status(), [401, 403], true) => 'unauthorized',
            $notFound => 'not_found',
            default => 'gateway_error',
        };

        return ['ok' => false, 'error' => $error, 'json' => $json];
    }
}
