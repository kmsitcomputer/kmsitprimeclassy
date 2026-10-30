<?php

namespace App\Services\WhatsApp;

use App\Exceptions\ApiException;
use App\Models\Setting;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use App\Support\WhatsAppNumber;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Global (Super Admin) WhatsApp Gateway configuration, stored in the EXISTING `settings` table under the private
 * group `integration.whatsapp` (never `is_public`, never part of the website-settings key whitelist). The API password
 * is encrypted with the app key and is NEVER returned by any API: callers only learn `has_password`. Every change is
 * written to `activity_logs` with secrets reduced to "[changed]".
 *
 * The same settings drive a local GOWA container and the future production endpoint: only base URL / credentials /
 * device id differ, no business logic does.
 */
class WhatsAppSettings
{
    public const GROUP = 'integration.whatsapp';

    public const PROVIDER = 'gowa';

    private const DEFAULT_OTP = [
        'enabled' => false, 'ttl_minutes' => 5, 'max_attempts' => 5, 'cooldown_seconds' => 60,
        'limit' => 3, 'limit_window_minutes' => 10,
    ];

    /** @var array<string,string>|null */
    private ?array $rows = null;

    /** Drop the per-instance cache. Long-lived holders (observers, queue workers) call this before every decision. */
    public function reload(): static
    {
        $this->rows = null;

        return $this;
    }

    /** @return array<string,string> */
    private function rows(): array
    {
        return $this->rows ??= Setting::query()->where('group', self::GROUP)->pluck('value', 'key')->all();
    }

    private function get(string $key, ?string $default = null): ?string
    {
        $v = $this->rows()[$key] ?? null;

        return $v === null || $v === '' ? $default : $v;
    }

