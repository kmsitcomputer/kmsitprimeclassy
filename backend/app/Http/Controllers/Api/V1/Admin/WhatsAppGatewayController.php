<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateWhatsAppSettingsRequest;
use App\Models\WhatsAppMessage;
use App\Services\Logging\ActivityLogger;
use App\Services\WhatsApp\GowaClient;
use App\Services\WhatsApp\MessageTemplates;
use App\Services\WhatsApp\WhatsAppDelivery;
use App\Services\WhatsApp\WhatsAppEvents;
use App\Services\WhatsApp\WhatsAppSettings;
use App\Support\WhatsAppNumber;
use Illuminate\Http\Request;

/**
 * Super Admin WhatsApp Gateway console (route group `role:super_admin`). It configures PrimeClassy's OWN use of the
 * gateway only: it cannot administer a GOWA device (no QR login/logout/delete, no other app's sessions). No API here
 * ever returns the stored password or an OTP.
 */
class WhatsAppGatewayController extends Controller
{
    public function __construct(
        private readonly WhatsAppSettings $settings,
        private readonly GowaClient $client,
        private readonly WhatsAppDelivery $delivery,
    ) {}

    public function show()
    {
        return $this->ok($this->settings->snapshot() + ['table_ready' => WhatsAppMessage::tableReady()]);
    }

    public function update(UpdateWhatsAppSettingsRequest $request)
    {
        $snapshot = $this->settings->update($request->validated(), $request->user());

        return $this->ok($snapshot + ['table_ready' => WhatsAppMessage::tableReady()], __('messages.whatsapp.saved'));
    }

    /** Read-only probe of the gateway and the configured device. */
    public function testConnection(Request $request)
    {
        $status = $this->client->status();
        ActivityLogger::log($request->user()->id, $this->anchor(), 'whatsapp.connection.tested', null, ['state' => $status['state']]);

        return $this->ok($status);
    }

    /** A REAL message — allowed only to Owner-authorised test numbers (Settings → test_numbers). */
    public function testMessage(Request $request)
    {
        $data = $request->validate(['number' => ['required', 'string', 'max:20'], 'message' => ['nullable', 'string', 'max:300']]);

        $msisdn = WhatsAppNumber::msisdn($data['number']);
        $allowed = $this->settings->reload()->testNumbers();
        if ($allowed === []) {
            throw new ApiException(__('messages.whatsapp.no_test_numbers'), 422, ['number' => [__('messages.whatsapp.no_test_numbers')]]);
        }
        if ($msisdn === null || ! in_array($msisdn, $allowed, true)) {
            throw new ApiException(__('messages.whatsapp.number_not_allowed'), 422, ['number' => [__('messages.whatsapp.number_not_allowed')]]);
        }
        if (! $this->settings->isConfigured()) {
            throw new ApiException(__('messages.whatsapp.config_incomplete'), 422);
        }

        $text = trim($data['message'] ?? '') !== '' ? MessageTemplates::clean($data['message']) : __('messages.whatsapp.test_text');
        $message = $this->delivery->sendTest($request->user(), $msisdn, (string) $text);

        return $this->ok($this->item($message), __('messages.whatsapp.test_done'));
    }

    public function previewTemplate(Request $request, string $event)
    {
        $this->assertEvent($event);
        $data = $request->validate(['template' => ['required', 'string', 'max:2000']]);

        MessageTemplates::validate($event, $data['template']);

        return $this->ok(['preview' => MessageTemplates::preview($event, $data['template'])]);
    }

    public function updateTemplate(Request $request, string $event)
    {
        $this->assertEvent($event);
        $data = $request->validate(['template' => ['required', 'string', 'max:2000']]);

        return $this->ok($this->settings->updateTemplate($event, $data['template'], $request->user()), __('messages.whatsapp.saved'));
    }

    public function resetTemplate(Request $request, string $event)
    {
        $this->assertEvent($event);

        return $this->ok($this->settings->resetTemplate($event, $request->user()), __('messages.whatsapp.saved'));
    }

    public function messages(Request $request)
    {
        if (! WhatsAppMessage::tableReady()) {
            return $this->ok(['migration_pending' => true, 'data' => [], 'total' => 0, 'last_page' => 1, 'current_page' => 1]);
        }

        $q = WhatsAppMessage::query()->orderByDesc('id');
        foreach (['status', 'purpose', 'event'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, $request->string($f)->toString());
            }
        }

        $page = $q->paginate(min($request->integer('per_page', 20), 100));

        return $this->ok([
            'migration_pending' => false,
            'data' => collect($page->items())->map(fn (WhatsAppMessage $m) => $this->item($m))->values(),
            'total' => $page->total(), 'last_page' => $page->lastPage(), 'current_page' => $page->currentPage(),
        ]);
    }

    public function retry(Request $request, WhatsAppMessage $message)
    {
        $data = $request->validate(['accept_duplicate_risk' => ['sometimes', 'boolean']]);

        return $this->ok(
            $this->item($this->delivery->manualRetry($message, $request->user(), (bool) ($data['accept_duplicate_risk'] ?? false))),
            __('messages.whatsapp.retry_queued'),
        );
    }

    /** @return array<string, mixed> */
    private function item(WhatsAppMessage $m): array
    {
        return [
            'id' => $m->id, 'purpose' => $m->purpose, 'event' => $m->event, 'status' => $m->status,
            'template_class' => $m->template_class, 'recipient_role' => $m->recipient_role,
            'sender_device_id' => $m->sender_device_id,
            'order_id' => $m->order_id, 'recipient' => $m->maskedPhone(),
            // OTP bodies are stored redacted; the encrypted original is never serialised (model $hidden).
            'body' => $m->body, 'attempts' => $m->attempts, 'max_attempts' => $m->max_attempts,
            'provider_message_id' => $m->provider_message_id, 'error_class' => $m->error_class,
            'error_message' => $m->error_message, 'skip_reason' => $m->skip_reason,
            'queued_at' => $m->queued_at, 'sent_at' => $m->sent_at, 'created_at' => $m->created_at,
            'can_retry' => in_array($m->status, [WhatsAppMessage::FAILED, WhatsAppMessage::UNKNOWN], true) && $m->purpose !== WhatsAppMessage::PURPOSE_OTP,
            'needs_duplicate_risk_confirmation' => $m->status === WhatsAppMessage::UNKNOWN,
        ];
    }

    private function assertEvent(string $event): void
    {
        if (! WhatsAppEvents::exists($event)) {
            throw new ApiException(__('messages.whatsapp.unknown_event'), 404);
        }
    }

    private function anchor(): \App\Models\Setting
    {
        return \App\Models\Setting::query()->firstOrCreate(
            ['key' => 'wa.enabled'], ['value' => '0', 'group' => WhatsAppSettings::GROUP, 'is_public' => false],
        );
    }
}
