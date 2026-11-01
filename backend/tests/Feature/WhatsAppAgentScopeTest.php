<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AgentWhatsAppDevice;
use App\Models\AgentWhatsAppEventSetting;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppSettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * WA-DEC-01..04: an Agen manages its own WhatsApp, an Admin Agen manages ONLY its branch Agen, Super Admin and everyone else are
 * refused; events/templates are one scoped record per Agen; test-send needs a registered number and the Agen's own paired slot.
 * The real services run; HTTP is faked and nothing real is ever sent.
 */
class WhatsAppAgentScopeTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'http://gowa.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->configure();
        $this->gateway();
    }

    private function agent(): User
    {
        $a = User::factory()->agen()->create();
        $a->update(['agent_id' => $a->id]);

        return $a->refresh();
    }

    private function adminOf(User $agen): User
    {
        return User::factory()->admin()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
    }

    private function configure(bool $enabled = true): void
    {
        app(WhatsAppSettings::class)->update([
            'enabled' => $enabled, 'base_url' => self::BASE, 'username' => 'gowa-user', 'password' => 'gowa-secret-pass', 'max_attempts' => 3, 'backoff_seconds' => 10,
        ], User::factory()->superAdmin()->create());
    }

    private function gateway(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            self::BASE.'/devices' => Http::response(['code' => 'SUCCESS', 'results' => ['device_id' => 'x']], 200),
            self::BASE.'/devices/*/status' => Http::response(['code' => 'SUCCESS', 'results' => ['is_connected' => true, 'is_logged_in' => true]], 200),
            self::BASE.'/devices/*/reconnect' => Http::response(['code' => 'SUCCESS'], 200),
            self::BASE.'/devices/*/logout' => Http::response(['code' => 'SUCCESS'], 200),
            self::BASE.'/devices/*' => Http::response(['code' => 'SUCCESS', 'results' => ['phone_number' => '6281399900077']], 200),
            self::BASE.'/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'WA-1']], 200),
        ]);
    }

    private function pair(User $agent, string $deviceId): AgentWhatsAppDevice
    {
        return AgentWhatsAppDevice::create(['agent_id' => $agent->id, 'device_id' => $deviceId, 'state' => 'connected', 'paired_at' => now(), 'last_checked_at' => now()]);
    }

    // ---- WA-DEC-01: scope -------------------------------------------------------------------------------------

    public function test_admin_agen_manages_its_branch_agen_device_and_the_audit_names_actor_and_target(): void
    {
        $agen = $this->agent();
        $admin = $this->adminOf($agen);
        $this->pair($agen, 'pc-agent-branch-slot');

        $this->actingAs($admin)->getJson('/api/v1/agent/whatsapp')->assertOk()->assertJsonPath('data.agent.id', $agen->id)->assertJsonPath('data.device.state', 'connected');
        $this->actingAs($admin)->postJson('/api/v1/agent/whatsapp/reconnect')->assertOk();
        $this->actingAs($admin)->postJson('/api/v1/agent/whatsapp/logout')->assertOk();
        $this->actingAs($admin)->postJson('/api/v1/agent/whatsapp/replace', ['confirm' => true])->assertOk();

        $log = ActivityLog::where('event', 'whatsapp.device.replaced')->latest('id')->first();
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertSame($agen->id, $log->properties['target_agent_id']);
        $this->assertSame($admin->id, $log->properties['actor_id']);
        // Ownership is unchanged: still the Agen's single slot.
        $this->assertSame(1, AgentWhatsAppDevice::where('agent_id', $agen->id)->count());
        $this->assertSame(0, AgentWhatsAppDevice::where('agent_id', $admin->id)->count());
    }

    public function test_replace_still_requires_explicit_confirmation_for_an_admin(): void
    {
        $agen = $this->agent();
        $this->pair($agen, 'pc-agent-x');
        $this->actingAs($this->adminOf($agen))->postJson('/api/v1/agent/whatsapp/replace')->assertStatus(422);
    }

    public function test_a_client_supplied_agent_id_can_never_widen_the_scope(): void
    {
        $a = $this->agent();
        $b = $this->agent();
        $this->pair($b, 'pc-agent-b-slot');
        $adminA = $this->adminOf($a);

        $this->actingAs($adminA)->getJson('/api/v1/agent/whatsapp?agent_id='.$b->id)->assertForbidden();
        $this->actingAs($adminA)->postJson('/api/v1/agent/whatsapp/logout', ['agent_id' => $b->id])->assertForbidden();
        $this->actingAs($adminA)->postJson('/api/v1/agent/whatsapp/replace', ['agent_id' => $b->id, 'confirm' => true])->assertForbidden();
        $this->actingAs($a)->getJson('/api/v1/agent/whatsapp?agent_id='.$b->id)->assertForbidden();
        $this->actingAs($a)->getJson('/api/v1/agent/whatsapp?agent_id=abc')->assertForbidden();

        $this->assertSame('connected', AgentWhatsAppDevice::where('agent_id', $b->id)->value('state'));
        Http::assertNothingSent();
        // Naming the own authorised Agen explicitly is fine.
        $this->actingAs($adminA)->getJson('/api/v1/agent/whatsapp?agent_id='.$a->id)->assertOk();
    }

    public function test_super_admin_unbranched_admin_and_other_roles_are_refused(): void
    {
        $this->pair($this->agent(), 'pc-agent-q');
        foreach (['superAdmin', 'admin', 'keuangan', 'korsal', 'sales', 'kurir', 'konsumen', 'gudang'] as $role) {
            $u = User::factory()->$role()->create(); // an `admin` here has no branch Agen
            foreach ([['getJson', '/api/v1/agent/whatsapp'], ['postJson', '/api/v1/agent/whatsapp/pair/qr'], ['getJson', '/api/v1/agent/whatsapp/events'],
                ['postJson', '/api/v1/agent/whatsapp/test-send'], ['getJson', '/api/v1/agent/whatsapp/test-numbers']] as [$m, $url]) {
                $this->actingAs($u)->$m($url)->assertForbidden();
            }
        }
        Http::assertNothingSent();
    }

    public function test_scope_endpoint_lists_only_the_authorised_agen(): void
    {
        $a = $this->agent();
        $this->agent();
        $admin = $this->adminOf($a);

        $this->assertSame([$a->id], array_column($this->actingAs($admin)->getJson('/api/v1/agent/whatsapp/scope')->assertOk()->json('data.agents'), 'id'));
        $this->assertSame([$a->id], array_column($this->actingAs($a)->getJson('/api/v1/agent/whatsapp/scope')->json('data.agents'), 'id'));
    }

    // ---- WA-DEC-02: events and templates ----------------------------------------------------------------------

    public function test_agen_and_admin_edit_one_authoritative_record_and_agens_are_isolated(): void
    {
        $a = $this->agent();
        $b = $this->agent();
        $admin = $this->adminOf($a);

        $this->actingAs($admin)->putJson('/api/v1/agent/whatsapp/events/order_shipped', ['enabled' => true, 'template' => "Halo {nama_pelanggan}, {nomor_pesanan} meluncur dari {nama_toko}."])->assertOk();
        $this->actingAs($a)->putJson('/api/v1/agent/whatsapp/events/order_shipped', ['template' => "Pesanan {nomor_pesanan} dikirim."])->assertOk();

        $this->assertSame(1, AgentWhatsAppEventSetting::where('agent_id', $a->id)->where('event', 'order_shipped')->count());
        $row = AgentWhatsAppEventSetting::where('agent_id', $a->id)->first();
        $this->assertTrue($row->enabled);                       // the Admin's switch survived the Agen's template edit
        $this->assertSame($a->id === $row->updated_by ? $a->id : $admin->id, $row->updated_by);

        $mine = collect($this->actingAs($a)->getJson('/api/v1/agent/whatsapp/events')->json('data'))->firstWhere('key', 'order_shipped');
        $this->assertSame('Pesanan {nomor_pesanan} dikirim.', $mine['template']);
        $other = collect($this->actingAs($b)->getJson('/api/v1/agent/whatsapp/events')->json('data'))->firstWhere('key', 'order_shipped');
        $this->assertFalse($other['enabled']);
        $this->assertSame('default', $other['template_source']);
    }

    public function test_templates_are_validated_against_the_event_allow_list_and_cannot_reach_other_data(): void
    {
        $a = $this->agent();
        foreach (['Total {password}', 'Kode {otp}', '{{nomor_pesanan}}', 'x{nomor_pesanan', '', str_repeat('a', 1001)] as $bad) {
            $this->actingAs($a)->putJson('/api/v1/agent/whatsapp/events/order_created', ['template' => $bad])->assertStatus(422);
        }
        // order_created has no payment variables: an order-status template cannot pull money fields it was not given.
        $this->actingAs($a)->putJson('/api/v1/agent/whatsapp/events/order_created', ['template' => 'Sisa {sisa_tagihan}'])->assertStatus(422);
        $this->assertSame(0, AgentWhatsAppEventSetting::count());
    }

    public function test_otp_identity_and_registration_traffic_is_not_configurable(): void
    {
        $a = $this->agent();
        foreach (['otp', 'whatsapp_number', 'account_registered', 'recovery', 'identity_otp'] as $event) {
            $this->actingAs($a)->putJson("/api/v1/agent/whatsapp/events/$event", ['enabled' => false])->assertStatus(422);
            $this->actingAs($a)->postJson("/api/v1/agent/whatsapp/events/$event/preview", ['template' => 'x'])->assertStatus(422);
        }
        $keys = array_column($this->actingAs($a)->getJson('/api/v1/agent/whatsapp/events')->json('data'), 'key');
        $this->assertNotContains('otp', $keys);
        $this->assertNotContains('account_registered', $keys);
    }

    public function test_legacy_global_settings_remain_the_fallback_and_an_agen_row_overrides_them(): void
    {
        $a = $this->agent();
        $s = app(WhatsAppSettings::class);
        $s->update(['events' => ['order_created' => true]], User::factory()->superAdmin()->create());

        $this->assertTrue($s->reload()->eventEnabledFor('order_created', $a->id));  // no row → inherits what Super Admin had enabled
        $this->actingAs($a)->putJson('/api/v1/agent/whatsapp/events/order_created', ['enabled' => false])->assertOk();
        $this->assertFalse($s->reload()->eventEnabledFor('order_created', $a->id));  // Agen's own decision wins
        $this->assertTrue($s->eventEnabledFor('order_created', $this->agent()->id)); // another Agen unaffected

        $this->actingAs($a)->deleteJson('/api/v1/agent/whatsapp/events/order_created/template')->assertOk();
        $this->assertStringContainsString('berhasil dibuat', $s->templateFor('order_created', $a->id));
    }

    public function test_preview_uses_sample_data_only(): void
    {
        $r = $this->actingAs($this->agent())->postJson('/api/v1/agent/whatsapp/events/order_created/preview', ['template' => 'Halo {nama_pelanggan} {nomor_pesanan}'])->assertOk();
        $this->assertSame('Halo Budi Santoso PC-261028-000123', $r->json('data.preview'));
    }

    // ---- WA-DEC-03: test numbers and test-send ----------------------------------------------------------------

    private function register(User $as, string $number = '081300000001')
    {
        return $this->actingAs($as)->postJson('/api/v1/agent/whatsapp/test-numbers', ['number' => $number, 'confirm_authorized' => true]);
    }

    public function test_test_numbers_need_attestation_are_capped_masked_and_per_agen(): void
    {
        $a = $this->agent();
        $b = $this->agent();

        $this->actingAs($a)->postJson('/api/v1/agent/whatsapp/test-numbers', ['number' => '081300000001'])->assertStatus(422);
        $this->actingAs($a)->postJson('/api/v1/agent/whatsapp/test-numbers', ['number' => 'abc', 'confirm_authorized' => true])->assertStatus(422);

        $list = $this->register($a)->assertOk()->getContent();
        $this->assertStringNotContainsString('6281300000001', $list);
        $this->register($a)->assertOk(); // idempotent
        $this->assertCount(1, $this->actingAs($a)->getJson('/api/v1/agent/whatsapp/test-numbers')->json('data.numbers'));
        $this->assertCount(0, $this->actingAs($b)->getJson('/api/v1/agent/whatsapp/test-numbers')->json('data.numbers'));

        foreach (['081300000002', '081300000003', '081300000004', '081300000005'] as $n) {
            $this->register($a, $n)->assertOk();
        }
        $this->register($a, '081300000006')->assertStatus(422);

        $id = $this->actingAs($a)->getJson('/api/v1/agent/whatsapp/test-numbers')->json('data.numbers.0.id');
        $this->actingAs($b)->deleteJson("/api/v1/agent/whatsapp/test-numbers/$id")->assertOk(); // not b's: no effect
        $this->assertCount(5, $this->actingAs($a)->getJson('/api/v1/agent/whatsapp/test-numbers')->json('data.numbers'));
        $this->actingAs($a)->deleteJson("/api/v1/agent/whatsapp/test-numbers/$id")->assertOk();
        $this->assertCount(4, $this->actingAs($a)->getJson('/api/v1/agent/whatsapp/test-numbers')->json('data.numbers'));
    }

    public function test_test_send_uses_exactly_the_target_agens_paired_slot_and_a_registered_number(): void
    {
        $a = $this->agent();
        $b = $this->agent();
        $this->pair($a, 'pc-agent-A-slot');
        $this->pair($b, 'pc-agent-B-slot');
        $admin = $this->adminOf($a);
        $this->register($admin)->assertOk();

        $r = $this->actingAs($admin)->postJson('/api/v1/agent/whatsapp/test-send', ['number' => '081300000001', 'message' => 'Uji'])->assertOk();
        $this->assertSame('sent', $r->json('data.status'));
        $this->assertStringNotContainsString('6281300000001', $r->getContent());
        $this->assertStringNotContainsString('gowa-secret-pass', $r->getContent());

        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/send/message') && $req->header('X-Device-Id') === ['pc-agent-A-slot']);
        Http::assertNotSent(fn (Request $req) => $req->hasHeader('X-Device-Id') && in_array('pc-agent-B-slot', $req->header('X-Device-Id'), true));

        $m = WhatsAppMessage::where('event', 'test')->first();
        $this->assertSame($a->id, $m->agent_id);
        $this->assertSame('pc-agent-A-slot', $m->sender_device_id);
        $log = ActivityLog::where('event', 'whatsapp.agent_test.sent')->first();
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertSame($a->id, $log->properties['target_agent_id']);
        $this->assertStringNotContainsString('6281300000001', json_encode($log->properties));
    }

    public function test_test_send_fails_closed_without_fallback(): void
    {
        $a = $this->agent();
        $b = $this->agent();
        $this->pair($b, 'pc-agent-B-slot'); // somebody else has a device; A has none
        $this->register($a)->assertOk();

        // Unregistered number (even though syntactically valid and registered for ANOTHER agen).
        $this->register($b, '081399999999')->assertOk();
        $this->actingAs($a)->postJson('/api/v1/agent/whatsapp/test-send', ['number' => '081399999999'])->assertStatus(422);
        // Registered number but the Agen has no paired slot → no fallback to the console default or to B.
        $this->actingAs($a)->postJson('/api/v1/agent/whatsapp/test-send', ['number' => '081300000001'])->assertStatus(409)->assertJsonPath('errors.code', 'whatsapp_device_not_ready');
        // Global OFF.
        $this->pair($a, 'pc-agent-A-slot');
        $this->configure(false);
        $this->actingAs($a)->postJson('/api/v1/agent/whatsapp/test-send', ['number' => '081300000001'])->assertStatus(409);

        Http::assertNotSent(fn (Request $req) => str_ends_with($req->url(), '/send/message'));
        $this->assertSame(0, WhatsAppMessage::where('event', 'test')->count());
    }

    public function test_test_send_is_rate_limited(): void
    {
        $a = $this->agent();
        $this->pair($a, 'pc-agent-A-slot');
        $this->register($a)->assertOk();
        RateLimiter::clear("wa-agent-test-min:{$a->id}");

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($a)->postJson('/api/v1/agent/whatsapp/test-send', ['number' => '081300000001'])->assertOk();
        }
        $this->actingAs($a)->postJson('/api/v1/agent/whatsapp/test-send', ['number' => '081300000001'])->assertStatus(429);
    }

    public function test_test_send_budget_is_shared_per_target_agen_and_cannot_be_reset_by_actor_or_forged_target(): void
    {
        $a = $this->agent();
        $b = $this->agent();
        $admin = $this->adminOf($a);
        $this->pair($a, 'pc-agent-A-slot');
        $this->pair($b, 'pc-agent-B-slot');
        $this->register($a)->assertOk();
        $this->register($b)->assertOk();
        foreach ([$a, $admin, $a] as $actor) {
            $this->actingAs($actor)->postJson('/api/v1/agent/whatsapp/test-send', ['number' => '081300000001'])->assertOk();
        }
        // Agen and its Admin drew from ONE budget; a forged agent_id neither reaches B's budget nor resets A's.
        $this->actingAs($admin)->postJson('/api/v1/agent/whatsapp/test-send', ['number' => '081300000001'])->assertStatus(429);
        $this->actingAs($admin)->postJson('/api/v1/agent/whatsapp/test-send', ['number' => '081300000001', 'agent_id' => $b->id])->assertForbidden();
        $this->actingAs($b)->postJson('/api/v1/agent/whatsapp/test-send', ['number' => '081300000001'])->assertOk(); // B unaffected
    }

    // ---- WA-DEC-04/05: Super Admin keeps the global control and the old console test path is not per-Agen -------

    public function test_super_admin_keeps_global_on_off_and_cannot_use_agen_endpoints(): void
    {
        $sa = User::factory()->superAdmin()->create();
        $this->actingAs($sa)->putJson('/api/v1/admin/integrations/whatsapp', ['enabled' => false])->assertOk();
        $this->assertFalse(app(WhatsAppSettings::class)->reload()->enabled());
        $this->actingAs($sa)->postJson('/api/v1/agent/whatsapp/test-send', ['number' => '081300000001'])->assertForbidden();
    }
}