    private function flag(string $key, bool $default = false): bool
    {
        $v = $this->get($key);

        return $v === null ? $default : filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    public function enabled(): bool
    {
        return $this->flag('wa.enabled');
    }

    public function baseUrl(): ?string
    {
        return $this->get('wa.base_url');
    }

    public function username(): ?string
    {
        return $this->get('wa.username');
    }

    public function password(): ?string
    {
        $cipher = $this->get('wa.password');
        if ($cipher === null) {
            return null;
        }

        try {
            return Crypt::decryptString($cipher);
        } catch (DecryptException) {
            return null; // app key rotated / corrupted: behave as "not configured", never leak the cipher
        }
    }

    public function deviceId(): ?string
    {
        return $this->get('wa.device_id');
    }

    public function timeout(): int
    {
        return max(2, min(30, (int) $this->get('wa.timeout', '10')));
    }

    public function maxAttempts(): int
    {
        return max(1, min(5, (int) $this->get('wa.max_attempts', '3')));
    }

    /**
     * Volume protection for the ONE shared WhatsApp device. Conservative on purpose; a Super Admin may tune within hard bounds.
     *
     * @return array{per_recipient_per_hour:int, global_per_minute:int}
     */
    public function rateLimits(): array
    {
        return [
            'per_recipient_per_hour' => max(1, min(60, (int) $this->get('wa.rate.recipient_hour', '6'))),
            'global_per_minute' => max(1, min(120, (int) $this->get('wa.rate.global_minute', '20'))),
        ];
    }

    public function backoffSeconds(): int
    {
        return max(10, min(3600, (int) $this->get('wa.backoff_seconds', '60')));
    }

    /** @return array<int,string> msisdn allow-list for real test messages (Owner-authorised numbers only) */
    public function testNumbers(): array
    {
        $decoded = json_decode((string) $this->get('wa.test_numbers', '[]'), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /** The central server connection is complete (independent of the global ON/OFF switch): device management may use it. */
    public function hasCredentials(): bool
    {
        return (bool) ($this->baseUrl() && $this->username() && $this->password());
    }

    /** Gateway usable for sending: enabled AND complete credentials. */
    public function isConfigured(): bool
    {
        return $this->enabled() && $this->baseUrl() && $this->username() && $this->password();
    }

    /** @return array{enabled:bool,ttl_minutes:int,max_attempts:int,cooldown_seconds:int,limit:int,limit_window_minutes:int} */
    public function otp(): array
    {
        $out = [];
        foreach (self::DEFAULT_OTP as $k => $default) {
            $raw = $this->get("wa.otp.$k");
            $out[$k] = is_bool($default) ? ($raw === null ? $default : filter_var($raw, FILTER_VALIDATE_BOOLEAN)) : (int) ($raw ?? $default);
        }

        return $out;
    }

    public function eventEnabled(string $event): bool
    {
        // Default OFF: nothing is sent to anyone until a Super Admin deliberately switches an event on.
        return WhatsAppEvents::exists($event) && $this->flag("wa.event.$event");
    }

    public function template(string $event): string
    {
        return $this->get("wa.template.$event") ?? WhatsAppEvents::all()[$event]['default'];
    }

    /** Everything the dashboard may see. No secret, ever. */
    public function snapshot(): array
    {
        $events = [];
        foreach (WhatsAppEvents::all() as $key => $def) {
            $events[] = [
                'key' => $key, 'label' => $def['label'], 'enabled' => $this->eventEnabled($key),
                'template' => $this->template($key), 'is_default_template' => $this->get("wa.template.$key") === null,
                'variables' => $def['variables'], 'required' => $def['required'],
            ];
        }

        return [
            'provider' => self::PROVIDER,
            'enabled' => $this->enabled(),
            'base_url' => $this->baseUrl(),
            'username' => $this->username(),
            'has_password' => $this->get('wa.password') !== null,
            'device_id' => $this->deviceId(),
            'timeout' => $this->timeout(),
            'max_attempts' => $this->maxAttempts(),
            'backoff_seconds' => $this->backoffSeconds(),
            'limits' => $this->rateLimits(),
            'test_numbers' => array_map(fn ($n) => ['masked' => WhatsAppNumber::mask($n)], $this->testNumbers()),
            'test_numbers_count' => count($this->testNumbers()),
            'otp' => $this->otp(),
            'events' => $events,
            'configured' => $this->isConfigured(),
        ];
    }

    /**
     * @param  array<string,mixed>  $data  validated input; `password` null/'' keeps the stored secret
     */
    public function update(array $data, User $actor): array
    {
        $before = $this->snapshot();
        $writes = [];

        foreach (['enabled' => 'wa.enabled'] as $in => $key) {
            if (array_key_exists($in, $data)) {
                $writes[$key] = $data[$in] ? '1' : '0';
            }
        }

        if (array_key_exists('base_url', $data)) {
            $writes['wa.base_url'] = $this->validatedBaseUrl((string) $data['base_url']);
        }
        foreach (['username' => 'wa.username', 'device_id' => 'wa.device_id'] as $in => $key) {
            if (array_key_exists($in, $data)) {
                $writes[$key] = trim((string) $data[$in]);
            }
        }
        if (isset($data['password']) && $data['password'] !== '') {
            $writes['wa.password'] = Crypt::encryptString((string) $data['password']);
        }
        foreach (['timeout' => 'wa.timeout', 'max_attempts' => 'wa.max_attempts', 'backoff_seconds' => 'wa.backoff_seconds'] as $in => $key) {
            if (array_key_exists($in, $data)) {
                $writes[$key] = (string) (int) $data[$in];
            }
        }
        foreach (['per_recipient_per_hour' => 'wa.rate.recipient_hour', 'global_per_minute' => 'wa.rate.global_minute'] as $in => $key) {
            if (isset($data['limits']) && array_key_exists($in, (array) $data['limits'])) {
                $writes[$key] = (string) (int) $data['limits'][$in];
            }
        }
        if (array_key_exists('test_numbers', $data)) {
            $numbers = [];
            foreach ((array) $data['test_numbers'] as $raw) {
                $n = WhatsAppNumber::msisdn((string) $raw);
                if ($n === null) {
                    throw new ApiException(__('messages.whatsapp.invalid_number'), 422, ['test_numbers' => [__('messages.whatsapp.invalid_number')]]);
                }
                $numbers[$n] = $n;
            }
            $writes['wa.test_numbers'] = json_encode(array_values($numbers));
        }
        foreach ((array) ($data['otp'] ?? []) as $k => $v) {
            if (array_key_exists($k, self::DEFAULT_OTP)) {
                $writes["wa.otp.$k"] = is_bool(self::DEFAULT_OTP[$k]) ? ($v ? '1' : '0') : (string) (int) $v;
            }
        }
        foreach ((array) ($data['events'] ?? []) as $event => $enabled) {
            if (WhatsAppEvents::exists((string) $event)) {
                $writes["wa.event.$event"] = $enabled ? '1' : '0';
            }
        }

        // Enabling needs a complete connection (stored value or this very request), otherwise the switch would be a lie.
        $enabling = ($writes['wa.enabled'] ?? null) === '1';
        if ($enabling) {
            $complete = ($writes['wa.base_url'] ?? $this->baseUrl())
                && ($writes['wa.username'] ?? $this->username())
                && (isset($writes['wa.password']) || $this->get('wa.password') !== null);
            if (! $complete) {
                throw new ApiException(__('messages.whatsapp.config_incomplete'), 422, ['enabled' => [__('messages.whatsapp.config_incomplete')]]);
            }
        }

        $this->persist($writes);

        $after = $this->snapshot();
        $this->audit($actor, 'whatsapp.settings.updated', $writes, $before, $after);

        return $after;
    }

    public function updateTemplate(string $event, string $template, User $actor): array
    {
        MessageTemplates::validate($event, $template);

        $old = $this->template($event);
        $this->persist(["wa.template.$event" => $template]);
        $this->audit($actor, 'whatsapp.template.updated', ["wa.template.$event" => $template], ['template' => $old, 'event' => $event], ['template' => $template, 'event' => $event]);

        return $this->snapshot();
    }

    public function resetTemplate(string $event, User $actor): array
    {
        if (! WhatsAppEvents::exists($event)) {
            throw new ApiException(__('messages.whatsapp.unknown_event'), 422);
        }

        $old = $this->template($event);
        Setting::query()->where('key', "wa.template.$event")->where('group', self::GROUP)->delete();
        $this->rows = null;
        $this->audit($actor, 'whatsapp.template.reset', ["wa.template.$event" => '[default]'], ['template' => $old, 'event' => $event], ['template' => WhatsAppEvents::all()[$event]['default'], 'event' => $event]);

        return $this->snapshot();
    }

    /** @param  array<string,string>  $writes */
    private function persist(array $writes): void
    {
        DB::transaction(function () use ($writes) {
            foreach ($writes as $key => $value) {
                Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'group' => self::GROUP, 'is_public' => false]);
            }
        });
        $this->rows = null;
    }

    /**
     * @param  array<string,string>  $writes
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     */
    private function audit(User $actor, string $event, array $writes, array $before, array $after): void
    {
        // Stable anchor row so an audit entry exists even right after the last config row was deleted (template reset).
        $anchor = Setting::query()->firstOrCreate(['key' => 'wa.enabled'], ['value' => '0', 'group' => self::GROUP, 'is_public' => false]);

        // Secrets never reach the audit trail: the password is reduced to a marker, numbers are masked in the snapshot.
        $changed = array_map(fn ($k) => $k === 'wa.password' ? 'wa.password' : $k, array_keys($writes));

        ActivityLogger::log($actor->id, $anchor, $event, null, [
            'changed_keys' => $changed,
            // (the key is NOT named `password`: ActivityLogger blanks any such key, which would hide whether it changed)
            'secret_changed' => array_key_exists('wa.password', $writes),
            'old' => $before,
            'new' => $after,
        ]);
    }

    private function validatedBaseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);

        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new ApiException(__('messages.whatsapp.invalid_base_url'), 422, ['base_url' => [__('messages.whatsapp.invalid_base_url')]]);
        }

        // Link-local / cloud-metadata addresses are never a legitimate gateway.
        if (preg_match('/^169\.254\./', $parts['host']) || $parts['host'] === 'metadata.google.internal') {
            throw new ApiException(__('messages.whatsapp.invalid_base_url'), 422, ['base_url' => [__('messages.whatsapp.invalid_base_url')]]);
        }

        return $url;
    }
}
