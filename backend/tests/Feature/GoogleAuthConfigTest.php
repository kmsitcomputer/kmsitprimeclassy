<?php

namespace Tests\Feature;

use App\Models\AgentGoogleAuthConfig;
use App\Models\AgentProfile;
use App\Models\GoogleAuthSetting;
use App\Models\User;
use App\Services\Auth\GoogleAuthConfigService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IMP-001 Step 3 — the Google Auth configuration surface: Super Admin's global
 * settings and each Agen's own branch config, resolved through
 * GoogleAuthConfigService (Agen row -> global row -> .env fallback).
 *
 * Security invariants under test: the client secret is never echoed by any
 * API (has_secret only); a blank edit preserves the stored secret; an explicit
 * clear removes it; every write is audited with field NAMES, never the secret;
 * the reviewer/actor derives its scope from the session (agent_id), never from
 * input.
 */
class GoogleAuthConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        config([
            'services.google.client_id' => 'env-client',
            'services.google.client_secret' => 'env-secret',
            'services.google.redirect_uri' => 'http://localhost/api/v1/auth/google/callback',
            'services.google.frontend_url' => '',
        ]);
    }

    private function agen(): User
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);

        return $agen;
    }

    private function branch(?User $agen = null): User
    {
        $agen ??= $this->agen();
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko Google', 'address' => 'Jl. Google',
            'latitude' => -6.2, 'longitude' => 106.8166,
        ]);

        return $agen;
    }

    public function test_super_admin_reads_and_writes_global_settings_and_the_secret_is_never_echoed(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->putJson('/api/v1/admin/google-auth', [
            'is_enabled' => true,
            'client_id' => 'global-client',
            'client_secret' => 'global-secret',
            'redirect_uri' => 'https://app.example/callback',
            'frontend_url' => 'https://app.example',
        ])->assertOk()->assertJsonPath('data.has_secret', true);

        $read = $this->actingAs($superAdmin)->getJson('/api/v1/admin/google-auth')->assertOk()->json('data');
        $this->assertSame('global-client', $read['client_id']);
        $this->assertTrue($read['is_enabled']);
        $this->assertTrue($read['has_secret']);
        $this->assertStringNotContainsString('global-secret', $this->actingAs($superAdmin)->getJson('/api/v1/admin/google-auth')->content(), 'the stored client secret must never reach the API');

        // Encrypted at rest through the model cast: the raw DB ciphertext is not the plaintext.
        $row = GoogleAuthSetting::query()->whereKey(1)->first();
        $cipher = (string) $row->getRawOriginal('client_secret');
        $this->assertNotSame('global-secret', $cipher, 'the secret must be encrypted at rest');
        $this->assertSame('global-secret', $row->client_secret, 'the model cast decrypts it for internal use');
        $this->assertDatabaseHas('activity_logs', ['event' => 'google_auth.settings_updated']);
    }

    public function test_blank_secret_preserves_and_clear_secret_removes_it(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($superAdmin)->putJson('/api/v1/admin/google-auth', [
            'is_enabled' => true, 'client_id' => 'c', 'client_secret' => 'keep-me',
        ])->assertOk()->assertJsonPath('data.has_secret', true);

        // Blank edit keeps the stored secret.
        $this->actingAs($superAdmin)->putJson('/api/v1/admin/google-auth', [
            'client_id' => 'c2', 'client_secret' => '',
        ])->assertOk()->assertJsonPath('data.has_secret', true);
        $this->assertSame('keep-me', GoogleAuthSetting::query()->whereKey(1)->first()->client_secret);

        // Explicit clear removes it.
        $this->actingAs($superAdmin)->putJson('/api/v1/admin/google-auth', [
            'clear_secret' => true,
        ])->assertOk()->assertJsonPath('data.has_secret', false);
        $this->assertNull(GoogleAuthSetting::query()->whereKey(1)->first()->client_secret);
    }

    public function test_only_super_admin_may_touch_global_settings(): void
    {
        $agen = $this->branch();

        $this->actingAs($agen)->getJson('/api/v1/admin/google-auth')->assertStatus(403);
        $this->actingAs($agen)->putJson('/api/v1/admin/google-auth', ['client_id' => 'hack'])->assertStatus(403);
    }

    public function test_agen_reads_and_writes_only_its_own_branch_config_and_the_secret_is_never_echoed(): void
    {
        $agen = $this->branch();

        $this->actingAs($agen)->putJson('/api/v1/agent/google-auth', [
            'is_enabled' => true,
            'client_id' => 'agent-client',
            'client_secret' => 'agent-secret',
        ])->assertOk()
            ->assertJsonPath('data.has_secret', true);

        $read = $this->actingAs($agen)->getJson('/api/v1/agent/google-auth')->json('data');
        $this->assertSame('agent-client', $read['client_id']);
        $this->assertTrue($read['has_secret']);
        $this->assertStringNotContainsString('agent-secret', json_encode($this->actingAs($agen)->getJson('/api/v1/agent/google-auth')->json()));
        $row = AgentGoogleAuthConfig::query()->where('agent_id', $agen->id)->first();
        $cipher = (string) $row->getRawOriginal('client_secret');
        $this->assertNotSame('agent-secret', $cipher, 'the secret must be encrypted at rest');
        $this->assertDatabaseHas('activity_logs', ['event' => 'google_auth.agent_config_updated']);
    }

    public function test_admin_manages_the_branch_config_but_one_agen_can_never_read_or_write_anothers(): void
    {
        $agenA = $this->branch();
        $agenB = $this->branch();
        $admin = User::factory()->admin()->create(['agent_id' => $agenA->id]);

        $this->actingAs($admin)->putJson('/api/v1/agent/google-auth', [
            'client_id' => 'branch-A', 'client_secret' => 'secret-A',
        ])->assertOk()->assertJsonPath('data.has_secret', true);

        // Branch B cannot see A's config, and a forged agent_id in the payload is ignored.
        $bRead = $this->actingAs($agenB)->getJson('/api/v1/agent/google-auth')->assertOk()->json('data');
        $this->assertNull($bRead['client_id']);

        $this->actingAs($agenB)->putJson('/api/v1/agent/google-auth', [
            'agent_id' => $agenA->id, 'client_id' => 'branch-B',
        ])->assertOk();

        $this->assertSame('branch-A', AgentGoogleAuthConfig::query()->where('agent_id', $agenA->id)->value('client_id'));
        $this->assertSame('branch-B', AgentGoogleAuthConfig::query()->where('agent_id', $agenB->id)->value('client_id'));
    }

    public function test_resolved_for_agent_precedence_is_agent_then_global_then_env(): void
    {
        $agen = $this->branch();
        $config = app(GoogleAuthConfigService::class);

        // No rows: .env fallback.
        $resolved = $config->resolvedForAgent($agen->id);
        $this->assertSame('env-client', $resolved['client_id']);
        $this->assertSame('env', $resolved['source']);

        // Global row wins over .env when present.
        GoogleAuthSetting::singleton()->forceFill([
            'is_enabled' => true, 'client_id' => 'global-client', 'client_secret' => 'global-secret',
        ])->save();
        $resolved = $config->resolvedForAgent($agen->id);
        $this->assertSame('global-client', $resolved['client_id']);
        $this->assertSame('global', $resolved['source']);

        // A different agen with no own row still resolves the same global row.
        $other = $this->branch();
        $this->assertSame('global-client', $config->resolvedForAgent($other->id)['client_id']);

        // The agent's own row wins over the global row.
        AgentGoogleAuthConfig::query()->create([
            'agent_id' => $agen->id, 'is_enabled' => true, 'client_id' => 'agent-client', 'client_secret' => 'agent-secret',
        ]);
        $resolved = $config->resolvedForAgent($agen->id);
        $this->assertSame('agent-client', $resolved['client_id']);
        $this->assertSame('agent', $resolved['source']);
    }

    public function test_global_enable_disable_flips_the_resolved_switch_for_unconfigured_branches(): void
    {
        $agen = $this->branch();
        $config = app(GoogleAuthConfigService::class);
        $superAdmin = User::factory()->superAdmin()->create();

        // With no settings row the .env bootstrap decides (env-client/.env-secret
        // configured in setUp) — that is the canonical fallback, not "disabled".
        $resolved = $config->resolvedForAgent($agen->id);
        $this->assertSame('env', $resolved['source']);

        // Enable globally with a real client_id.
        $this->actingAs($superAdmin)->putJson('/api/v1/admin/google-auth', [
            'is_enabled' => true, 'client_id' => 'global-enable', 'client_secret' => 's',
        ])->assertOk()->assertJsonPath('data.has_secret', true);

        $read = $this->actingAs($superAdmin)->getJson('/api/v1/admin/google-auth')->assertOk()->json('data');
        $this->assertTrue($read['is_enabled']);
        $this->assertSame('global-enable', $read['client_id']);

        $resolved = $config->resolvedForAgent($agen->id);
        $this->assertTrue($resolved['is_enabled']);
        $this->assertSame('global-enable', $resolved['client_id']);
        $this->assertSame('global', $resolved['source']);

        // Keep the global row present but flip is_enabled off — the frontend always
        // sends the current non-secret values (the canonical contract), so the
        // toggle keeps the client_id and only flips the switch.
        $this->actingAs($superAdmin)->putJson('/api/v1/admin/google-auth', [
            'is_enabled' => false, 'client_id' => 'global-enable',
        ])->assertOk();
        $read = $this->actingAs($superAdmin)->getJson('/api/v1/admin/google-auth')->assertOk()->json('data');
        $this->assertFalse($read['is_enabled']);
        $this->assertSame('global-enable', $read['client_id']);
        $this->assertSame('global', $config->resolvedForAgent($agen->id)['source']);
        $this->assertFalse(GoogleAuthSetting::singleton()->is_enabled);
    }

    public function test_agent_enable_disable_only_touches_its_own_branch_switch(): void
    {
        $agenA = $this->branch();
        $agenB = $this->branch();
        $config = app(GoogleAuthConfigService::class);

        $this->actingAs($agenA)->putJson('/api/v1/agent/google-auth', [
            'is_enabled' => true, 'client_id' => 'branch-a', 'client_secret' => 'a-secret',
        ])->assertOk()->assertJsonPath('data.has_secret', true);
        $readA = $this->actingAs($agenA)->getJson('/api/v1/agent/google-auth')->assertOk()->json('data');
        $this->assertTrue($readA['is_enabled']);
        $this->assertSame('branch-a', $readA['client_id']);

        $resolvedA = $config->resolvedForAgent($agenA->id);
        $resolvedB = $config->resolvedForAgent($agenB->id);
        $this->assertTrue($resolvedA['is_enabled']);
        $this->assertSame('branch-a', $resolvedA['client_id']);
        $this->assertSame('agent', $resolvedA['source']);
        $this->assertSame('env', $resolvedB['source'], 'branch B must keep its own (absent) resolution unchanged');

        // Disabling branch A does not touch the stored client_id — the switch
        // is independent of the credential, and the frontend sends the current
        // non-secret values along (canonical contract), so only the switch flips.
        $this->actingAs($agenA)->putJson('/api/v1/agent/google-auth', [
            'is_enabled' => false, 'client_id' => 'branch-a',
        ])->assertOk();
        $readA = $this->actingAs($agenA)->getJson('/api/v1/agent/google-auth')->assertOk()->json('data');
        $this->assertFalse($readA['is_enabled']);
        $this->assertSame('branch-a', $readA['client_id']);
        $row = AgentGoogleAuthConfig::query()->where('agent_id', $agenA->id)->firstOrFail();
        $this->assertFalse($row->is_enabled);
        $this->assertSame('branch-a', $row->client_id);
    }

    public function test_other_roles_cannot_reach_either_settings_surface(): void
    {
        $b = $this->branch();
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $b->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $b->id, 'parent_id' => $b->id]);

        // Keuangan / Gudang cannot read or write the global config.
        foreach ([$keuangan, $gudang] as $actor) {
            $this->actingAs($actor)->getJson('/api/v1/admin/google-auth')->assertStatus(403);
            $this->actingAs($actor)->putJson('/api/v1/admin/google-auth', ['client_id' => 'hack'])->assertStatus(403);
            // nor the agent surface (not agen/admin).
            $this->actingAs($actor)->getJson('/api/v1/agent/google-auth')->assertStatus(403);
            $this->actingAs($actor)->putJson('/api/v1/agent/google-auth', ['client_id' => 'hack'])->assertStatus(403);
        }
    }
}