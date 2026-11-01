<?php

namespace App\Services\Mail;

use App\Exceptions\ApiException;
use App\Models\Setting;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Global SMTP configuration (Super Admin only), stored in the EXISTING `settings` table under the private group `integration.smtp`.
 * It points Laravel's mailer at the existing mail server; it never builds or changes a mail server. The password is encrypted with the
 * app key, is WRITE-ONLY (APIs only learn `has_password`) and never reaches logs/audit. Agents cannot change it.
 *
 * When the dashboard configuration is switched ON it overrides the environment mailer at runtime (so the existing OTP e-mails and every
 * other Mailable use it unchanged); when OFF or incomplete the environment configuration keeps working exactly as before.
 *
 * Encryption: `ssl` = implicit TLS (smtps), `tls` = STARTTLS REQUIRED, `none` = plaintext (allowed for a local relay only; certificate
 * verification is never disabled).
 */
class SmtpSettings
{
    public const GROUP = 'integration.smtp';

    public const ENCRYPTIONS = ['ssl', 'tls', 'none'];

    /** @var array<string,string>|null */
    private ?array $rows = null;

    public function reload(): static
    {
        $this->rows = null;

        return $this;
    }

    private function tableReady(): bool
    {
        try {
            return Schema::hasTable('settings');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,string> */
    private function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        return $this->rows = $this->tableReady() ? Setting::query()->where('group', self::GROUP)->pluck('value', 'key')->all() : [];
    }

    private function get(string $key, ?string $default = null): ?string
    {
        $v = $this->rows()[$key] ?? null;

        return $v === null || $v === '' ? $default : $v;
    }

    public function enabled(): bool
    {
        return filter_var($this->get('smtp.enabled', '0'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Transactional order/payment/shipping/return/refund e-mail. Default OFF: needs the dashboard SMTP ON and complete AND
     * this separate switch, so turning SMTP on for account mail never starts customer order mail by itself.
     */
    public function transactionalEnabled(): bool
    {
        return $this->enabled() && $this->isComplete()
            && filter_var($this->get('smtp.transactional_enabled', '0'), FILTER_VALIDATE_BOOLEAN);
    }

    public function password(): ?string
    {
        $cipher = $this->get('smtp.password');
        if ($cipher === null) {
            return null;
        }
        try {
            return Crypt::decryptString($cipher);
        } catch (DecryptException) {
            return null;
        }
    }

    /** Host, port and sender are the minimum for a usable relay (username/password are optional for an open internal relay). */
    public function isComplete(): bool
    {
        return (bool) ($this->get('smtp.host') && $this->get('smtp.port') && $this->get('smtp.from_address'));
    }

    /** @return array<string,mixed> everything the dashboard may see — never the password */
    public function snapshot(): array
    {
        return [
            'enabled' => $this->enabled(),
            'host' => $this->get('smtp.host'),
            'port' => $this->get('smtp.port') ? (int) $this->get('smtp.port') : null,
            'encryption' => $this->get('smtp.encryption', 'tls'),
            'username' => $this->get('smtp.username'),
            'has_password' => $this->get('smtp.password') !== null,
            'from_address' => $this->get('smtp.from_address'),
            'from_name' => $this->get('smtp.from_name'),
            'timeout' => (int) $this->get('smtp.timeout', '10'),
            'complete' => $this->isComplete(),
            'transactional_enabled' => filter_var($this->get('smtp.transactional_enabled', '0'), FILTER_VALIDATE_BOOLEAN),
            // Which configuration the application is using right now.
            'active_source' => $this->enabled() && $this->isComplete() ? 'dashboard' : 'environment',
        ];
    }

    /**
     * @param  array<string,mixed>  $data  validated input; `password` null/'' keeps the stored secret
     * @return array<string,mixed>
     */
    public function update(array $data, User $actor): array
    {
        $before = $this->snapshot();
        $writes = [];

        if (array_key_exists('enabled', $data)) {
            $writes['smtp.enabled'] = $data['enabled'] ? '1' : '0';
        }
        if (array_key_exists('transactional_enabled', $data)) {
            $writes['smtp.transactional_enabled'] = $data['transactional_enabled'] ? '1' : '0';
        }
        foreach (['host' => 'smtp.host', 'username' => 'smtp.username', 'from_name' => 'smtp.from_name'] as $in => $key) {
            if (array_key_exists($in, $data)) {
                $writes[$key] = trim((string) $data[$in]);
            }
        }
        if (array_key_exists('from_address', $data)) {
            $writes['smtp.from_address'] = strtolower(trim((string) $data['from_address']));
        }
        foreach (['port' => 'smtp.port', 'timeout' => 'smtp.timeout'] as $in => $key) {
            if (array_key_exists($in, $data)) {
                $writes[$key] = (string) (int) $data[$in];
            }
        }
        if (array_key_exists('encryption', $data)) {
            $writes['smtp.encryption'] = in_array($data['encryption'], self::ENCRYPTIONS, true) ? $data['encryption'] : 'tls';
        }
        if (isset($data['password']) && $data['password'] !== '') {
            $writes['smtp.password'] = Crypt::encryptString((string) $data['password']);
        }
        if (! empty($data['clear_password'])) {
            $writes['smtp.password'] = '';
        }

        // Enabling needs a usable configuration (stored values or this very request), otherwise the switch would be a lie.
        if (($writes['smtp.enabled'] ?? null) === '1') {
            $has = fn (string $k) => ($writes[$k] ?? $this->get($k)) !== null && ($writes[$k] ?? $this->get($k)) !== '';
            if (! ($has('smtp.host') && $has('smtp.port') && $has('smtp.from_address'))) {
                throw new ApiException(__('messages.smtp.config_incomplete'), 422, ['enabled' => [__('messages.smtp.config_incomplete')]]);
            }
        }

        DB::transaction(function () use ($writes) {
            foreach ($writes as $key => $value) {
                Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'group' => self::GROUP, 'is_public' => false]);
            }
        });
        $this->reload();

        $anchor = Setting::query()->firstOrCreate(['key' => 'smtp.enabled'], ['value' => '0', 'group' => self::GROUP, 'is_public' => false]);
        ActivityLogger::log($actor->id, $anchor, 'smtp.settings.updated', null, [
            'changed_keys' => array_keys($writes),
            // (not named `password`: ActivityLogger blanks any such key, which would hide whether it changed)
            'secret_changed' => array_key_exists('smtp.password', $writes),
            'old' => $before,
            'new' => $this->snapshot(),
        ]);

        return $this->snapshot();
    }

    /**
     * Points Laravel's mailer at the dashboard configuration when it is ON and complete. Called on boot; never throws (a broken
     * settings table must not take the application down) and never touches anything when the switch is off.
     */
    public function applyRuntime(bool $force = false): bool
    {
        try {
            $this->reload();
            if (! $this->isComplete() || (! $force && ! $this->enabled())) {
                return false;
            }

            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp' => $this->mailerConfig(),
                'mail.from' => ['address' => $this->get('smtp.from_address'), 'name' => $this->get('smtp.from_name') ?: config('app.name')],
            ]);
            Mail::purge('smtp');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    private function mailerConfig(): array
    {
        $enc = $this->get('smtp.encryption', 'tls');
        $cfg = [
            'transport' => 'smtp', 'host' => $this->get('smtp.host'), 'port' => (int) $this->get('smtp.port'),
            'username' => $this->get('smtp.username') ?: null, 'password' => $this->password(),
            'timeout' => max(2, min(60, (int) $this->get('smtp.timeout', '10'))),
        ];

        return match ($enc) {
            'ssl' => $cfg + ['scheme' => 'smtps'],
            'none' => $cfg + ['scheme' => 'smtp', 'auto_tls' => false],
            default => $cfg + ['scheme' => 'smtp', 'require_tls' => true],
        };
    }

    /**
     * Opens a real session against the saved relay (connect + EHLO + STARTTLS + AUTH) WITHOUT sending a message.
     *
     * @return array{ok:bool, detail:?string}
     */
    public function testConnection(): array
    {
        if (! $this->applyRuntime(force: true)) {
            return ['ok' => false, 'detail' => __('messages.smtp.config_incomplete')];
        }

        try {
            $transport = Mail::mailer('smtp')->getSymfonyTransport();
            $transport->start();
            $transport->stop();

            return ['ok' => true, 'detail' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => $this->sanitize($e->getMessage())];
        }
    }

    /**
     * Sends ONE test message to the acting Super Admin's own address — never to an address typed by anyone.
     *
     * @return array{ok:bool, detail:?string}
     */
    public function sendTestTo(User $actor): array
    {
        if (! $actor->email || ! $this->applyRuntime(force: true)) {
            return ['ok' => false, 'detail' => __('messages.smtp.config_incomplete')];
        }

        try {
            Mail::mailer('smtp')->raw(__('messages.smtp.test_body'), fn ($m) => $m->to($actor->email)->subject(__('messages.smtp.test_subject')));

            return ['ok' => true, 'detail' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => $this->sanitize($e->getMessage())];
        }
    }

    /** Provider/transport messages may echo the login; the secret and the username are removed before anything is shown or logged. */
    private function sanitize(string $message): string
    {
        foreach (array_filter([$this->password(), $this->get('smtp.username')]) as $secret) {
            $message = str_replace($secret, '[hidden]', $message);
        }

        return mb_substr($message, 0, 300);
    }
}
