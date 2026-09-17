<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Account',
            'email' => 'new-'.uniqid().'@example.com',
            'phone' => '081200000000',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $overrides);
    }

    public function test_super_admin_can_create_an_agen_which_becomes_root_of_its_own_branch(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->postJson('/api/v1/users', $this->payload(['role' => 'agen']));

        $response->assertCreated();
        $agen = User::query()->where('email', $response->json('data.email'))->firstOrFail();
        $this->assertSame($agen->id, $agen->agent_id, 'agen must self-reference agent_id');
        $this->assertNotNull($agen->referral_code);
    }

    public function test_super_admin_cannot_create_network_roles(): void
    {
        $actor = User::factory()->superAdmin()->create();
        foreach (['admin', 'keuangan', 'korsal', 'sales', 'kurir'] as $role) {
            $this->actingAs($actor)->postJson('/api/v1/users', $this->payload(['role' => $role]))->assertForbidden();
        }
    }

    public function test_super_admin_can_soft_delete_non_agent_users_but_never_another_super_admin_self_or_agen(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $otherSuperAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->deleteJson("/api/v1/users/{$admin->id}")->assertOk();
        $this->assertSoftDeleted('users', ['id' => $admin->id]);

        $this->actingAs($superAdmin)->deleteJson("/api/v1/users/{$agen->id}")->assertStatus(403);
        $this->actingAs($superAdmin)->deleteJson("/api/v1/users/{$otherSuperAdmin->id}")->assertStatus(403);
        $this->actingAs($superAdmin)->deleteJson("/api/v1/users/{$superAdmin->id}")->assertStatus(403);
    }

    public function test_super_admin_cannot_delete_an_agen_account(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko Dihapus', 'address' => 'Jl. X',
            'latitude' => -6.2, 'longitude' => 106.8,
        ]);

        $this->actingAs($superAdmin)->deleteJson("/api/v1/users/{$agen->id}")->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $agen->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('agent_profiles', ['user_id' => $agen->id]);
    }

    public function test_agen_cannot_delete_users_even_within_their_own_branch(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        $sales = User::factory()->sales()->create(['agent_id' => $agen->id]);

        $this->actingAs($agen)->deleteJson("/api/v1/users/{$sales->id}")->assertStatus(403);
    }

    public function test_agen_can_create_korsal_sales_admin_and_kurir_but_not_another_agen(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);

        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        foreach (['korsal', 'sales', 'admin', 'keuangan', 'kurir'] as $allowedRole) {
            $this->actingAs($agen)->postJson('/api/v1/users', $this->payload(['role' => $allowedRole, ...($allowedRole === 'sales' ? ['korsal_id' => $korsal->id] : [])]))
                ->assertCreated();
        }

        $this->actingAs($agen)
            ->postJson('/api/v1/users', $this->payload(['role' => 'agen']))
            ->assertStatus(403);
    }

    public function test_agen_created_admin_and_kurir_are_auto_linked_to_the_creators_own_branch_never_a_client_supplied_agent_id(): void
    {
        $agenA = User::factory()->agen()->create();
        $agenA->update(['agent_id' => $agenA->id]);
        $agenB = User::factory()->agen()->create();
        $agenB->update(['agent_id' => $agenB->id]);

        // No agent_id sent at all — still lands in the creator's own branch.
        $adminResponse = $this->actingAs($agenA)->postJson('/api/v1/users', $this->payload(['role' => 'admin']));
        $adminResponse->assertCreated();
        $this->assertSame($agenA->id, $adminResponse->json('data.agent_id'));

        // Client-supplied agent_id pointing at a DIFFERENT agent must be rejected.
        $kurirResponse = $this->actingAs($agenA)
            ->postJson('/api/v1/users', $this->payload(['role' => 'kurir', 'agent_id' => $agenB->id]));
        $kurirResponse->assertUnprocessable();
    }

    public function test_agen_can_delete_admin_and_kurir_in_its_own_branch_but_not_another_agents(): void
    {
        $agenA = User::factory()->agen()->create();
        $agenA->update(['agent_id' => $agenA->id]);
        $agenB = User::factory()->agen()->create();
        $agenB->update(['agent_id' => $agenB->id]);

        $ownAdmin = User::factory()->admin()->create(['agent_id' => $agenA->id]);
        $otherAdmin = User::factory()->admin()->create(['agent_id' => $agenB->id]);
        $ownKorsal = User::factory()->korsal()->create(['agent_id' => $agenA->id]);

        $this->actingAs($agenA)->deleteJson("/api/v1/users/{$ownAdmin->id}")->assertOk();
        $this->actingAs($agenA)->deleteJson("/api/v1/users/{$otherAdmin->id}")->assertForbidden();
        // Delete stays undelegated for korsal/sales — create rights don't imply delete rights there.
        $this->actingAs($agenA)->deleteJson("/api/v1/users/{$ownKorsal->id}")->assertForbidden();
    }

    public function test_korsal_can_only_create_sales(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id]);

        $response = $this->actingAs($korsal)->postJson('/api/v1/users', $this->payload(['role' => 'sales']));
        $response->assertCreated();

        $sales = User::query()->where('email', $response->json('data.email'))->firstOrFail();
        $this->assertSame($korsal->id, $sales->korsal_id);
        $this->assertSame($korsal->id, $sales->parent_id);
        $this->assertSame($agen->id, $sales->agent_id);

        $this->actingAs($korsal)
            ->postJson('/api/v1/users', $this->payload(['role' => 'korsal']))
            ->assertStatus(403);
    }

    public function test_sales_cannot_create_anyone(): void
    {
        $sales = User::factory()->sales()->create();

        $this->actingAs($sales)
            ->postJson('/api/v1/users', $this->payload(['role' => 'sales']))
            ->assertStatus(403);
    }

    public function test_konsumen_never_has_a_referral_code(): void
    {
        $agen = User::factory()->agen()->create();
        $sales = User::factory()->sales()->create(['agent_id' => $agen->id, 'referral_code' => 'S-'.uniqid()]);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Konsumen Baru', 'email' => 'konsumen-'.uniqid().'@example.com',
            'phone' => '0812', 'password' => 'password123', 'password_confirmation' => 'password123',
            'referral_code' => $sales->referral_code,
        ]);

        $response->assertCreated();
        $konsumen = User::query()->where('email', $response->json('data.user.email'))->firstOrFail();
        $this->assertNull($konsumen->referral_code);
    }

    public function test_user_listing_shows_agen_accounts_and_supports_role_and_search_filters(): void
    {
        $agenA = User::factory()->agen()->create(['name' => 'Toko Agen A']);
        $agenA->update(['agent_id' => $agenA->id]);
        $agenB = User::factory()->agen()->create(['name' => 'Toko Agen B']);
        $agenB->update(['agent_id' => $agenB->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agenA->id, 'name' => 'Budi Konsumen', 'phone' => '081234567890']);
        $superAdmin = User::factory()->superAdmin()->create();

        // Super Admin now sees agen accounts in the generic listing (previously excluded entirely).
        $response = $this->actingAs($superAdmin)->getJson('/api/v1/users');
        $response->assertOk();
        $roles = collect($response->json('data'))->pluck('role');
        $this->assertTrue($roles->contains('agen'));

        // role=konsumen search picker (used by the checkout on-behalf-of-konsumen UI).
        $picker = $this->actingAs($agenA)->getJson('/api/v1/users?role=konsumen&search=Budi');
        $picker->assertOk();
        $names = collect($picker->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Budi Konsumen'));

        // An agen only ever sees its OWN branch — agen B's konsumen must never appear,
        // and agen A's own network listing only shows itself for role=agen (never agen B).
        $ownAgenRow = $this->actingAs($agenA)->getJson('/api/v1/users?role=agen');
        $ownAgenRow->assertOk();
        $agenNames = collect($ownAgenRow->json('data'))->pluck('name');
        $this->assertTrue($agenNames->contains('Toko Agen A'));
        $this->assertFalse($agenNames->contains('Toko Agen B'));
    }

    public function test_agent_sales_requires_own_korsal_and_updates_closures(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        $own = User::factory()->korsal()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $other = User::factory()->agen()->create();
        $foreign = User::factory()->korsal()->create(['agent_id' => $other->id]);
        $this->actingAs($agen)->postJson('/api/v1/users', $this->payload(['role' => 'sales']))->assertUnprocessable();
        $this->postJson('/api/v1/users', $this->payload(['role' => 'sales', 'korsal_id' => $foreign->id]))->assertUnprocessable();
        $response = $this->postJson('/api/v1/users', $this->payload(['role' => 'sales', 'korsal_id' => $own->id]))->assertCreated();
        $this->assertDatabaseHas('users', ['id' => $response->json('data.id'), 'parent_id' => $own->id, 'korsal_id' => $own->id, 'agent_id' => $agen->id]);
        $this->assertDatabaseHas('user_closures', ['ancestor_id' => $own->id, 'descendant_id' => $response->json('data.id'), 'depth' => 1]);
        $this->actingAs($own)->postJson('/api/v1/users', $this->payload(['role' => 'sales', 'korsal_id' => $foreign->id]))->assertUnprocessable();
    }
}
