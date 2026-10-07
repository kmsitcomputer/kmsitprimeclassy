<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Human UAT follow-up (Finding 2): Koordinator Kurir must have a Dashboard navigation entry,
 * and the destination plus the existing Dispatch / Pengiriman Saya entries must be genuinely
 * authorized — a nav button alone is not authorization.
 *
 * What this proves at the only layer tests can reach (there is no frontend test harness):
 *   1. the koordinator reaches its two workspaces: GET /dispatch and GET /kurir/orders;
 *   2. it does NOT gain unrelated surfaces: finance reports, the gudang queue, fulfillment
 *      approval review, or the office financial summary behind the dashboard page;
 *   3. other roles' access is unchanged (gudang/kurir still cannot open dispatch; konsumen
 *      still cannot open the courier queue).
 *
 * The frontend change itself (AppHeader DASHBOARD_ROLES + DashboardHomeView label) reuses the
 * existing `dashboard-home` destination, whose route guard already lists koordinator-kurir and
 * whose page renders role-driven quick links without calling any office-only API for this role.
 */
class KoordinatorAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    /** @return array{agen:User, koordinator:User, gudang:User, kurir:User, konsumen:User} */
    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko Nav', 'address' => 'Jl. Nav',
            'latitude' => -6.2, 'longitude' => 106.8166,
        ]);

        return [
            'agen' => $agen,
            'koordinator' => User::factory()->koordinatorKurir()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]),
            'gudang' => User::factory()->gudang()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]),
            'kurir' => User::factory()->kurir()->create(['agent_id' => $agen->id]),
            'konsumen' => User::factory()->konsumen()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]),
        ];
    }

    // ---------- 1 + 3 + 4. Dispatch and Pengiriman Saya stay reachable ----------

    public function test_koordinator_reaches_dispatch_and_own_deliveries(): void
    {
        $b = $this->branch();

        $this->actingAs($b['koordinator'])->getJson('/api/v1/dispatch')->assertOk();
        $this->actingAs($b['koordinator'])->getJson('/api/v1/dispatch/couriers')->assertOk();
        $this->actingAs($b['koordinator'])->getJson('/api/v1/dispatch/regions')->assertOk();
        $this->actingAs($b['koordinator'])->getJson('/api/v1/kurir/orders')->assertOk();
    }

    // ---------- 2 + 6. No unrelated Admin/Finance/Gudang surface is gained ----------

    public function test_koordinator_gains_no_unrelated_admin_finance_or_gudang_surface(): void
    {
        $b = $this->branch();

        // Finance + office reporting.
        $this->actingAs($b['koordinator'])->getJson('/api/v1/reports/finance-orders')->assertForbidden();
        $this->actingAs($b['koordinator'])->getJson('/api/v1/reports/finance-summary')->assertForbidden();
        $this->actingAs($b['koordinator'])->getJson('/api/v1/dashboard/summary')->assertForbidden();

        // Gudang queue + fulfillment review.
        $this->actingAs($b['koordinator'])->getJson('/api/v1/warehouse/orders/diproses')->assertForbidden();
        $this->actingAs($b['koordinator'])->getJson('/api/v1/warehouse/fulfillment-proposals')->assertForbidden();

        // Normal-kurir return workflow (executor-only surfaces stay executor-only).
        $this->actingAs($b['koordinator'])->getJson('/api/v1/kurir/returns')->assertForbidden();
    }

    // ---------- 5. Other roles' access is unchanged ----------

    public function test_other_roles_dispatch_and_queue_access_is_unchanged(): void
    {
        $b = $this->branch();

        $this->actingAs($b['gudang'])->getJson('/api/v1/dispatch')->assertForbidden('gudang works the warehouse queue, not dispatch');
        $this->actingAs($b['kurir'])->getJson('/api/v1/dispatch')->assertForbidden('kurir executes, never dispatches');
        $this->actingAs($b['konsumen'])->getJson('/api/v1/dispatch')->assertForbidden();
        $this->actingAs($b['konsumen'])->getJson('/api/v1/kurir/orders')->assertForbidden();
        $this->actingAs($b['gudang'])->getJson('/api/v1/kurir/orders')->assertForbidden();
    }
}