<?php

namespace App\Services\WhatsApp;

use App\Exceptions\ApiException;
use App\Models\AgentWhatsAppDevice;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use App\Support\WhatsAppNumber;
use Illuminate\Database\QueryException;

/**
 * WhatsApp device lifecycle PER AGENT on the one central GOWA server (Owner directive 2026-10-29).
 *
 *  - An Agent manages ONLY its own slot; the caller is always resolved to `agent_id` server-side, nothing the client sends selects a
 *    device or an Agent. Super Admin / Admin / other roles never reach these methods (route middleware `role:agen`).
 *  - Exactly one active slot per Agent (unique `agent_id`). Replacement is explicit and audited.
 *  - Sending uses the Agent's own slot only; there is NO fallback to another Agent's or the console's default device.
 *  - The GOWA credential, the QR source URL and the full WhatsApp number never reach the browser.
 */
class AgentDeviceService
{
    public function __construct(
        private readonly GowaDeviceClient $gowa,
        private readonly WhatsAppSettings $settings,
    ) {}

    /* ---------------------------------------------------------------- read side (also used by the sender) */

    public function forAgent(?int $agentId): ?AgentWhatsAppDevice
    {
        if (! $agentId || ! AgentWhatsAppDevice::tableReady()) {
            return null;
        }

        return AgentWhatsAppDevice::query()->where('agent_id', $agentId)->first();
    }

    /**
     * The slot a message of this Agent must be sent from, or null when the Agent has none that was ever paired. The gateway decides
     * whether a paired slot is currently connected (a disconnected one is a definite "not sent" and follows the normal retry rules).
     */
    public function sendingDeviceId(?int $agentId): ?string
    {
        $device = $this->forAgent($agentId);

        return $device && $device->hasBeenPaired() ? $device->device_id : null;
    }

    /** Can this Agent's WhatsApp number verification / OTP be delivered right now? */
    public function canDeliverFor(?int $agentId): bool
    {
        return $this->sendingDeviceId($agentId) !== null && $this->settings->reload()->isConfigured();
    }

    /* ---------------------------------------------------------------- Agent actions */

    /**
     * Status for the Agent dashboard. `refresh` asks the gateway (read-only) and updates the cached state.
     *
     * @return array<string,mixed>
     */
    public function status(User $agent, bool $refresh = true): array
    {
        $this->settings->reload();
        $device = $this->forAgent($agent->id);

        if ($device && $refresh && $this->settings->hasCredentials()) {
            $device = $this->refresh($device);
        }

        return [
            // The Super Admin's global switch and the connection completeness — the Agent only learns whether WhatsApp is available.
            'global_enabled' => $this->settings->enabled(),
            'gateway_ready' => $this->settings->hasCredentials(),
            'table_ready' => AgentWhatsAppDevice::tableReady(),
            'capabilities' => ['qr' => true, 'pair_code' => true],
            'device' => $device ? $this->present($device) : null,
        ];
    }

    public function ensure(User $agent): AgentWhatsAppDevice
    {
        $this->assertManageable();

        $device = $this->forAgent($agent->id);
        if ($device) {
            return $device;
        }

        $deviceId = $this->newDeviceId($agent->id);
        $created = $this->gowa->create($deviceId);
        if (! $created['ok']) {
            throw $this->gatewayError($created['error']);
        }

        try {
            $device = AgentWhatsAppDevice::create(['agent_id' => $agent->id, 'device_id' => $deviceId, 'state' => AgentWhatsAppDevice::NOT_PAIRED]);
        } catch (QueryException) {
            // Lost a race with a second click: the winner owns the single slot; drop the surplus slot we just made on the gateway.
            $this->gowa->delete($deviceId);
            $device = $this->forAgent($agent->id) ?? throw new ApiException(__('messages.whatsapp.device_gateway_error'), 502);
        }

        ActivityLogger::log($agent->id, $device, 'whatsapp.device.created', null, ['agent_id' => $agent->id]);

        return $device;
    }

