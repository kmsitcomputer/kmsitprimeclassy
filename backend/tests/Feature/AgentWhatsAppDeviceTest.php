<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AgentWhatsAppDevice;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppDelivery;
use App\Services\WhatsApp\WhatsAppSettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** One central GOWA server, one isolated device per Agent. The REAL AgentDeviceService is used; HTTP is faked, never real. */
class AgentWhatsAppDeviceTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'http://gowa.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function agent(): User
    {
        $a = User::factory()->agen()->create();
        $a->update(['agent_id' => $a->id]);

        return $a->refresh();
    }

    private function configure(bool $enabled = true): void
    {
        app(WhatsAppSettings::class)->update([
            'enabled' => $enabled, 'base_url' => self::BASE, 'username' => 'gowa-user', 'password' => 'gowa-secret-pass', 'max_attempts' => 3, 'backoff_seconds' => 10,
        ], User::factory()->superAdmin()->create());
    }

    /** @param  array<string,mixed>  $extra */
    private function gateway(array $extra = []): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake($extra + [
            self::BASE.'/devices' => Http::response(['code' => 'SUCCESS', 'results' => ['device_id' => 'x']], 200),
            self::BASE.'/devices/*/status' => Http::response(['code' => 'SUCCESS', 'results' => ['is_connected' => false, 'is_logged_in' => false]], 200),
            self::BASE.'/devices/*/login/code*' => Http::response(['code' => 'SUCCESS', 'results' => ['pair_code' => 'ABCD-1234']], 200),
            self::BASE.'/devices/*/login' => Http::response(['code' => 'SUCCESS', 'results' => ['qr_link' => 'http://localhost:3000/statics/qrcode/scan-qr-1.png', 'qr_duration' => 30]], 200),
            self::BASE.'/statics/qrcode/*' => Http::response('PNGBYTES', 200, ['Content-Type' => 'image/png']),
            self::BASE.'/devices/*/reconnect' => Http::response(['code' => 'SUCCESS'], 200),
            self::BASE.'/devices/*/logout' => Http::response(['code' => 'SUCCESS'], 200),
            self::BASE.'/devices/*' => Http::response(['code' => 'SUCCESS', 'results' => ['phone_number' => '6281399900077']], 200),
            self::BASE.'/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'WA-1']], 200),
        ]);
    }

    private function pair(User $agent, string $deviceId): AgentWhatsAppDevice
    {
        return AgentWhatsAppDevice::create([
            'agent_id' => $agent->id, 'device_id' => $deviceId, 'state' => 'connected', 'paired_at' => now(), 'last_checked_at' => now(),
        ]);
    }

    private function queued(User $agent, string $phone = '6281300000001'): WhatsAppMessage
    {
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agent->id, 'phone' => '0'.substr($phone, 2)]);
        \App\Models\IdentityOtpChallenge::create([
            'agent_id' => $agent->id, 'subject_user_id' => $konsumen->id, 'created_by' => $konsumen->id, 'purpose' => 'whatsapp_number',
            'code_hash' => str_repeat('0', 64), 'sent_to_email' => 'wa:'.$phone, 'attempts' => 1, 'max_attempts' => 5,
            'expires_at' => now()->addMinutes(5), 'verified_at' => now(), 'consumed_at' => now(),
        ]);

        return app(WhatsAppDelivery::class)->enqueue([
            'idempotency_key' => 'k:'.bin2hex(random_bytes(5)), 'purpose' => 'transaction', 'event' => 'order_shipped', 'agent_id' => $agent->id,
            'recipient_user_id' => $konsumen->id, 'recipient_phone' => $phone, 'body' => 'Halo',
        ], dispatch: false);
    }

    /* ----------------------------------------------------------------- RBAC: only the Agen, only itself */

    public function test_only_an_agen_can_reach_the_device_endpoints(): void
    {
        $this->configure();
        $this->gateway();

        // 'admin' is deliberately absent: an Admin Agen WITH a branch may manage its branch Agen (WhatsAppAgentScopeTest); an unbranched one is refused there.
        foreach (['superAdmin', 'keuangan', 'korsal', 'sales', 'kurir', 'konsumen'] as $role) {
            $u = User::factory()->$role()->create();
            foreach ([['getJson', '/api/v1/agent/whatsapp'], ['postJson', '/api/v1/agent/whatsapp/device'], ['postJson', '/api/v1/agent/whatsapp/pair/qr'],
                ['postJson', '/api/v1/agent/whatsapp/replace'], ['getJson', '/api/v1/agent/whatsapp/messages']] as [$m, $url]) {
                $this->actingAs($u)->$m($url)->assertForbidden();
            }
        }
        Http::assertNothingSent();
    }

    public function test_status_never_exposes_gateway_secrets_or_the_slot_id(): void
    {
        $this->configure();
        $this->gateway();
        $a = $this->agent();
        $this->pair($a, 'pc-agent-secret-slot');

        $body = $this->actingAs($a)->getJson('/api/v1/agent/whatsapp')->assertOk()->assertJsonPath('data.global_enabled', true)->getContent();
        foreach (['gowa-secret-pass', 'gowa-user', 'pc-agent-secret-slot', 'gowa.test', 'localhost:3000'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    /* ----------------------------------------------------------------- device lifecycle */

    public function test_creating_a_device_is_refused_until_the_central_gateway_is_configured(): void
    {
        $this->gateway();
        $this->actingAs($this->agent())->postJson('/api/v1/agent/whatsapp/device')->assertStatus(409)->assertJsonPath('errors.code', 'whatsapp_gateway_unconfigured');
        $this->assertSame(0, AgentWhatsAppDevice::query()->count());
    }

    public function test_one_slot_per_agent_and_the_second_request_is_idempotent(): void
    {
        $this->configure();
        $this->gateway();
        $a = $this->agent();

        $this->actingAs($a)->postJson('/api/v1/agent/whatsapp/device')->assertCreated()->assertJsonPath('data.device.state', 'not_paired');
        $this->actingAs($a)->postJson('/api/v1/agent/whatsapp/device')->assertCreated();

        $this->assertSame(1, AgentWhatsAppDevice::query()->where('agent_id', $a->id)->count());
        $this->assertMatchesRegularExpression('/^pc-agent-'.$a->id.'-[0-9a-f]{8}$/', AgentWhatsAppDevice::firstOrFail()->device_id);
        // exactly ONE slot was created on the gateway (the other requests are read-only status refreshes)
        $creations = collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'POST' && str_ends_with($pair[0]->url(), '/devices'));
        $this->assertCount(1, $creations);
    }

    public function test_qr_pairing_returns_a_server_built_image_and_nothing_of_the_gateway(): void
    {
        $this->configure();
        $this->gateway();
        $a = $this->agent();

        $res = $this->actingAs($a)->postJson('/api/v1/agent/whatsapp/pair/qr')->assertOk()->assertJsonPath('data.expires_in', 30)->assertJsonPath('data.device.state', 'pairing');
        $this->assertStringStartsWith('data:image/png;base64,', $res->json('data.image'));
        $this->assertSame('PNGBYTES', base64_decode(substr($res->json('data.image'), strlen('data:image/png;base64,'))));
        $this->assertStringNotContainsString('qr_link', $res->getContent());
        $this->assertStringNotContainsString('localhost:3000', $res->getContent());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/statics/qrcode/') && str_starts_with($r->url(), self::BASE));
    }

    public function test_a_qr_link_that_is_not_the_qr_image_path_is_never_fetched(): void
    {
        $this->configure();
        $this->gateway([self::BASE.'/devices/*/login' => Http::response(['code' => 'SUCCESS', 'results' => ['qr_link' => 'http://127.0.0.1:9999/internal/secrets', 'qr_duration' => 30]], 200)]);

        $this->actingAs($this->agent())->postJson('/api/v1/agent/whatsapp/pair/qr')->assertStatus(502);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/internal/secrets'));
    }

    public function test_a_gateway_that_forgot_the_slot_answers_500_not_found_and_the_slot_is_no_longer_trusted_as_paired(): void
    {
        $this->configure();
        $this->gateway([self::BASE.'/devices/*/status' => Http::response(['code' => 'INTERNAL_SERVER_ERROR', 'message' => 'device pc-agent-x-1 not found'], 500)]);
        $a = $this->agent();
        $this->pair($a, 'pc-agent-x-1');

        $this->actingAs($a)->getJson('/api/v1/agent/whatsapp')->assertOk()->assertJsonPath('data.device.state', 'not_paired');
        $this->assertNull(AgentWhatsAppDevice::firstOrFail()->paired_at, 'an unknown slot must not be used for sending');
        $this->assertNull(app(\App\Services\WhatsApp\AgentDeviceService::class)->sendingDeviceId($a->id));
    }

    public function test_only_png_files_under_the_qr_directories_are_ever_fetched(): void
    {
        $this->configure();
        foreach (['/statics/qrcode/../../etc/passwd.png', '/statics/qrcode/readme.txt', '/other/scan.png'] as $bad) {
            $this->gateway([self::BASE.'/devices/*/login' => Http::response(['code' => 'SUCCESS', 'results' => ['qr_link' => 'http://x'.$bad, 'qr_duration' => 30]], 200)]);
            $this->actingAs($this->agent())->postJson('/api/v1/agent/whatsapp/pair/qr')->assertStatus(502);
        }
    }
    public function test_pairing_code_is_supported_and_validates_the_number(): void
    {
        $this->configure();
        $this->gateway();
        $a = $this->agent();

        $this->actingAs($a)->postJson('/api/v1/agent/whatsapp/pair/code', ['phone' => 'abc'])->assertStatus(422);
        $this->actingAs($a)->postJson('/api/v1/agent/whatsapp/pair/code', ['phone' => '0813 9990 0077'])->assertOk()->assertJsonPath('data.code', 'ABCD-1234');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/login/code') && str_contains($r->url(), 'phone=6281399900077'));
        // The number being paired is not written to the audit trail.
        $this->assertStringNotContainsString('81399900077', json_encode(ActivityLog::query()->get()->toArray()));
    }

    public function test_a_connected_gateway_marks_the_slot_paired_and_exposes_only_a_masked_number(): void
    {
        $this->configure();
        $this->gateway([self::BASE.'/devices/*/status' => Http::response(['code' => 'SUCCESS', 'results' => ['is_connected' => true, 'is_logged_in' => true]], 200)]);
        $a = $this->agent();
        AgentWhatsAppDevice::create(['agent_id' => $a->id, 'device_id' => 'pc-agent-x-1', 'state' => 'pairing']);

        $res = $this->actingAs($a)->getJson('/api/v1/agent/whatsapp')->assertOk()->assertJsonPath('data.device.state', 'connected');
        $this->assertNotNull(AgentWhatsAppDevice::firstOrFail()->paired_at);
        $this->assertStringNotContainsString('6281399900077', $res->getContent());
        $this->assertNotNull($res->json('data.device.phone_masked'));
        // Already connected: a new QR is refused instead of disturbing a working session.
        $this->actingAs($a)->postJson('/api/v1/agent/whatsapp/pair/qr')->assertStatus(409)->assertJsonPath('errors.code', 'whatsapp_device_connected');
    }

    public function test_reconnect_and_logout_act_on_the_agents_own_slot_and_logout_keeps_the_slot(): void
    {
        $this->configure();
        $this->gateway();
        $a = $this->agent();
        $this->pair($a, 'pc-agent-own-1');

        $this->actingAs($a)->postJson('/api/v1/agent/whatsapp/reconnect')->assertOk();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/devices/pc-agent-own-1/reconnect'));

        $this->actingAs($a)->postJson('/api/v1/agent/whatsapp/logout')->assertOk()->assertJsonPath('data.device.state', 'not_paired');
        $row = AgentWhatsAppDevice::firstOrFail();
        $this->assertSame('pc-agent-own-1', $row->device_id, 'logout keeps the slot');
        $this->assertNull($row->paired_at, 'a logged-out slot is not used for sending');
    }

    public function test_replacement_needs_explicit_confirmation_and_keeps_exactly_one_slot(): void
    {
        $this->configure();
        $this->gateway();
        $a = $this->agent();
        $this->pair($a, 'pc-agent-old-1');

        $this->actingAs($a)->postJson('/api/v1/agent/whatsapp/replace')->assertStatus(422);
        $this->assertSame('pc-agent-old-1', AgentWhatsAppDevice::firstOrFail()->device_id);

        $this->actingAs($a)->postJson('/api/v1/agent/whatsapp/replace', ['confirm' => true])->assertOk()->assertJsonPath('data.device.state', 'not_paired')->assertJsonPath('data.device.replaced_count', 1);

        $this->assertSame(1, AgentWhatsAppDevice::query()->where('agent_id', $a->id)->count());
        $new = AgentWhatsAppDevice::firstOrFail();
        $this->assertNotSame('pc-agent-old-1', $new->device_id);
        $this->assertNull($new->paired_at);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/devices/pc-agent-old-1'));
        $this->assertTrue(ActivityLog::query()->where('event', 'whatsapp.device.replaced')->exists());
    }

    /* ----------------------------------------------------------------- isolation and routing */

    public function test_agents_cannot_see_or_affect_each_others_device_or_history(): void
    {
        $this->configure();
        $this->gateway();
        $a = $this->agent();
        $b = $this->agent();
        $this->pair($a, 'pc-agent-A-1');
        $this->pair($b, 'pc-agent-B-1');
        $this->queued($a);

        $this->assertStringNotContainsString('A-1', $this->actingAs($b)->getJson('/api/v1/agent/whatsapp')->assertOk()->getContent());
        $this->assertCount(0, $this->actingAs($b)->getJson('/api/v1/agent/whatsapp/messages')->assertOk()->json('data'));
        $this->assertCount(1, $this->actingAs($a)->getJson('/api/v1/agent/whatsapp/messages')->assertOk()->json('data'));

        // B's logout touches B's slot only.
        $this->actingAs($b)->postJson('/api/v1/agent/whatsapp/logout')->assertOk();
        $this->assertNotNull(AgentWhatsAppDevice::where('agent_id', $a->id)->firstOrFail()->paired_at);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'pc-agent-A-1/logout'));
    }

    public function test_every_message_leaves_through_its_own_agents_slot_and_never_through_another(): void
    {
        $this->configure();
        $this->gateway();
        $a = $this->agent();
        $b = $this->agent();
        $this->pair($a, 'pc-agent-A-1');
        $this->pair($b, 'pc-agent-B-1');

        $ma = $this->queued($a, '6281300000011');
        $mb = $this->queued($b, '6281300000012');
        app(WhatsAppDelivery::class)->deliver($ma->id);
        app(WhatsAppDelivery::class)->deliver($mb->id);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/send/message') && $r->hasHeader('X-Device-Id', 'pc-agent-A-1') && str_contains($r['phone'], '6281300000011'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/send/message') && $r->hasHeader('X-Device-Id', 'pc-agent-B-1') && str_contains($r['phone'], '6281300000012'));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/send/message') && $r->hasHeader('X-Device-Id', 'pc-agent-A-1') && str_contains($r['phone'], '6281300000012'));
    }

    public function test_an_agent_without_a_paired_device_sends_nothing_and_does_not_borrow_another_one(): void
    {
        $this->configure();
        $this->gateway();
        $a = $this->agent();
        $b = $this->agent();
        $this->pair($b, 'pc-agent-B-1');
        AgentWhatsAppDevice::create(['agent_id' => $a->id, 'device_id' => 'pc-agent-A-unpaired', 'state' => 'not_paired']); // exists, never paired

        $m = $this->queued($a);
        $done = app(WhatsAppDelivery::class)->deliver($m->id);

        $this->assertSame('skipped', $done->status);
        $this->assertSame('agent_device_not_ready', $done->skip_reason);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/send/message'));
    }

    public function test_global_off_stops_sending_without_touching_sessions_and_global_on_neither_resends_nor_enables_events(): void
    {
        $this->configure();
        $this->gateway();
        $a = $this->agent();
        $device = $this->pair($a, 'pc-agent-A-1');
        $queued = $this->queued($a);

        // Super Admin switches WhatsApp OFF globally.
        app(WhatsAppSettings::class)->update(['enabled' => false], User::factory()->superAdmin()->create());
        $off = app(WhatsAppDelivery::class)->deliver($queued->id);
        $this->assertSame('skipped', $off->status);
        $this->assertSame('gateway_disabled', $off->skip_reason);
        $this->assertSame('connected', $device->fresh()->state, 'the session and the slot are kept');
        $this->assertNotNull($device->fresh()->paired_at);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/send/message'));

        // ...and ON again: nothing old is resent, no event is switched on, no device is touched.
        app(WhatsAppSettings::class)->update(['enabled' => true], User::factory()->superAdmin()->create());
        $this->assertSame('skipped', $queued->fresh()->status);
        $this->assertSame([], collect(app(WhatsAppSettings::class)->reload()->snapshot()['events'])->where('enabled', true)->pluck('key')->all());
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/send/message'));
    }

    public function test_number_verification_is_offered_only_when_the_users_agent_has_a_paired_device(): void
    {
        $this->configure();
        app(WhatsAppSettings::class)->update(['otp' => ['enabled' => true]], User::factory()->superAdmin()->create());
        $this->gateway();
        $a = $this->agent();
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $a->id, 'phone' => '081377700001']);

        $this->actingAs($konsumen)->getJson('/api/v1/profile/whatsapp')->assertOk()->assertJsonPath('data.channel_available', false);
        $this->actingAs($konsumen)->postJson('/api/v1/profile/whatsapp/verification')->assertStatus(409)->assertJsonPath('errors.code', 'whatsapp_otp_unavailable');

        $this->pair($a, 'pc-agent-A-1');
        $this->actingAs($konsumen)->getJson('/api/v1/profile/whatsapp')->assertOk()->assertJsonPath('data.channel_available', true);
        Queue::fake();
        $this->actingAs($konsumen)->postJson('/api/v1/profile/whatsapp/verification')->assertCreated();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/send/message') && $r->hasHeader('X-Device-Id', 'pc-agent-A-1'));
    }
}
