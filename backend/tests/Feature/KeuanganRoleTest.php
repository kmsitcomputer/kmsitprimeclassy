<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 — the KEUANGAN role: agent-scoped, creatable only by agen/
 * super_admin, and deliberately NOT a transaction-operations role.
 */
class KeuanganRoleTest extends TestCase
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
            'name' => 'Bendahara Cabang',
            'email' => 'keuangan-'.uniqid().'@example.com',
            'phone' => '081200000001',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $overrides);
    }

    public function test_agen_can_create_a_keuangan_account_in_its_own_branch_without_a_referral_code(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);

        $response = $this->actingAs($agen)->postJson('/api/v1/users', $this->payload(['role' => 'keuangan']));

        $response->assertCreated();
        $created = User::query()->where('email', $response->json('data.email'))->firstOrFail();

        $this->assertSame('keuangan', $created->role->slug);
        $this->assertSame($agen->id, $created->agent_id, 'keuangan must belong to the creator agen branch');
        $this->assertNull($created->referral_code, 'keuangan must never own a referral code');
    }

    public function test_super_admin_creating_keuangan_is_forbidden(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->postJson('/api/v1/users', $this->payload(['role' => 'keuangan']))
            ->assertForbidden();

        $agen = User::factory()->agen()->create();

        $this->actingAs($superAdmin)
            ->postJson('/api/v1/users', $this->payload(['role' => 'keuangan', 'agent_id' => $agen->id]))
            ->assertForbidden();
    }

    public function test_korsal_sales_and_admin_cannot_create_keuangan(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);

        foreach ([
            User::factory()->korsal()->create(['agent_id' => $agen->id]),
            User::factory()->sales()->create(['agent_id' => $agen->id]),
            User::factory()->admin()->create(['agent_id' => $agen->id]),
        ] as $actor) {
            $this->actingAs($actor)
                ->postJson('/api/v1/users', $this->payload(['role' => 'keuangan']))
                ->assertStatus(403);
        }
    }

    public function test_keuangan_cannot_create_any_account(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $agen->id]);

        $this->actingAs($keuangan)
            ->postJson('/api/v1/users', $this->payload(['role' => 'sales']))
            ->assertStatus(403);
    }

    public function test_keuangan_permissions_are_financial_only(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $agen->id]);

        $permissions = $this->actingAs($keuangan)->getJson('/api/v1/auth/me')->json('data.permissions');

        foreach (['finance.view', 'finance.payment.verify', 'finance.cod.settle', 'finance.dp.verify', 'finance.dp.settle', 'finance.refund.manage', 'finance.additional.manage', 'users.view.network', 'orders.view.network'] as $capability) {
            $this->assertContains($capability, $permissions);
        }

        // Separation of duties: no operational capabilities, no system config.
        foreach (['orders.manage.status', 'orders.manage.fulfillment', 'orders.cancel', 'system.config.manage', 'system.payment.manage'] as $capability) {
            $this->assertNotContains($capability, $permissions);
        }
        $this->assertNull($keuangan->referral_code);
    }

    public function test_admin_permissions_no_longer_include_payment_management(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);

        $permissions = $this->actingAs($admin)->getJson('/api/v1/auth/me')->json('data.permissions');

        $this->assertNotContains('orders.manage.payment', $permissions);
        $this->assertNotContains('finance.payment.verify', $permissions);
        // Still the transaction-operations role.
        $this->assertContains('orders.manage.fulfillment', $permissions);
        $this->assertContains('orders.manage.status', $permissions);
    }

    public function test_keuangan_cannot_read_another_branchs_user(): void
    {
        $agenA = User::factory()->agen()->create();
        $agenA->update(['agent_id' => $agenA->id]);
        $agenB = User::factory()->agen()->create();
        $agenB->update(['agent_id' => $agenB->id]);

        $keuanganA = User::factory()->keuangan()->create(['agent_id' => $agenA->id]);
        $keuanganB = User::factory()->keuangan()->create(['agent_id' => $agenB->id]);

        $this->actingAs($keuanganA)->getJson("/api/v1/users/{$keuanganB->id}")->assertStatus(403);

        // And its own branch user is reachable.
        $this->actingAs($keuanganA)->getJson("/api/v1/users/{$keuanganA->id}")->assertOk();
    }
}
