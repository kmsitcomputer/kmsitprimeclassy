<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\CrmCustomer;
use App\Models\CrmVisit;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * GAP-B (Owner 2026-10-30): "Perlu Tindakan" = CRM follow-up. The /dashboard/kpis `follow_ups`
 * contract: due_today (plan_next_date = today WIB) + overdue (< today), from the customer's LATEST
 * visit only (a handled plan is superseded, never recounted), scoped Sales own / Korsal own+downline
 * via CrmAccess. No new task entity.
 */
class CrmFollowUpKpiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function makeNetwork(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id]);
        $sales = User::factory()->sales()->create(['agent_id' => $agen->id, 'korsal_id' => $korsal->id]);
        $sks = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id, 'korsal_id' => $korsal->id]);

        // Korsal's own customer.
        $own = CrmCustomer::create(['agent_id' => $agen->id, 'owner_user_id' => $korsal->id, 'name' => 'Ibu Korsal', 'phone' => '081200000101']);
        // The Sales' customers (Korsal downline scope; the SKS owns none here).
        $dueToday = CrmCustomer::create(['agent_id' => $agen->id, 'owner_user_id' => $sales->id, 'name' => 'Due Today', 'phone' => '081200000102']);
        $overdue = CrmCustomer::create(['agent_id' => $agen->id, 'owner_user_id' => $sales->id, 'name' => 'Overdue', 'phone' => '081200000103']);
        $handled = CrmCustomer::create(['agent_id' => $agen->id, 'owner_user_id' => $sales->id, 'name' => 'Handled', 'phone' => '081200000104']);
        $future = CrmCustomer::create(['agent_id' => $agen->id, 'owner_user_id' => $sales->id, 'name' => 'Future', 'phone' => '081200000105']);

        return compact('agen', 'korsal', 'sales', 'sks', 'own', 'dueToday', 'overdue', 'handled', 'future');
    }

    public function test_follow_ups_count_due_today_and_overdue_and_never_recount_handled_plans(): void
    {
        $n = $this->makeNetwork();
        $today = Carbon::now('Asia/Jakarta')->toDateString();
        $yesterday = Carbon::now('Asia/Jakarta')->subDay()->toDateString();
        $tomorrow = Carbon::now('Asia/Jakarta')->addDay()->toDateString();

        $this->actingAs($n['sales'])->postJson("/api/v1/crm/customers/{$n['dueToday']->id}/visits", [
            'visit_date' => $yesterday, 'status' => '2', 'plan_next_date' => $today,
        ])->assertCreated();
        $this->actingAs($n['sales'])->postJson("/api/v1/crm/customers/{$n['overdue']->id}/visits", [
            'visit_date' => Carbon::now('Asia/Jakarta')->subDay(5)->toDateString(), 'status' => '3', 'plan_next_date' => $yesterday,
        ])->assertCreated();
        // Handled: a planned follow-up, then a NEWER visit (no new plan) — the old plan is superseded.
        $this->actingAs($n['sales'])->postJson("/api/v1/crm/customers/{$n['handled']->id}/visits", [
            'visit_date' => Carbon::now('Asia/Jakarta')->subDay(5)->toDateString(), 'status' => '1', 'plan_next_date' => $yesterday,
        ])->assertCreated();
        $this->actingAs($n['sales'])->postJson("/api/v1/crm/customers/{$n['handled']->id}/visits", [
            'visit_date' => $yesterday, 'status' => '2',
        ])->assertCreated();
        // Future plans are never "due".
        $this->actingAs($n['sales'])->postJson("/api/v1/crm/customers/{$n['future']->id}/visits", [
            'visit_date' => $today, 'status' => '4', 'plan_next_date' => $tomorrow,
        ])->assertCreated();
        // Korsal's own customer is overdue.
        $this->actingAs($n['korsal'])->postJson("/api/v1/crm/customers/{$n['own']->id}/visits", [
            'visit_date' => Carbon::now('Asia/Jakarta')->subDay(3)->toDateString(), 'status' => '2', 'plan_next_date' => $yesterday,
        ])->assertCreated();

        $payload = $this->actingAs($n['korsal'])->getJson('/api/v1/dashboard/kpis')->assertOk()->json('data');
        $this->assertSame(['due_today' => 1, 'overdue' => 2], array_map('intval', $payload['follow_ups']));
    }

    public function test_sales_scope_is_ownership_only_and_agent_rows_never_leak(): void
    {
        $n = $this->makeNetwork();
        $today = Carbon::now('Asia/Jakarta')->toDateString();

        // The Korsal's OWN customer is planned — but ownership-only scope means the Sales must not count it.
        $this->actingAs($n['korsal'])->postJson("/api/v1/crm/customers/{$n['own']->id}/visits", [
            'visit_date' => $today, 'status' => '2', 'plan_next_date' => $today,
        ])->assertCreated();
        // The Sales' own customer IS planned.
        $this->actingAs($n['sales'])->postJson("/api/v1/crm/customers/{$n['dueToday']->id}/visits", [
            'visit_date' => $today, 'status' => '2', 'plan_next_date' => $today,
        ])->assertCreated();

        $payload = $this->actingAs($n['sales'])->getJson('/api/v1/dashboard/kpis')->assertOk()->json('data');
        $this->assertSame(['due_today' => 1, 'overdue' => 0], array_map('intval', $payload['follow_ups']));

        // Another branch's Sales sees none of this branch's rows.
        $other = $this->makeNetwork();
        $this->actingAs($other['sales'])->postJson("/api/v1/crm/customers", ['name' => 'Lain', 'phone' => '081200000201'])->assertCreated();

        $otherKpi = $this->actingAs($other['sales'])->getJson('/api/v1/dashboard/kpis')->assertOk()->json('data');
        $this->assertSame(['due_today' => 0, 'overdue' => 0], array_map('intval', $otherKpi['follow_ups']));
    }
}
