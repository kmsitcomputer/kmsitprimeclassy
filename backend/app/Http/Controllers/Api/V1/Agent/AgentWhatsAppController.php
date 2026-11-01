<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\Logging\ActivityLogger;
use App\Services\WhatsApp\AgentDeviceService;
use App\Services\WhatsApp\AgentWhatsAppSettingsService;
use App\Services\WhatsApp\MessageTemplates;
use App\Services\WhatsApp\WhatsAppDelivery;
use App\Services\WhatsApp\WhatsAppScope;
use App\Services\WhatsApp\WhatsAppSettings;
use App\Support\WhatsAppNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * WhatsApp management for ONE Agen's device, events/templates and test numbers (route group `role:agen,admin`).
 *
 * The target Agen is NEVER taken from the client: WhatsAppScope resolves it from the signed-in user (an Agen → itself, an Admin →
 * its branch Agen). A client `agent_id` is only accepted when it names that same authorised Agen, otherwise 403. Super Admin and
 * every other role are refused at the route and again in the scope resolver. Audit entries name actor and target separately.
 * No GOWA credential, QR source URL or full number is ever returned.
 */
class AgentWhatsAppController extends Controller
{
    private const TEST_PER_MINUTE = 3;

    private const TEST_PER_DAY = 20;

    public function __construct(
        private readonly AgentDeviceService $devices,
        private readonly WhatsAppScope $scope,
        private readonly AgentWhatsAppSettingsService $agentSettings,
        private readonly WhatsAppDelivery $delivery,
        private readonly WhatsAppSettings $settings,
    ) {}

    /** Which Agen the signed-in user may manage (one entry for an Agen or an Admin) — drives the selector in the UI. */
    public function scope(Request $request)
    {
        return $this->ok(['agents' => $this->scope->authorised($request->user())]);
    }

    public function show(Request $request)
    {
        [$actor, $agent] = $this->resolve($request);

        return $this->ok($this->devicesFor($actor)->status($agent) + ['agent' => ['id' => $agent->id, 'name' => $agent->name]]);
    }

    public function createDevice(Request $request)
    {
        [$actor, $agent] = $this->resolve($request);
        $svc = $this->devicesFor($actor);
        $svc->ensure($agent);

        return $this->created($svc->status($agent));
    }

    public function qr(Request $request)
    {
        [$actor, $agent] = $this->resolve($request);

        return $this->ok($this->devicesFor($actor)->startQrPairing($agent));
    }

    public function pairCode(Request $request)
    {
        [$actor, $agent] = $this->resolve($request);
        $data = $request->validate(['phone' => ['required', 'string', 'max:20']]);

        return $this->ok($this->devicesFor($actor)->startCodePairing($agent, $data['phone']));
    }

    public function reconnect(Request $request)
    {
        [$actor, $agent] = $this->resolve($request);

        return $this->ok(['device' => $this->devicesFor($actor)->reconnect($agent)]);
    }

    public function logout(Request $request)
    {
        [$actor, $agent] = $this->resolve($request);

        return $this->ok(['device' => $this->devicesFor($actor)->logout($agent)]);
    }

    public function replace(Request $request)
    {
        [$actor, $agent] = $this->resolve($request);
        // Replacing drops the paired session: it has to be a deliberate act, not an accidental click.
        $request->validate(['confirm' => ['required', 'accepted']]);

        return $this->ok(['device' => $this->devicesFor($actor)->replace($agent)]);
    }

    /** The target Agen's delivery history (masked recipient, no message body). */
    public function messages(Request $request)
    {
        [, $agent] = $this->resolve($request);
        $rows = WhatsAppMessage::query()
            ->where('agent_id', $agent->id)
            ->latest('id')->limit(50)->get()
            ->map(fn (WhatsAppMessage $m) => [
                'id' => $m->id, 'purpose' => $m->purpose, 'event' => $m->event, 'status' => $m->status,
                'recipient' => WhatsAppNumber::mask($m->recipient_phone), 'attempts' => $m->attempts,
                'skip_reason' => $m->skip_reason, 'error_class' => $m->error_class,
                'created_at' => $m->created_at, 'sent_at' => $m->sent_at,
            ]);

        return $this->ok($rows);
    }

    /* ---------------------------------------------------------------- events / templates */

    public function events(Request $request)
    {
        [, $agent] = $this->resolve($request);

        return $this->ok($this->agentSettings->events($agent));
    }

    public function updateEvent(Request $request, string $event)
    {
        [$actor, $agent] = $this->resolve($request);
        $data = $request->validate(['enabled' => ['sometimes', 'boolean'], 'template' => ['sometimes', 'string', 'max:2000']]);

        return $this->ok($this->agentSettings->update($actor, $agent, $event, $data), __('messages.whatsapp.saved'));
    }