    /** @return array{image:string, expires_in:?int, device:array<string,mixed>} */
    public function startQrPairing(User $agent): array
    {
        $device = $this->ensure($agent);
        $this->assertNotAlreadyConnected($device);

        $qr = $this->gowa->qr($device->device_id);
        if (! $qr['ok'] || ! $qr['image']) {
            throw $this->gatewayError($qr['error']);
        }

        $device->forceFill(['state' => AgentWhatsAppDevice::PAIRING])->save();
        ActivityLogger::log($agent->id, $device, 'whatsapp.device.pairing_started', null, ['method' => 'qr']);

        return ['image' => $qr['image'], 'expires_in' => $qr['duration'], 'device' => $this->present($device)];
    }

    /** @return array{code:string, device:array<string,mixed>} */
    public function startCodePairing(User $agent, string $phone): array
    {
        $msisdn = WhatsAppNumber::msisdn($phone);
        if ($msisdn === null) {
            throw new ApiException(__('messages.whatsapp.invalid_number'), 422, ['phone' => [__('messages.whatsapp.invalid_number')]]);
        }

        $device = $this->ensure($agent);
        $this->assertNotAlreadyConnected($device);

        $res = $this->gowa->pairCode($device->device_id, $msisdn);
        if (! $res['ok'] || ! $res['code']) {
            throw $this->gatewayError($res['error']);
        }

        $device->forceFill(['state' => AgentWhatsAppDevice::PAIRING])->save();
        // The number being paired is NOT recorded in the audit trail in clear; only the method.
        ActivityLogger::log($agent->id, $device, 'whatsapp.device.pairing_started', null, ['method' => 'code']);

        return ['code' => $res['code'], 'device' => $this->present($device)];
    }

    /** @return array<string,mixed> */
    public function reconnect(User $agent): array
    {
        $device = $this->requireDevice($agent);
        $this->assertManageable();

        $res = $this->gowa->reconnect($device->device_id);
        if (! $res['ok']) {
            throw $this->gatewayError($res['error']);
        }
        ActivityLogger::log($agent->id, $device, 'whatsapp.device.reconnect_requested');

        return $this->present($this->refresh($device));
    }

    /** Logs the WhatsApp account out but keeps the slot (re-pairable under the same id). */
    public function logout(User $agent): array
    {
        $device = $this->requireDevice($agent);
        $this->assertManageable();

        $res = $this->gowa->logout($device->device_id);
        if (! $res['ok']) {
            throw $this->gatewayError($res['error']);
        }
        $device->forceFill(['state' => AgentWhatsAppDevice::NOT_PAIRED, 'paired_at' => null, 'phone_masked' => null, 'last_checked_at' => now()])->save();
        ActivityLogger::log($agent->id, $device, 'whatsapp.device.logged_out');

        return $this->present($device);
    }

    /**
     * Controlled device replacement: the old slot is logged out and removed on the gateway, a NEW slot id is issued to the same Agent
     * (still exactly one), and the Agent must pair again. Messages queued meanwhile are never sent from the old or a foreign slot:
     * until the new one is paired they are skipped (`agent_device_not_ready`).
     */
    public function replace(User $agent): array
    {
        $device = $this->requireDevice($agent);
        $this->assertManageable();

        $old = $device->device_id;
        $new = $this->newDeviceId($agent->id);

        $created = $this->gowa->create($new); // make the new slot first: a failure leaves the old one untouched
        if (! $created['ok']) {
            throw $this->gatewayError($created['error']);
        }

        $this->gowa->logout($old);
        $this->gowa->delete($old);

        $device->forceFill([
            'device_id' => $new, 'state' => AgentWhatsAppDevice::NOT_PAIRED, 'phone_masked' => null, 'paired_at' => null,
            'last_checked_at' => now(), 'replaced_count' => $device->replaced_count + 1,
        ])->save();
        ActivityLogger::log($agent->id, $device, 'whatsapp.device.replaced', null, ['replaced_count' => $device->replaced_count]);

        return $this->present($device);
    }

