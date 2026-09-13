<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Kontak Agen (Blueprint §Agent Contact) — super_admin CRUD + a public directory that never leaks internal fields. */
class AgentContactTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function makeAgent(string $name = 'Toko Kue Bahagia'): User
    {
        $agen = User::factory()->agen()->create(['name' => $name]);
        $agen->update(['agent_id' => $agen->id]);

        return $agen;
    }

    public function test_super_admin_can_create_an_agent_contact_profile(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $agen = $this->makeAgent();

        $response = $this->actingAs($superAdmin)->postJson('/api/v1/admin/agents', [
            'user_id' => $agen->id, 'store_name' => 'Toko Kue Bahagia', 'address' => 'Jl. Merdeka No. 1',
            'phone' => '021-555-1234', 'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('agent_profiles', ['user_id' => $agen->id, 'store_name' => 'Toko Kue Bahagia']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'agent_profile.created']);
    }

    public function test_creating_a_profile_for_a_non_agent_user_is_rejected(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $konsumen = User::factory()->konsumen()->create();

        $this->actingAs($superAdmin)->postJson('/api/v1/admin/agents', [
            'user_id' => $konsumen->id, 'store_name' => 'X', 'address' => 'Y', 'latitude' => 0, 'longitude' => 0,
        ])->assertStatus(422);
    }

    public function test_creating_a_second_profile_for_the_same_agent_is_rejected(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $agen = $this->makeAgent();
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko Lama', 'address' => 'Jl. Lama',
            'latitude' => -6.2, 'longitude' => 106.8,
        ]);

        $this->actingAs($superAdmin)->postJson('/api/v1/admin/agents', [
            'user_id' => $agen->id, 'store_name' => 'Toko Baru', 'address' => 'Jl. Baru', 'latitude' => 0, 'longitude' => 0,
        ])->assertStatus(422);
    }

    public function test_super_admin_can_update_and_delete_an_agent_profile(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $agen = $this->makeAgent();
        $profile = AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko Awal', 'address' => 'Jl. Awal',
            'latitude' => -6.2, 'longitude' => 106.8,
        ]);

        $this->actingAs($superAdmin)->patchJson("/api/v1/admin/agents/{$profile->id}", ['store_name' => 'Toko Update'])
            ->assertOk()->assertJsonPath('data.profile.store_name', 'Toko Update');
        $this->assertDatabaseHas('activity_logs', ['event' => 'agent_profile.updated']);

        $this->actingAs($superAdmin)->deleteJson("/api/v1/admin/agents/{$profile->id}")->assertOk();
        $this->assertDatabaseMissing('agent_profiles', ['id' => $profile->id]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'agent_profile.deleted']);
    }

    public function test_super_admin_can_toggle_an_agents_active_status(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $agen = $this->makeAgent();
        $this->assertSame('active', $agen->status);

        $response = $this->actingAs($superAdmin)->patchJson("/api/v1/admin/agents/{$agen->id}/toggle-status");
        $response->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->assertDatabaseHas('activity_logs', ['event' => 'agent.status_changed']);

        $this->actingAs($superAdmin)->patchJson("/api/v1/admin/agents/{$agen->id}/toggle-status")
            ->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_only_super_admin_may_manage_agent_contacts(): void
    {
        $agen = $this->makeAgent();
        $otherAgen = $this->makeAgent('Toko Lain');

        $this->actingAs($agen)->getJson('/api/v1/admin/agents')->assertStatus(403);
        $this->actingAs($agen)->postJson('/api/v1/admin/agents', [
            'user_id' => $otherAgen->id, 'store_name' => 'X', 'address' => 'Y', 'latitude' => 0, 'longitude' => 0,
        ])->assertStatus(403);
    }

    public function test_public_directory_only_shows_active_agents_and_never_leaks_internal_fields(): void
    {
        $activeAgent = $this->makeAgent('Toko Aktif');
        $activeAgent->update(['referral_code' => 'AG-ACTIVE1']);
        AgentProfile::create([
            'user_id' => $activeAgent->id, 'store_name' => 'Toko Aktif', 'address' => 'Jl. Aktif',
            'phone' => '021-111', 'latitude' => -6.9, 'longitude' => 107.6,
        ]);

        $inactiveAgent = $this->makeAgent('Toko Nonaktif');
        $inactiveAgent->update(['status' => 'inactive']);
        AgentProfile::create([
            'user_id' => $inactiveAgent->id, 'store_name' => 'Toko Nonaktif', 'address' => 'Jl. Nonaktif',
            'latitude' => -6.8, 'longitude' => 107.5,
        ]);

        $response = $this->getJson('/api/v1/agents');
        $response->assertOk();

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Toko Aktif', $names);
        $this->assertNotContains('Toko Nonaktif', $names);

        // referral_code IS public here — meant to be shared so a konsumen can sign up
        // under this agent's network directly from the "Kontak Agen" page.
        $row = collect($response->json('data'))->firstWhere('name', 'Toko Aktif');
        $this->assertSame('AG-ACTIVE1', $row['referral_code']);
        $this->assertArrayNotHasKey('email', $row);
        $this->assertArrayNotHasKey('status', $row);
        $this->assertArrayNotHasKey('user_id', $row);
        $this->assertSame(-6.9, (float) $row['latitude']);
        $this->assertSame(107.6, (float) $row['longitude']);
    }

    public function test_admin_directory_listing_includes_internal_fields_for_super_admin_only(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $agen = $this->makeAgent();
        $agen->update(['referral_code' => 'AG-TEST123']);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko', 'address' => 'Jl.',
            'latitude' => -6.9, 'longitude' => 107.6,
        ]);

        $response = $this->actingAs($superAdmin)->getJson('/api/v1/admin/agents');
        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('user_id', $agen->id);
        $this->assertNotNull($row['referral_code']);
        $this->assertSame('active', $row['status']);
    }

    /** Regression: withoutGlobalScopes() used to also lift the SoftDeletingScope, resurrecting deleted agents into this list. */
    public function test_admin_directory_listing_never_includes_a_soft_deleted_agen(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $activeAgen = $this->makeAgent('Toko Aktif Saja');
        $deletedAgen = $this->makeAgent('Toko Sudah Dihapus');
        $deletedAgen->delete();

        $response = $this->actingAs($superAdmin)->getJson('/api/v1/admin/agents');
        $response->assertOk();

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Toko Aktif Saja'));
        $this->assertFalse($names->contains('Toko Sudah Dihapus'));
    }

    /* -----------------------------------------------------------
     * Self-service "Profil Toko Saya" — agen managing its OWN record
     * ----------------------------------------------------------- */

    public function test_agen_can_view_and_create_their_own_store_profile(): void
    {
        $agen = $this->makeAgent();

        $this->actingAs($agen)->getJson('/api/v1/agent/store-profile')
            ->assertOk()
            ->assertJsonPath('data', null);

        $response = $this->actingAs($agen)->putJson('/api/v1/agent/store-profile', [
            'store_name' => 'Toko Saya Sendiri', 'address' => 'Jl. Mandiri No. 1',
            'phone' => '0811', 'latitude' => -6.9, 'longitude' => 107.6,
        ]);
        $response->assertOk();
        $this->assertSame('Toko Saya Sendiri', $response->json('data.store_name'));

        $this->actingAs($agen)->getJson('/api/v1/agent/store-profile')
            ->assertOk()
            ->assertJsonPath('data.store_name', 'Toko Saya Sendiri');
    }

    public function test_agen_can_never_view_or_edit_another_agents_store_profile_via_self_service(): void
    {
        $agenA = $this->makeAgent('Agen A');
        $agenB = $this->makeAgent('Agen B');
        AgentProfile::create([
            'user_id' => $agenB->id, 'store_name' => 'Toko B', 'address' => 'Jl. B',
            'latitude' => -6.9, 'longitude' => 107.6,
        ]);

        // agenA has no profile of its own — must see null, never agenB's.
        $this->actingAs($agenA)->getJson('/api/v1/agent/store-profile')->assertJsonPath('data', null);

        $this->actingAs($agenA)->putJson('/api/v1/agent/store-profile', [
            'store_name' => 'Toko A', 'address' => 'Jl. A', 'latitude' => -6.2, 'longitude' => 106.8,
        ])->assertOk();

        // agenB's own record must be untouched by agenA's write.
        $this->assertSame('Toko B', AgentProfile::query()->where('user_id', $agenB->id)->value('store_name'));
    }

    public function test_non_agen_roles_cannot_reach_the_self_service_store_profile_endpoint(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->getJson('/api/v1/agent/store-profile')->assertStatus(403);
    }
}