    public function resetEventTemplate(Request $request, string $event)
    {
        [$actor, $agent] = $this->resolve($request);

        return $this->ok($this->agentSettings->resetTemplate($actor, $agent, $event), __('messages.whatsapp.saved'));
    }

    public function previewEvent(Request $request, string $event)
    {
        $this->resolve($request);
        $data = $request->validate(['template' => ['required', 'string', 'max:2000']]);

        return $this->ok(['preview' => $this->agentSettings->preview($event, $data['template'])]);
    }

    /* ---------------------------------------------------------------- test numbers + test-send */

    public function testNumbers(Request $request)
    {
        [, $agent] = $this->resolve($request);

        return $this->ok(['numbers' => $this->agentSettings->testNumbers($agent), 'max' => AgentWhatsAppSettingsService::MAX_TEST_NUMBERS]);
    }

    public function addTestNumber(Request $request)
    {
        [$actor, $agent] = $this->resolve($request);
        $data = $request->validate(['number' => ['required', 'string', 'max:20'], 'confirm_authorized' => ['required', 'accepted']]);

        return $this->ok(['numbers' => $this->agentSettings->registerTestNumber($actor, $agent, $data['number']), 'max' => AgentWhatsAppSettingsService::MAX_TEST_NUMBERS], __('messages.whatsapp.test_number_registered'));
    }

    public function removeTestNumber(Request $request, int $id)
    {
        [$actor, $agent] = $this->resolve($request);

        return $this->ok(['numbers' => $this->agentSettings->removeTestNumber($actor, $agent, $id), 'max' => AgentWhatsAppSettingsService::MAX_TEST_NUMBERS]);
    }

    /**
     * A REAL message from the target Agen's OWN paired slot to one of ITS registered test numbers. Every gate fails closed and none
     * falls back to the console default device: global switch → gateway → allow-listed destination → paired slot → rate limit.
     */
    public function testSend(Request $request)
    {
        [$actor, $agent] = $this->resolve($request);
        $data = $request->validate(['number' => ['required', 'string', 'max:20'], 'message' => ['nullable', 'string', 'max:300']]);

        if (! $this->settings->reload()->isConfigured()) {
            throw new ApiException(__('messages.whatsapp.test_gateway_off'), 409, ['code' => 'whatsapp_unavailable']);
        }

        $msisdn = WhatsAppNumber::msisdn($data['number']);
        if ($msisdn === null || ! $this->agentSettings->isAllowedTestNumber($agent, $msisdn)) {
            $key = $this->agentSettings->testNumbers($agent) === [] ? 'no_test_numbers' : 'number_not_allowed';

            throw new ApiException(__("messages.whatsapp.$key"), 422, ['number' => [__("messages.whatsapp.$key")]]);
        }

        if ($this->devices->sendingDeviceId($agent->id) === null) {
            throw new ApiException(__('messages.whatsapp.test_device_not_ready'), 409, ['code' => 'whatsapp_device_not_ready']);
        }

        $minute = "wa-agent-test-min:{$agent->id}";
        $day = "wa-agent-test-day:{$agent->id}";
        // Counted per TARGET Agen (server-resolved), so Agen + Admin share one budget and a new session / forged agent_id cannot reset it.
        // hit() increments atomically and returns the new count, so two concurrent requests cannot both squeeze under the limit.
        if (RateLimiter::hit($minute, 60) > self::TEST_PER_MINUTE | /* non-short-circuit on purpose: both counters always advance */ RateLimiter::hit($day, 86400) > self::TEST_PER_DAY) {
            throw new ApiException(__('messages.whatsapp.test_send_limited'), 429);
        }

        $text = trim((string) ($data['message'] ?? '')) !== '' ? (string) MessageTemplates::clean($data['message']) : __('messages.whatsapp.test_text');
        $message = $this->delivery->sendAgentTest($actor, $agent->id, $msisdn, $text);

        ActivityLogger::log($actor->id, $message, 'whatsapp.agent_test.sent', null, [
            'actor_id' => $actor->id, 'target_agent_id' => $agent->id, 'recipient' => WhatsAppNumber::mask($msisdn), 'status' => $message->status,
        ]);

        return $this->ok([
            'id' => $message->id, 'status' => $message->status, 'recipient' => WhatsAppNumber::mask($msisdn),
            'skip_reason' => $message->skip_reason, 'error_class' => $message->error_class,
            'sender_suffix' => $message->sender_device_id ? substr($message->sender_device_id, -6) : null,
        ], __('messages.whatsapp.test_done'));
    }

    /* ---------------------------------------------------------------- internals */

    /** @return array{0: User, 1: User} [actor, target Agen] — the target is derived server-side, see class docblock. */
    private function resolve(Request $request): array
    {
        $actor = $request->user();

        return [$actor, $this->scope->resolve($actor, $request->input('agent_id', $request->query('agent_id')))];
    }

    private function devicesFor(User $actor): AgentDeviceService
    {
        return $this->devices->actingAs($actor);
    }
}