    /* ---------------------------------------------------------------- internals */

    /** Reads the gateway (read-only) and caches the observed state. Never throws; an unreachable gateway keeps the last state. */
    public function refresh(AgentWhatsAppDevice $device): AgentWhatsAppDevice
    {
        $s = $this->gowa->status($device->device_id);

        if (! $s['ok']) {
            // A slot the gateway no longer knows must not be trusted as paired.
            if ($s['error'] === 'not_found' && $device->state !== AgentWhatsAppDevice::NOT_PAIRED) {
                $device->forceFill(['state' => AgentWhatsAppDevice::NOT_PAIRED, 'paired_at' => null, 'last_checked_at' => now()])->save();
            }

            return $device;
        }

        if ($s['connected'] && $s['logged_in']) {
            $fields = ['state' => AgentWhatsAppDevice::CONNECTED, 'last_checked_at' => now()];
            if ($device->paired_at === null) {
                $fields['paired_at'] = now();
                $number = $this->gowa->phoneNumber($device->device_id);
                $fields['phone_masked'] = $number ? WhatsAppNumber::mask($number) : null;
                ActivityLogger::log($device->agent_id, $device, 'whatsapp.device.paired');
            }
            $device->forceFill($fields)->save();

            return $device;
        }

        $next = $device->state === AgentWhatsAppDevice::PAIRING ? AgentWhatsAppDevice::PAIRING
            : ($device->paired_at !== null ? AgentWhatsAppDevice::DISCONNECTED : AgentWhatsAppDevice::NOT_PAIRED);
        $device->forceFill(['state' => $next, 'last_checked_at' => now()])->save();

        return $device;
    }

    /** @return array<string,mixed> */
    public function present(AgentWhatsAppDevice $device): array
    {
        return [
            'state' => $device->state, 'phone_masked' => $device->phone_masked,
            'paired_at' => $device->paired_at, 'last_checked_at' => $device->last_checked_at, 'replaced_count' => $device->replaced_count,
            // The slot id is operational, not secret, but there is no reason for the browser to hold it.
        ];
    }

    private function requireDevice(User $agent): AgentWhatsAppDevice
    {
        return $this->forAgent($agent->id) ?? throw new ApiException(__('messages.whatsapp.device_not_found'), 404);
    }

    private function assertManageable(): void
    {
        $this->settings->reload();
        if (! AgentWhatsAppDevice::tableReady()) {
            throw new ApiException(__('messages.whatsapp.migration_pending'), 503);
        }
        if (! $this->settings->hasCredentials()) {
            throw new ApiException(__('messages.whatsapp.device_gateway_unconfigured'), 409, ['code' => 'whatsapp_gateway_unconfigured']);
        }
    }

    private function assertNotAlreadyConnected(AgentWhatsAppDevice $device): void
    {
        $this->refresh($device);
        if ($device->fresh()->state === AgentWhatsAppDevice::CONNECTED) {
            throw new ApiException(__('messages.whatsapp.device_already_connected'), 409, ['code' => 'whatsapp_device_connected']);
        }
    }

    private function gatewayError(?string $error): ApiException
    {
        return match ($error) {
            'not_configured' => new ApiException(__('messages.whatsapp.device_gateway_unconfigured'), 409, ['code' => 'whatsapp_gateway_unconfigured']),
            'unreachable' => new ApiException(__('messages.whatsapp.device_gateway_unreachable'), 503, ['code' => 'whatsapp_gateway_unreachable']),
            default => new ApiException(__('messages.whatsapp.device_gateway_error'), 502, ['code' => 'whatsapp_gateway_error']),
        };
    }

    private function newDeviceId(int $agentId): string
    {
        return 'pc-agent-'.$agentId.'-'.bin2hex(random_bytes(4));
    }
}
