<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionMapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_me_endpoint_returns_permissions_matching_the_authenticated_role(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);

        $response = $this->actingAs($agen)->getJson('/api/v1/auth/me');

        $response->assertOk();
        $permissions = $response->json('data.permissions');

        $this->assertContains('users.create.korsal', $permissions);
        $this->assertContains('users.create.sales', $permissions);
        // Agen can now also CRUD admin/kurir within its own branch (HierarchyRules::ALLOWED_CREATIONS).
        $this->assertContains('users.create.admin', $permissions);
        $this->assertContains('users.create.kurir', $permissions);
        $this->assertContains('stock.manage', $permissions);
        $this->assertNotContains('system.config.manage', $permissions);
        $this->assertNotContains('users.create.agen', $permissions);
    }

    public function test_konsumen_permissions_never_include_management_capabilities(): void
    {
        $agen = User::factory()->agen()->create();
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);

        $permissions = $this->actingAs($konsumen)->getJson('/api/v1/auth/me')->json('data.permissions');

        $this->assertSame(['orders.view.own', 'orders.create'], $permissions);
    }

    public function test_super_admin_permissions_include_all_system_config_capabilities(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $permissions = $this->actingAs($superAdmin)->getJson('/api/v1/auth/me')->json('data.permissions');

        foreach (['system.config.manage', 'system.payment.manage', 'system.shipping.manage', 'system.cms.manage'] as $capability) {
            $this->assertContains($capability, $permissions);
        }
    }

    public function test_admin_permissions_include_stock_and_user_network_management_but_no_referral_code_capability(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);

        $permissions = $this->actingAs($admin)->getJson('/api/v1/auth/me')->json('data.permissions');

        // Fixes a frontend-only gap: backend already allows admin to reach
        // /stock/* and /users, but the nav/router previously blocked it
        // because these capabilities were missing here.
        $this->assertContains('stock.view.own', $permissions);
        $this->assertContains('stock.manage', $permissions);
        $this->assertContains('users.view.network', $permissions);
        // Admin owns no referral code (HierarchyRules::ROLES_WITH_REFERRAL_CODE) —
        // it never gets a users.create.* capability requiring one, and has no branch of its own to grant.
        $this->assertNotContains('users.create.agen', $permissions);
        $this->assertNull($admin->referral_code);
    }
}
