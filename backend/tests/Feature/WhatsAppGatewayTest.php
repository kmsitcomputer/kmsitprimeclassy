<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\GatewayResult;
use App\Services\WhatsApp\GowaClient;
use App\Services\WhatsApp\MessageTemplates;
use App\Services\WhatsApp\WhatsAppEvents;
use App\Services\WhatsApp\WhatsAppSettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** GOWA adapter, Super Admin console (RBAC, secrets, validation, audit) and the template engine. HTTP is always faked. */
class WhatsAppGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'http://gowa.test';

    private const PASSWORD = 'gowa-secret-PASSWORD-9931';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function configure(?User $by = null, array $over = []): void
    {
        app(WhatsAppSettings::class)->update($over + [
            'enabled' => true, 'base_url' => self::BASE, 'username' => 'primeclassy', 'password' => self::PASSWORD,
            'device_id' => 'dev-1', 'test_numbers' => ['081234567890'],
        ], $by ?? $this->admin());
    }

    /** $this->fake() APPENDS stubs (first match wins); a fresh factory makes each scenario independent. */
    private function fake(array|\Closure $stubs = []): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake($stubs);
    }

    private function client(): GowaClient
    {
        return app(GowaClient::class);
    }

    private function statusFake(array $results = null, int $http = 200): void
    {
        $this->fake([
            self::BASE.'/app/status' => Http::response(['code' => 'SUCCESS', 'results' => $results ?? ['is_connected' => true, 'is_logged_in' => true, 'device_id' => 'dev-1', 'jid' => '628123456789@s.whatsapp.net']], $http),
            self::BASE.'/app/devices' => Http::response(['code' => 'SUCCESS', 'results' => [['id' => 'dev-1']]], 200),
        ]);
    }

    /* ----------------------------------------------------------------- adapter: status */

    public function test_status_connected_uses_basic_auth_and_device_header_and_hides_the_whatsapp_number(): void
    {
        $this->configure();
        $this->statusFake();

        $s = $this->client()->status();

        $this->assertSame('connected', $s['state']);
        $this->assertTrue($s['connected'] && $s['logged_in']);
        $this->assertSame(1, $s['devices']);
        $this->assertStringNotContainsString('628123456789', json_encode($s), 'the paired WhatsApp number (jid) must not leave the adapter');

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('primeclassy:'.self::PASSWORD))
            && $r->hasHeader('X-Device-Id', 'dev-1') && str_ends_with($r->url(), '/app/status'));
    }

    public function test_status_classifies_every_failure_mode(): void
    {
        $this->configure();

        $this->statusFake(['is_connected' => true, 'is_logged_in' => false]);
        $this->assertSame('disconnected', $this->client()->status()['state']);

        $this->fake([self::BASE.'/*' => Http::response(['message' => 'unauthorized'], 401)]);
        $this->assertSame('unauthorized', $this->client()->status()['state']);

        $this->fake([self::BASE.'/*' => Http::response('boom', 500)]);
        $this->assertSame('error', $this->client()->status()['state']);

        $this->fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to gowa.test'));
        $this->assertSame('unreachable', $this->client()->status()['state']);
    }

    public function test_status_is_not_configured_without_credentials_and_makes_no_request(): void
    {
        $this->fake();
        $this->assertSame('not_configured', $this->client()->status()['state']);
        Http::assertNothingSent();
    }

    /* ----------------------------------------------------------------- adapter: send classification */

    public function test_send_text_success_posts_the_documented_payload(): void
    {
        $this->configure();
        $this->fake([self::BASE.'/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => '3EB0ABC']], 200)]);

        $r = $this->client()->sendText('6281234567890', 'Halo');

        $this->assertSame(GatewayResult::SENT, $r->outcome);
        $this->assertSame('3EB0ABC', $r->providerMessageId);
        Http::assertSent(fn (Request $q) => $q['phone'] === '6281234567890@s.whatsapp.net' && $q['message'] === 'Halo');
    }

    public function test_send_text_error_classes_decide_whether_a_retry_is_duplicate_safe(): void
    {
        $this->configure();
        $send = fn () => $this->client()->sendText('6281234567890', 'x');

        // Definite failures: never retried.
        $this->fake([self::BASE.'/*' => Http::response(['message' => 'bad number'], 400)]);
        $this->assertSame([GatewayResult::FAILED, 'rejected'], [$send()->outcome, $send()->errorClass]);
        $this->fake([self::BASE.'/*' => Http::response(['message' => 'nope'], 401)]);
        $this->assertSame([GatewayResult::FAILED, 'auth'], [$send()->outcome, $send()->errorClass]);
        $this->fake([self::BASE.'/*' => Http::response(['message' => 'device not found'], 404)]);
        $this->assertSame('device_not_found', $send()->errorClass);

        // Provably NOT sent: safe to retry.
        $this->fake([self::BASE.'/*' => Http::response(['message' => 'whatsapp client is not connected'], 500)]);
        $this->assertSame([GatewayResult::RETRY, 'device_disconnected'], [$send()->outcome, $send()->errorClass]);
        $this->fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));
        $this->assertSame([GatewayResult::RETRY, 'unreachable'], [$send()->outcome, $send()->errorClass]);

        // Uncertain: NEVER auto-retried (could duplicate).
        $this->fake([self::BASE.'/*' => Http::response(['message' => 'internal error'], 500)]);
        $this->assertSame([GatewayResult::UNKNOWN, 'provider_error'], [$send()->outcome, $send()->errorClass]);
        $this->fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds'));
        $this->assertSame([GatewayResult::UNKNOWN, 'timeout'], [$send()->outcome, $send()->errorClass]);
    }

    public function test_send_without_configuration_fails_cleanly_without_a_request(): void
    {
        $this->fake();
        $r = $this->client()->sendText('6281234567890', 'x');
        $this->assertSame([GatewayResult::FAILED, 'not_configured'], [$r->outcome, $r->errorClass]);
        Http::assertNothingSent();
    }

    public function test_error_text_never_carries_credentials(): void
    {
        $clean = GatewayResult::sanitize('boom Authorization: Basic cHJpbWVjbGFzc3k6c2VjcmV0 and http://user:hunter2@host/x end');
        $this->assertStringNotContainsString('cHJpbWVjbGFzc3k6c2VjcmV0', $clean);
        $this->assertStringNotContainsString('hunter2', $clean);
        $this->assertStringContainsString('[redacted]', $clean);
    }

    /* ----------------------------------------------------------------- console: RBAC + secrets */

    public function test_only_super_admin_reaches_the_console(): void
    {
        $this->configure();
        $msg = WhatsAppMessage::create(['idempotency_key' => 'rbac', 'purpose' => 'transaction', 'status' => 'failed', 'body' => 'x']);
        $routes = [
            ['GET', '/'], ['PUT', '/'], ['POST', '/test-connection'], ['POST', '/test-message'],
            ['POST', '/templates/order_shipped/preview'], ['PUT', '/templates/order_shipped'], ['DELETE', '/templates/order_shipped'],
            ['GET', '/messages'], ['POST', '/messages/'.$msg->id.'/retry'],
        ];

        foreach (['admin', 'agen', 'keuangan', 'sales', 'konsumen', 'korsal', 'gudang'] as $role) {
            $user = User::factory()->{$role}()->create();
            foreach ($routes as [$method, $path]) {
                $this->actingAs($user)->json($method, '/api/v1/admin/integrations/whatsapp'.($path === '/' ? '' : $path), [])
                    ->assertForbidden();
            }
        }
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/integrations/whatsapp')->assertUnauthorized();
        $this->actingAs($this->admin())->getJson('/api/v1/admin/integrations/whatsapp')->assertOk();
    }

    public function test_no_api_ever_returns_the_password_and_it_is_encrypted_at_rest_and_absent_from_the_audit(): void
    {
        $sa = $this->admin();
        $res = $this->actingAs($sa)->putJson('/api/v1/admin/integrations/whatsapp', [
            'enabled' => true, 'base_url' => self::BASE, 'username' => 'primeclassy', 'password' => self::PASSWORD, 'device_id' => 'dev-1',
        ])->assertOk();

        $res->assertJsonPath('data.has_password', true)->assertJsonMissingPath('data.password');
        $this->assertStringNotContainsString(self::PASSWORD, $res->getContent());
        $this->assertStringNotContainsString(self::PASSWORD, $this->actingAs($sa)->getJson('/api/v1/admin/integrations/whatsapp')->getContent());

        $stored = Setting::query()->where('key', 'wa.password')->value('value');
        $this->assertNotSame(self::PASSWORD, $stored);
        $this->assertStringNotContainsString(self::PASSWORD, (string) $stored);
        $this->assertSame(self::PASSWORD, app(WhatsAppSettings::class)->password(), 'round-trips through the app key');

        // Config rows are private: never `is_public`, never leaked through the public/website settings endpoints.
        $this->assertSame(0, Setting::query()->where('group', WhatsAppSettings::GROUP)->where('is_public', true)->count());
        $this->assertStringNotContainsString((string) $stored, $this->getJson('/api/v1/settings')->getContent());
        $this->assertStringNotContainsString('wa.password', $this->actingAs($sa)->getJson('/api/v1/admin/settings')->getContent());

        $audit = ActivityLog::query()->where('event', 'whatsapp.settings.updated')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($audit->properties));
        $this->assertTrue($audit->properties['secret_changed']);
        $this->assertSame($sa->id, $audit->causer_id);

        // An update without a password keeps the stored secret.
        $this->actingAs($sa)->putJson('/api/v1/admin/integrations/whatsapp', ['device_id' => 'dev-2'])->assertOk();
        $this->assertSame(self::PASSWORD, app(WhatsAppSettings::class)->reload()->password());
        $this->assertFalse(ActivityLog::query()->where('event', 'whatsapp.settings.updated')->latest('id')->first()->properties['secret_changed']);
    }

    public function test_configuration_validation(): void
    {
        $sa = $this->admin();
        $put = fn (array $d) => $this->actingAs($sa)->putJson('/api/v1/admin/integrations/whatsapp', $d);

        // Enabling an incomplete gateway is refused.
        $put(['enabled' => true])->assertStatus(422);
        $put(['enabled' => true, 'base_url' => self::BASE, 'username' => 'u'])->assertStatus(422);

        // Unsafe / malformed URLs.
        foreach (['ftp://x.test', 'not a url', 'http://user:pw@gowa.test', 'http://169.254.169.254/latest'] as $bad) {
            $put(['base_url' => $bad])->assertStatus(422);
        }

        $put(['timeout' => 1])->assertStatus(422);
        $put(['otp' => ['ttl_minutes' => 999]])->assertStatus(422);
        $put(['test_numbers' => ['abc']])->assertStatus(422);

        $put(['base_url' => self::BASE.'/', 'test_numbers' => ['0812-3456-7890', '+62 812 3456 7890']])->assertOk()
            ->assertJsonPath('data.base_url', self::BASE)->assertJsonPath('data.test_numbers_count', 1); // normalised + de-duplicated
        $this->assertSame(['6281234567890'], app(WhatsAppSettings::class)->reload()->testNumbers());
    }

    public function test_connection_test_reports_state_and_is_audited_without_secrets(): void
    {
        $sa = $this->admin();
        $this->configure($sa);
        $this->statusFake();

        $this->actingAs($sa)->postJson('/api/v1/admin/integrations/whatsapp/test-connection')
            ->assertOk()->assertJsonPath('data.state', 'connected')->assertJsonMissingPath('data.password');

        $this->fake(fn () => throw new ConnectionException('cURL error 7: refused'));
        $this->actingAs($sa)->postJson('/api/v1/admin/integrations/whatsapp/test-connection')->assertOk()->assertJsonPath('data.state', 'unreachable');
        $this->assertSame(2, ActivityLog::query()->where('event', 'whatsapp.connection.tested')->count());
    }

    /* ----------------------------------------------------------------- console: test message */

    public function test_test_message_only_goes_to_authorised_numbers(): void
    {
        $sa = $this->admin();
        $this->fake([self::BASE.'/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'T1']], 200)]);
        $send = fn (string $n) => $this->actingAs($sa)->postJson('/api/v1/admin/integrations/whatsapp/test-message', ['number' => $n]);

        // Not configured / no allow-list => refused, nothing sent.
        $send('081234567890')->assertStatus(422);
        $this->configure($sa, ['test_numbers' => []]);
        $send('081234567890')->assertStatus(422);

        $this->configure($sa);
        $send('089999999999')->assertStatus(422); // not on the allow-list
        Http::assertNothingSent();

        $res = $send('+62 812-3456-7890')->assertOk()->assertJsonPath('data.status', 'sent')->assertJsonPath('data.purpose', 'test');
        $this->assertStringNotContainsString('6281234567890', $res->getContent(), 'recipient is masked in the API');
        Http::assertSentCount(1);
    }

    /* ----------------------------------------------------------------- templates */

    public function test_template_validation_rejects_unknown_variables_braces_and_control_characters(): void
    {
        $v = fn (string $t) => MessageTemplates::validate(WhatsAppEvents::ORDER_CREATED, $t);

        $v('Halo {nama_pelanggan}, pesanan {nomor_pesanan}');
        foreach (['Halo {resi}' => 'resi', 'Halo {admin_password}' => 'admin_password'] as $t => $var) {
            try {
                $v($t);
                $this->fail("{{$var}} must be refused for order_created");
            } catch (\App\Exceptions\ApiException $e) {
                $this->assertSame(422, $e->status());
            }
        }
        foreach (['Halo {{nama_pelanggan}}', 'Halo {nama_pelanggan', 'Halo }', "Halo\x07", '', str_repeat('a', 1001)] as $bad) {
            try {
                $v($bad);
                $this->fail('template must be refused: '.json_encode($bad));
            } catch (\App\Exceptions\ApiException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_rendering_drops_unavailable_optional_lines_and_never_invents_data(): void
    {
        $tpl = "Halo {nama_pelanggan},\nPesanan {nomor_pesanan} dikirim.\nKurir: {kurir}\nNomor Resi: {resi}\nTerima kasih.";
        $full = MessageTemplates::render(WhatsAppEvents::ORDER_SHIPPED, $tpl, ['nama_pelanggan' => 'Budi', 'nomor_pesanan' => 'PC-1', 'kurir' => 'Andi', 'resi' => 'R-9']);
        $this->assertStringContainsString("Kurir: Andi\nNomor Resi: R-9", $full);

        $noResi = MessageTemplates::render(WhatsAppEvents::ORDER_SHIPPED, $tpl, ['nama_pelanggan' => 'Budi', 'nomor_pesanan' => 'PC-1', 'kurir' => 'Andi', 'resi' => null]);
        $this->assertStringContainsString('Kurir: Andi', $noResi);
        $this->assertStringNotContainsString('Resi', $noResi, 'a missing tracking number drops its line instead of faking one');

        $this->assertNull(MessageTemplates::render(WhatsAppEvents::ORDER_SHIPPED, $tpl, ['nama_pelanggan' => 'Budi', 'nomor_pesanan' => null]), 'a missing REQUIRED variable aborts');
        $this->assertStringContainsString('Halo Pelanggan,', (string) MessageTemplates::render(WhatsAppEvents::ORDER_SHIPPED, $tpl, ['nomor_pesanan' => 'PC-1']));
    }

    public function test_values_cannot_inject_tokens_or_control_characters(): void
    {
        $out = MessageTemplates::render(WhatsAppEvents::ORDER_CREATED, 'Halo {nama_pelanggan}, {nomor_pesanan}', [
            'nama_pelanggan' => "{nomor_pesanan}\x07\n{{x}}", 'nomor_pesanan' => 'PC-1',
        ]);

        $this->assertSame('Halo (nomor_pesanan) ((x)), PC-1', $out, 'braces neutralised, control chars flattened');
        $this->assertSame(200, mb_strlen((string) MessageTemplates::clean(str_repeat('é', 500))));
    }

    public function test_template_endpoints_preview_save_reset_and_audit(): void
    {
        $sa = $this->admin();
        $base = '/api/v1/admin/integrations/whatsapp/templates/order_shipped';

        $this->actingAs($sa)->postJson($base.'/preview', ['template' => 'Halo {nama_pelanggan}, resi {resi}'])
            ->assertOk()->assertJsonPath('data.preview', 'Halo Budi Santoso, resi RESI-CONTOH-001');
        $this->actingAs($sa)->postJson($base.'/preview', ['template' => 'Halo {sandi}'])->assertStatus(422);
        $this->actingAs($sa)->postJson('/api/v1/admin/integrations/whatsapp/templates/bogus/preview', ['template' => 'x'])->assertNotFound();

        $this->actingAs($sa)->putJson($base, ['template' => 'Dikirim {nomor_pesanan}'])->assertOk();
        $this->assertSame('Dikirim {nomor_pesanan}', app(WhatsAppSettings::class)->template('order_shipped'));
        $this->actingAs($sa)->putJson($base, ['template' => 'Bad {x}'])->assertStatus(422);
        $this->assertSame('Dikirim {nomor_pesanan}', app(WhatsAppSettings::class)->reload()->template('order_shipped'));

        $log = ActivityLog::query()->where('event', 'whatsapp.template.updated')->firstOrFail();
        $this->assertSame('Dikirim {nomor_pesanan}', $log->properties['new']['template']);
        $this->assertSame(WhatsAppEvents::all()['order_shipped']['default'], $log->properties['old']['template']);

        $this->actingAs($sa)->deleteJson($base)->assertOk();
        $this->assertSame(WhatsAppEvents::all()['order_shipped']['default'], app(WhatsAppSettings::class)->reload()->template('order_shipped'));
        $this->assertSame(1, ActivityLog::query()->where('event', 'whatsapp.template.reset')->count());
    }

    public function test_every_event_has_a_valid_default_template_and_no_dikemas_event_exists(): void
    {
        foreach (WhatsAppEvents::all() as $key => $def) {
            MessageTemplates::validate($key, $def['default']);
            $this->assertNotNull(MessageTemplates::render($key, $def['default'], WhatsAppEvents::sample()), $key);
        }
        $this->assertNotContains('order_packed', WhatsAppEvents::keys(), 'no "dikemas" status exists in the order lifecycle');
    }

    public function test_defaults_send_nothing_until_a_super_admin_switches_events_on(): void
    {
        $this->configure();
        $s = app(WhatsAppSettings::class)->reload();
        foreach (WhatsAppEvents::keys() as $event) {
            $this->assertFalse($s->eventEnabled($event), "$event must default to OFF");
        }
        $this->assertFalse($s->otp()['enabled']);
        $this->assertSame(0, WhatsAppMessage::query()->count());
    }

    public function test_messages_endpoint_lists_masked_rows_and_filters(): void
    {
        $sa = $this->admin();
        WhatsAppMessage::create(['idempotency_key' => 'a', 'purpose' => 'transaction', 'event' => 'order_shipped', 'recipient_phone' => '6281234567890', 'body' => 'x', 'status' => 'failed', 'error_class' => 'rejected']);
        WhatsAppMessage::create(['idempotency_key' => 'b', 'purpose' => 'otp', 'event' => 'otp', 'recipient_phone' => '6281234567891', 'body' => 'kode ••••••', 'secret_body' => 'kode 123456', 'status' => 'sent']);

        $res = $this->actingAs($sa)->getJson('/api/v1/admin/integrations/whatsapp/messages')->assertOk();
        $this->assertCount(2, $res->json('data.data'));
        $this->assertStringNotContainsString('62812345678', $res->getContent());
        $this->assertStringNotContainsString('123456', $res->getContent(), 'the encrypted OTP original is never serialised');

        $this->assertCount(1, $this->actingAs($sa)->getJson('/api/v1/admin/integrations/whatsapp/messages?status=failed')->json('data.data'));
        $this->assertCount(1, $this->actingAs($sa)->getJson('/api/v1/admin/integrations/whatsapp/messages?purpose=otp')->json('data.data'));
    }
}
