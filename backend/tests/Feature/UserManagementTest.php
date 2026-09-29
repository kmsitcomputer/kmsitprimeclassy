<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Courier;
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

    public function test_agen_can_delete_every_role_beneath_it_in_its_own_branch(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);

        foreach (['korsal', 'sales', 'admin', 'keuangan', 'kurir', 'gudang', 'salesKurir', 'konsumen'] as $role) {
            $target = User::factory()->{$role}()->create(['agent_id' => $agen->id]);

            $this->actingAs($agen)->deleteJson("/api/v1/users/{$target->id}")->assertOk();
            $this->assertSoftDeleted('users', ['id' => $target->id]);
        }
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

    public function test_agen_can_create_gudang_directly_under_its_own_network_without_referral_hierarchy(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);

        $response = $this->actingAs($agen)->postJson('/api/v1/users', $this->payload([
            'role' => 'gudang',
        ]))->assertCreated();

        $gudang = User::query()->with('role')->findOrFail($response->json('data.id'));
        $this->assertSame('gudang', $gudang->role->slug);
        $this->assertSame($agen->id, $gudang->agent_id);
        $this->assertSame($agen->id, $gudang->parent_id);
        $this->assertNull($gudang->korsal_id);
        $this->assertNull($gudang->sales_id);
        $this->assertNull($gudang->referral_code);
        $this->assertNotNull($gudang->password);
        $this->assertDatabaseHas('user_closures', [
            'ancestor_id' => $agen->id,
            'descendant_id' => $gudang->id,
            'depth' => 1,
        ]);
    }

    public function test_gudang_creation_rejects_cross_agent_tampering_and_is_forbidden_to_unauthorized_roles(): void
    {
        $owner = User::factory()->agen()->create();
        $owner->update(['agent_id' => $owner->id]);
        $foreign = User::factory()->agen()->create();
        $foreign->update(['agent_id' => $foreign->id]);

        $tamperedEmail = 'tampered-'.uniqid().'@example.com';
        $this->actingAs($owner)->postJson('/api/v1/users', $this->payload([
            'role' => 'gudang',
            'email' => $tamperedEmail,
            'agent_id' => $foreign->id,
        ]))->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => $tamperedEmail]);

        $admin = User::factory()->admin()->create([
            'agent_id' => $owner->id,
            'parent_id' => $owner->id,
        ]);
        $this->actingAs($admin)->postJson('/api/v1/users', $this->payload([
            'role' => 'gudang',
        ]))->assertForbidden();
    }

    public function test_agen_can_never_delete_another_agen_itself_or_anyone_in_another_branch(): void
    {
        $agenA = User::factory()->agen()->create();
        $agenA->update(['agent_id' => $agenA->id]);
        $agenB = User::factory()->agen()->create();
        $agenB->update(['agent_id' => $agenB->id]);

        $otherAdmin = User::factory()->admin()->create(['agent_id' => $agenB->id]);
        $otherSales = User::factory()->sales()->create(['agent_id' => $agenB->id]);

        $this->actingAs($agenA)->deleteJson("/api/v1/users/{$otherAdmin->id}")->assertForbidden();
        $this->actingAs($agenA)->deleteJson("/api/v1/users/{$otherSales->id}")->assertForbidden();
        $this->actingAs($agenA)->deleteJson("/api/v1/users/{$agenB->id}")->assertForbidden();
        $this->actingAs($agenA)->deleteJson("/api/v1/users/{$agenA->id}")->assertForbidden();
    }

    public function test_korsal_and_sales_can_never_delete_anyone(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id]);
        $sales = User::factory()->sales()->create(['agent_id' => $agen->id, 'korsal_id' => $korsal->id]);
        $peer = User::factory()->sales()->create(['agent_id' => $agen->id, 'korsal_id' => $korsal->id]);

        $this->actingAs($korsal)->deleteJson("/api/v1/users/{$sales->id}")->assertForbidden();
        $this->actingAs($sales)->deleteJson("/api/v1/users/{$peer->id}")->assertForbidden();
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

    public function test_agen_can_convert_own_sales_to_sales_kurir_without_replacing_identity_or_referral(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $sales = User::factory()->sales()->create([
            'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'parent_id' => $korsal->id,
            'referral_code' => 'SA-KEEP01',
        ]);
        $originalId = $sales->id;

        $response = $this->actingAs($agen)->patchJson("/api/v1/users/{$sales->id}/convert-to-sales-kurir");

        $response->assertOk();
        $sales->refresh();
        $this->assertSame($originalId, $sales->id);
        $this->assertSame('sales-kurir', $sales->role->slug);
        $this->assertSame('SA-KEEP01', $sales->referral_code);
        $this->assertSame($agen->id, $sales->agent_id);
        $this->assertSame($korsal->id, $sales->korsal_id);
        $this->assertSame($korsal->id, $sales->parent_id);
        $this->assertDatabaseHas('couriers', ['user_id' => $sales->id, 'type' => 'internal', 'agent_id' => $agen->id, 'is_active' => true]);

        $this->actingAs($agen)->patchJson("/api/v1/users/{$sales->id}/convert-to-sales-kurir")->assertUnprocessable();
        $this->assertSame(1, Courier::query()->where('user_id', $sales->id)->count());
    }

    public function test_only_owning_agen_can_convert_sales_to_sales_kurir(): void
    {
        $owner = User::factory()->agen()->create();
        $owner->update(['agent_id' => $owner->id]);
        $other = User::factory()->agen()->create();
        $other->update(['agent_id' => $other->id]);
        $korsal = User::factory()->korsal()->create(['agent_id' => $owner->id]);
        $sales = User::factory()->sales()->create(['agent_id' => $owner->id, 'korsal_id' => $korsal->id]);

        foreach ([User::factory()->superAdmin()->create(), User::factory()->admin()->create(['agent_id' => $owner->id]), $korsal, User::factory()->sales()->create(['agent_id' => $owner->id]), $other] as $actor) {
            $this->actingAs($actor)->patchJson("/api/v1/users/{$sales->id}/convert-to-sales-kurir")->assertForbidden();
        }
    }

    public function test_sales_kurir_creation_and_conversion_preserve_identity_and_referral_profile(): void
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $korsal = User::factory()->korsal()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);

        $created = $this->actingAs($agent)->postJson('/api/v1/users', $this->payload(['role' => 'sales-kurir', 'korsal_id' => $korsal->id]))->assertCreated();
        $createdUser = User::query()->where('email', $created->json('data.email'))->firstOrFail();
        $this->assertStringStartsWith('SK-', $createdUser->referral_code);
        $this->assertSame($agent->id, $createdUser->agent_id);
        $this->assertSame($korsal->id, $createdUser->korsal_id);
        $this->assertSame($korsal->id, $createdUser->parent_id);
        $this->assertDatabaseHas('couriers', ['user_id' => $createdUser->id, 'agent_id' => $agent->id]);

        $sales = User::factory()->sales()->create(['agent_id' => $agent->id, 'korsal_id' => $korsal->id, 'parent_id' => $korsal->id, 'referral_code' => 'SA-ABC123']);
        $id = $sales->id;
        $this->actingAs($agent)->patchJson("/api/v1/users/{$id}/convert-to-sales-kurir")->assertOk();
        $sales->refresh();
        $this->assertSame($id, $sales->id);
        $this->assertSame('sales-kurir', $sales->role->slug);
        $this->assertSame('SA-ABC123', $sales->referral_code);
        $this->assertDatabaseCount('couriers', 2);
        $this->actingAs($agent)->patchJson("/api/v1/users/{$id}/convert-to-sales-kurir")->assertUnprocessable();
        $this->assertDatabaseCount('couriers', 2);
    }
}
