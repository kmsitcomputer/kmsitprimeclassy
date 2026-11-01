<?php

namespace App\Services\WhatsApp;

use App\Exceptions\ApiException;
use App\Models\AgentWhatsAppEventSetting;
use App\Models\AgentWhatsAppTestNumber;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use App\Support\WhatsAppNumber;

/**
 * Per-Agen WhatsApp notification events/templates and the registered test-number allow-list (WA-DEC-02/03).
 *
 * One authoritative row per Agen+event, edited by the Agen or its Admin (scope resolved by WhatsAppScope; never a client id).
 * Only the transactional events in WhatsAppEvents are configurable — OTP, identity, recovery and registration traffic are
 * not events here and therefore cannot be switched or re-worded from this surface. Templates go through the same
 * MessageTemplates::validate allow-list as the platform defaults, so a template can never reach a variable its event does not
 * define. The restricted (unproven-recipient) lane uses a fixed template and ignores these rows entirely.
 */
class AgentWhatsAppSettingsService
{
    public const MAX_TEST_NUMBERS = 5;

    public function __construct(private readonly WhatsAppSettings $settings) {}

    /** @return array<int, array<string,mixed>> */
    public function events(User $agent): array
    {
        $this->settings->reload();
        $rows = AgentWhatsAppEventSetting::tableReady()
            ? AgentWhatsAppEventSetting::query()->where('agent_id', $agent->id)->get()->keyBy('event')
            : collect();

        $out = [];
        foreach (WhatsAppEvents::all() as $key => $def) {
            $row = $rows->get($key);
            $out[] = [
                'key' => $key, 'label' => $def['label'], 'variables' => $def['variables'],
                'enabled' => $this->settings->eventEnabledFor($key, $agent->id),
                'enabled_source' => $row && $row->enabled !== null ? 'agent' : 'default',
                'template' => $this->settings->templateFor($key, $agent->id),
                'template_source' => $row && $row->template ? 'agent' : 'default',
                'default_template' => $def['default'],
            ];
        }

        return $out;
    }

    /** @param array{enabled?:bool, template?:string} $changes */
    public function update(User $actor, User $agent, string $event, array $changes): array
    {
        $this->assertConfigurable($event);
        $this->assertTable();

        if (array_key_exists('template', $changes)) {
            MessageTemplates::validate($event, (string) $changes['template']);
        }

        $row = AgentWhatsAppEventSetting::query()->firstOrNew(['agent_id' => $agent->id, 'event' => $event]);
        $before = ['enabled' => $row->enabled, 'template' => $row->template];
        if (array_key_exists('enabled', $changes)) {
            $row->enabled = (bool) $changes['enabled'];
        }
        if (array_key_exists('template', $changes)) {
            $row->template = (string) $changes['template'];
        }
        $row->updated_by = $actor->id;
        $row->save();

        $this->audit($actor, $agent, $row, 'whatsapp.agent_event.updated', [
            'event' => $event, 'enabled' => ['old' => $before['enabled'], 'new' => $row->enabled],
            // The template text itself is business copy, not a secret; only its change is recorded, not its full content.
            'template_changed' => $before['template'] !== $row->template,
        ]);

        return $this->one($agent, $event);
    }

    public function resetTemplate(User $actor, User $agent, string $event): array
    {
        $this->assertConfigurable($event);
        $this->assertTable();

        $row = AgentWhatsAppEventSetting::query()->where('agent_id', $agent->id)->where('event', $event)->first();
        if ($row && $row->template !== null) {
            $row->forceFill(['template' => null, 'updated_by' => $actor->id])->save();
            $this->audit($actor, $agent, $row, 'whatsapp.agent_event.template_reset', ['event' => $event]);
        }

        return $this->one($agent, $event);
    }

    public function preview(string $event, string $template): string
    {
        $this->assertConfigurable($event);
        MessageTemplates::validate($event, $template);

        return MessageTemplates::preview($event, $template);
    }

    /* ---------------------------------------------------------------- test numbers */

    /** @return array<int, array{id:int, masked:string}> */
    public function testNumbers(User $agent): array
    {
        if (! AgentWhatsAppTestNumber::tableReady()) {
            return [];
        }

        return AgentWhatsAppTestNumber::query()->where('agent_id', $agent->id)->orderBy('id')->get()
            ->map(fn (AgentWhatsAppTestNumber $n) => ['id' => $n->id, 'masked' => WhatsAppNumber::mask($n->msisdn)])->all();
    }

    public function registerTestNumber(User $actor, User $agent, string $raw): array
    {
        $this->assertTable();
        $msisdn = WhatsAppNumber::msisdn($raw);
        if ($msisdn === null) {
            throw new ApiException(__('messages.whatsapp.invalid_number'), 422, ['number' => [__('messages.whatsapp.invalid_number')]]);
        }

        $existing = AgentWhatsAppTestNumber::query()->where('agent_id', $agent->id)->where('msisdn', $msisdn)->first();
        if (! $existing) {
            if (AgentWhatsAppTestNumber::query()->where('agent_id', $agent->id)->count() >= self::MAX_TEST_NUMBERS) {
                throw new ApiException(__('messages.whatsapp.test_numbers_limit', ['max' => self::MAX_TEST_NUMBERS]), 422, ['number' => [__('messages.whatsapp.test_numbers_limit', ['max' => self::MAX_TEST_NUMBERS])]]);
            }
            $existing = AgentWhatsAppTestNumber::query()->create(['agent_id' => $agent->id, 'msisdn' => $msisdn, 'registered_by' => $actor->id]);
            $this->audit($actor, $agent, $existing, 'whatsapp.agent_test_number.registered', ['masked' => WhatsAppNumber::mask($msisdn)]);
        }

        return $this->testNumbers($agent);
    }

    public function removeTestNumber(User $actor, User $agent, int $id): array
    {
        $this->assertTable();
        $row = AgentWhatsAppTestNumber::query()->where('agent_id', $agent->id)->where('id', $id)->first();
        if ($row) {
            $this->audit($actor, $agent, $row, 'whatsapp.agent_test_number.removed', ['masked' => WhatsAppNumber::mask($row->msisdn)]);
            $row->delete();
        }

        return $this->testNumbers($agent);
    }

    /** True only for a number registered for THIS Agen. */
    public function isAllowedTestNumber(User $agent, string $msisdn): bool
    {
        return AgentWhatsAppTestNumber::tableReady()
            && AgentWhatsAppTestNumber::query()->where('agent_id', $agent->id)->where('msisdn', $msisdn)->exists();
    }

    /* ---------------------------------------------------------------- internals */

    private function one(User $agent, string $event): array
    {
        return collect($this->events($agent))->firstWhere('key', $event);
    }

    private function assertConfigurable(string $event): void
    {
        if (! WhatsAppEvents::exists($event)) {
            throw new ApiException(__('messages.whatsapp.event_not_configurable'), 422, ['event' => [__('messages.whatsapp.event_not_configurable')]]);
        }
    }

    private function assertTable(): void
    {
        if (! AgentWhatsAppEventSetting::tableReady() || ! AgentWhatsAppTestNumber::tableReady()) {
            throw new ApiException(__('messages.whatsapp.migration_pending'), 503);
        }
    }

    /** @param array<string,mixed> $props */
    private function audit(User $actor, User $agent, \Illuminate\Database\Eloquent\Model $subject, string $event, array $props): void
    {
        ActivityLogger::log($actor->id, $subject, $event, null, $props + ['actor_id' => $actor->id, 'target_agent_id' => $agent->id]);
    }
}
