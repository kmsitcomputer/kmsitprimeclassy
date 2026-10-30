<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Mail\SmtpSettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Global SMTP (Super Admin). No real mail server or address is contacted: a closed local port is used for the failure paths. */
class SmtpSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/integrations/smtp';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function payload(array $over = []): array
    {
        return $over + [
            'host' => 'mail.example.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'mailer-user', 'password' => 'mail-secret-pass',
            'from_address' => 'noreply@example.test', 'from_name' => 'Prime Classy', 'timeout' => 5,
        ];
    }

    public function test_only_the_super_admin_can_read_or_change_smtp(): void
    {
        foreach (['admin', 'agen', 'keuangan', 'korsal', 'sales', 'kurir', 'konsumen'] as $role) {
            $u = User::factory()->$role()->create();
            $this->actingAs($u)->getJson(self::URL)->assertForbidden();
            $this->actingAs($u)->putJson(self::URL, $this->payload())->assertForbidden();
            $this->actingAs($u)->postJson(self::URL.'/test-connection')->assertForbidden();
            $this->actingAs($u)->postJson(self::URL.'/test-email')->assertForbidden();
        }
        $this->assertSame(0, Setting::query()->where('group', 'integration.smtp')->count());
    }

    public function test_the_password_is_write_only_encrypted_and_kept_when_omitted(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $res = $this->actingAs($admin)->putJson(self::URL, $this->payload())->assertOk()->assertJsonPath('data.has_password', true);
        foreach (['mail-secret-pass'] as $secret) {
            $this->assertStringNotContainsString($secret, $res->getContent());
            $this->assertStringNotContainsString($secret, $this->actingAs($admin)->getJson(self::URL)->getContent());
        }
        $stored = Setting::where('key', 'smtp.password')->value('value');
        $this->assertNotSame('mail-secret-pass', $stored);
        $this->assertSame('mail-secret-pass', app(SmtpSettings::class)->reload()->password());

        // Saving other fields without a password keeps the stored secret.
        $this->actingAs($admin)->putJson(self::URL, ['from_name' => 'Renamed'])->assertOk()->assertJsonPath('data.has_password', true);
        $this->assertSame('mail-secret-pass', app(SmtpSettings::class)->reload()->password());

        // Clearing is explicit.
        $this->actingAs($admin)->putJson(self::URL, ['clear_password' => true])->assertOk()->assertJsonPath('data.has_password', false);
    }

    public function test_the_audit_trail_records_that_a_secret_changed_but_never_the_secret(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin)->putJson(self::URL, $this->payload())->assertOk();

        $log = ActivityLog::query()->where('event', 'smtp.settings.updated')->latest('id')->firstOrFail();
        $dump = json_encode($log->toArray());
        $this->assertStringNotContainsString('mail-secret-pass', $dump);
        $this->assertTrue((bool) data_get($log->properties, 'secret_changed'));
    }

    public function test_switching_on_requires_a_usable_configuration(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin)->putJson(self::URL, ['enabled' => true])->assertStatus(422);
        $this->assertFalse(app(SmtpSettings::class)->reload()->enabled());

        $this->actingAs($admin)->putJson(self::URL, $this->payload(['enabled' => true]))->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.active_source', 'dashboard');
    }

    public function test_input_is_validated(): void
    {
        $admin = User::factory()->superAdmin()->create();
        foreach ([
            ['host' => 'smtp://evil.example.test'], ['host' => 'a b'], ['host' => 'host/path'],
            ['port' => 22], ['port' => 'abc'], ['encryption' => 'starttls-maybe'], ['from_address' => 'not-an-email'], ['timeout' => 1],
        ] as $bad) {
            $this->actingAs($admin)->putJson(self::URL, $this->payload($bad))->assertStatus(422);
        }
        $this->assertSame(0, Setting::query()->where('group', 'integration.smtp')->count());
    }

    public function test_the_dashboard_configuration_overrides_the_environment_mailer_only_while_on(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $envDefault = config('mail.default');
        $this->actingAs($admin)->putJson(self::URL, $this->payload(['enabled' => false]))->assertOk();

        $this->assertFalse(app(SmtpSettings::class)->applyRuntime());
        $this->assertSame($envDefault, config('mail.default'), 'OFF: the existing environment configuration is untouched');

        $this->actingAs($admin)->putJson(self::URL, ['enabled' => true])->assertOk();
        $this->assertTrue(app(SmtpSettings::class)->applyRuntime());
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('mail.example.test', config('mail.mailers.smtp.host'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));
        $this->assertSame('smtp', config('mail.mailers.smtp.scheme'));
        $this->assertTrue((bool) config('mail.mailers.smtp.require_tls'), 'tls = STARTTLS REQUIRED');
        $this->assertSame('noreply@example.test', config('mail.from.address'));
        $this->assertSame('mail-secret-pass', config('mail.mailers.smtp.password'));
        // certificate verification is never switched off
        $this->assertArrayNotHasKey('verify_peer', config('mail.mailers.smtp'));

        $this->actingAs($admin)->putJson(self::URL, ['encryption' => 'ssl', 'port' => 465])->assertOk();
        app(SmtpSettings::class)->applyRuntime();
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
    }

    public function test_a_failing_relay_is_reported_without_leaking_the_login(): void
    {
        $admin = User::factory()->superAdmin()->create();
        // 127.0.0.1:1025 is closed: a real connection attempt, a real failure, no outside contact.
        $this->actingAs($admin)->putJson(self::URL, $this->payload(['host' => '127.0.0.1', 'port' => 1025, 'encryption' => 'none', 'timeout' => 2]))->assertOk();

        $res = $this->actingAs($admin)->postJson(self::URL.'/test-connection')->assertOk()->assertJsonPath('data.ok', false);
        $this->assertStringNotContainsString('mail-secret-pass', $res->getContent());
        $this->assertStringNotContainsString('mailer-user', $res->getContent());

        $res = $this->actingAs($admin)->postJson(self::URL.'/test-email', ['to' => 'someone-else@example.test'])->assertOk()->assertJsonPath('data.ok', false);
        $this->assertStringNotContainsString('mail-secret-pass', $res->getContent());
    }

    public function test_an_unconfigured_smtp_cannot_be_tested(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin)->postJson(self::URL.'/test-connection')->assertOk()->assertJsonPath('data.ok', false);
        $this->actingAs($admin)->postJson(self::URL.'/test-email')->assertOk()->assertJsonPath('data.ok', false);
    }
}
