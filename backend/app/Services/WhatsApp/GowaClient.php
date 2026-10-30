<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Transport to GOWA (go-whatsapp-web-multidevice v9). Endpoints are taken from the project's own OpenAPI
 * (`docs/openapi.yaml`) and verified against the running `sapakita/gowa:v9.6.0` image:
 *   GET  /health            public liveness
 *   GET  /app/status        device connection  -> results.is_connected / is_logged_in / device_id   (X-Device-Id)
 *   GET  /app/devices       registered devices
 *   POST /send/message      {phone, message}   -> results.message_id                                  (X-Device-Id)
 * Auth is HTTP Basic. The credentials/Authorization header are never logged, returned or stored by this class.
 *
 * Retries are NOT done here (no `->retry()`): the caller decides, using GatewayResult's duplicate-safety classes.
 */
class GowaClient
{
    public function __construct(private readonly WhatsAppSettings $settings) {}

    /**
     * Authenticated request builder for the central server. `$deviceId` selects the device slot (X-Device-Id); when null the
     * platform-level default device of the console is used — Agent traffic ALWAYS passes its own slot explicitly and never falls
     * back to that default (session isolation).
     */
    public function request(?string $deviceId = null): ?PendingRequest
    {
        $this->settings->reload(); // a queue worker lives for hours: never send with a stale credential/URL
        $base = $this->settings->baseUrl();
        $user = $this->settings->username();
        $pass = $this->settings->password();
        if (! $base || ! $user || ! $pass) {
            return null;
        }

        $req = Http::baseUrl($base)
            ->withBasicAuth($user, $pass)
            ->acceptJson()
            ->asJson()
            ->timeout($this->settings->timeout())
            ->connectTimeout(min(5, $this->settings->timeout()));

        $device = $deviceId ?: $this->settings->deviceId();

        return $device ? $req->withHeaders(['X-Device-Id' => $device]) : $req;
    }

    /**
     * Connection + device status for the dashboard. Never throws; `state` is one of
     * not_configured | unreachable | unauthorized | disconnected | connected | error.
     *
     * @return array{state:string, reachable:bool, connected:bool, logged_in:bool, devices:int, device_id:?string, detail:?string}
     */
    public function status(): array
    {
        $base = ['state' => 'not_configured', 'reachable' => false, 'connected' => false, 'logged_in' => false, 'devices' => 0, 'device_id' => null, 'detail' => null];
        $http = $this->request();
        if (! $http) {
            return $base;
        }

        try {
            $res = $http->get('/app/status');
        } catch (ConnectionException $e) {
            return [...$base, 'state' => 'unreachable', 'detail' => GatewayResult::sanitize($e->getMessage())];
        } catch (\Throwable $e) {
            return [...$base, 'state' => 'error', 'detail' => GatewayResult::sanitize($e->getMessage())];
        }

        if (in_array($res->status(), [401, 403], true)) {
            return [...$base, 'state' => 'unauthorized', 'reachable' => true, 'detail' => 'HTTP '.$res->status()];
        }
        if (! $res->successful()) {
            return [...$base, 'state' => 'error', 'reachable' => true, 'detail' => $this->detail($res)];
        }

        $r = (array) $res->json('results', []);
        $connected = (bool) ($r['is_connected'] ?? false);
        $loggedIn = (bool) ($r['is_logged_in'] ?? false);

        return [
            'state' => $connected && $loggedIn ? 'connected' : 'disconnected',
            'reachable' => true, 'connected' => $connected, 'logged_in' => $loggedIn,
            'devices' => $this->deviceCount($http),
            // The id is operational data; the WhatsApp number (jid) is deliberately NOT exposed.
            'device_id' => isset($r['device_id']) ? (string) $r['device_id'] : null,
            'detail' => null,
        ];
    }

    private function deviceCount(PendingRequest $http): int
    {
        try {
            $res = $http->get('/app/devices');

            return $res->successful() ? count((array) $res->json('results', [])) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Send ONE text message from `$deviceId` (an Agent's slot) or, when null, the console's default device. `$msisdn` = digits with country code. */
    public function sendText(string $msisdn, #[\SensitiveParameter] string $text, ?string $deviceId = null): GatewayResult
    {
        $http = $this->request($deviceId);
        if (! $http) {
            return GatewayResult::failed('not_configured', 'WhatsApp gateway is not configured.');
        }

        try {
            $res = $http->post('/send/message', ['phone' => $msisdn.'@s.whatsapp.net', 'message' => $text]);
        } catch (ConnectionException $e) {
            return $this->fromConnectionError($e);
        } catch (RequestException $e) {
            return GatewayResult::unknown('provider_error', $e->getMessage());
        } catch (\Throwable $e) {
            return GatewayResult::unknown('transport_error', $e->getMessage());
        }

        return $this->fromResponse($res);
    }

    /**
     * cURL 6 (resolve) / 7 (connect refused) / 35 (TLS handshake): nothing reached the provider => safe to retry.
     * Anything else (28 timeout, 52/56 empty/reset reply…): the request may have been processed => UNKNOWN.
     */
    private function fromConnectionError(ConnectionException $e): GatewayResult
    {
        $msg = $e->getMessage();
        if (preg_match('/cURL error (6|7|35)\b/', $msg) || preg_match('/Connection refused|Could not resolve host|Failed to connect/i', $msg)) {
            return GatewayResult::retry('unreachable', $msg);
        }

        return GatewayResult::unknown('timeout', $msg);
    }

    private function fromResponse(Response $res): GatewayResult
    {
        $status = $res->status();

        if ($res->successful()) {
            $code = strtoupper((string) $res->json('code', ''));
            if ($code === '' || $code === 'SUCCESS') {
                return GatewayResult::sent($res->json('results.message_id') ? (string) $res->json('results.message_id') : null, $status);
            }

            return GatewayResult::unknown('unexpected_response', 'Unexpected provider response: '.$code, $status);
        }

        $detail = $this->detail($res);

        if (in_array($status, [401, 403], true)) {
            return GatewayResult::failed('auth', 'Gateway rejected the credentials.', $status);
        }
        if ($status === 404) {
            return GatewayResult::failed('device_not_found', $detail, $status);
        }
        if ($status >= 400 && $status < 500) {
            return GatewayResult::failed('rejected', $detail, $status);
        }

        // 5xx. A disconnected/logged-out device is a definite "not sent"; any other server error proves nothing.
        if (preg_match('/not connected|not logged in|disconnected|device.*(not|no).*(found|ready)|login/i', $detail)) {
            return GatewayResult::retry('device_disconnected', $detail, $status);
        }

        return GatewayResult::unknown('provider_error', $detail, $status);
    }

    private function detail(Response $res): string
    {
        $msg = (string) ($res->json('message') ?? '');

        return GatewayResult::sanitize($msg !== '' ? 'HTTP '.$res->status().': '.$msg : 'HTTP '.$res->status());
    }
}
