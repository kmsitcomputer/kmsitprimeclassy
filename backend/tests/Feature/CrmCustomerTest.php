<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CrmCustomer;
use App\Models\CrmVisit;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** IMP-006 slice 1 (BUSINESS-RULES §27, OD-01): CRM Customer + visit/follow-up authority and isolation. */
class CrmCustomerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id]);
        $sales = User::factory()->sales()->create(['agent_id' => $agen->id, 'korsal_id' => $korsal->id]);
        $sks = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id]);

        return compact('agen', 'admin', 'korsal', 'sales', 'sks');
    }

    private function make(User $owner, array $overrides = []): int
    {
        return $this->actingAs($owner)->postJson('/api/v1/crm/customers', $overrides + [
            'name' => 'Ibu Rina', 'phone' => '081234567890', 'customer_type' => 'reseller',
        ])->assertCreated()->json('data.id');
    }

    public function test_sales_and_sks_create_owned_customer_without_creating_a_user(): void
    {
        $b = $this->branch();
        $users = User::count();

        $id = $this->actingAs($b['sales'])->postJson('/api/v1/crm/customers', [
            'name' => 'Ibu Rina', 'phone' => '081234567890',
            // Client-supplied ownership must be ignored.
            'owner_user_id' => $b['sks']->id, 'agent_id' => 999,
        ])->assertCreated()->json('data.id');

        $row = CrmCustomer::findOrFail($id);
        $this->assertSame($b['sales']->id, $row->owner_user_id);
        $this->assertSame($b['agen']->id, $row->agent_id);
        $this->assertSame($users, User::count(), 'A CRM customer must never fabricate a User.');

        $this->make($b['sks'], ['name' => 'Pak Budi', 'phone' => '0811999888']);
    }

    public function test_only_owner_sales_can_write_and_read_roles_are_read_only(): void
    {
        $b = $this->branch();
        $id = $this->make($b['sales']);

        // OD-01: agen/admin/korsal(downline) read, never write.
        foreach (['agen', 'admin', 'korsal'] as $role) {
            $this->actingAs($b[$role])->getJson("/api/v1/crm/customers/{$id}")->assertOk();
            $this->actingAs($b[$role])->patchJson("/api/v1/crm/customers/{$id}", ['name' => 'X'])->assertForbidden();
            $this->actingAs($b[$role])->postJson("/api/v1/crm/customers/{$id}/visits", ['visit_date' => '2026-10-29', 'status' => '1'])->assertForbidden();
            if ($role !== 'korsal') { // Phase 2: a Korsal acts as Sales and may create its OWN customers (never write a downline's).
                $this->actingAs($b[$role])->postJson('/api/v1/crm/customers', ['name' => 'X'])->assertForbidden();
            }
        }

        // Another seller (SKS) cannot see or write it.
        $this->actingAs($b['sks'])->getJson("/api/v1/crm/customers/{$id}")->assertForbidden();
        $this->actingAs($b['sks'])->patchJson("/api/v1/crm/customers/{$id}", ['name' => 'X'])->assertForbidden();

        // A korsal without that sales in the downline has no read.
        $otherKorsal = User::factory()->korsal()->create(['agent_id' => $b['agen']->id]);
        $this->actingAs($otherKorsal)->getJson("/api/v1/crm/customers/{$id}")->assertForbidden();

        // Konsumen has no CRM access at all.
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $b['agen']->id]);
        $this->actingAs($konsumen)->getJson('/api/v1/crm/customers')->assertForbidden();

        $this->actingAs($b['sales'])->patchJson("/api/v1/crm/customers/{$id}", ['name' => 'Ibu Rina Baru'])->assertOk();
        $this->assertSame('Ibu Rina Baru', CrmCustomer::findOrFail($id)->name);
    }

    public function test_cross_agent_isolation_on_list_show_and_write(): void
    {
        $b = $this->branch();
        $other = $this->branch();
        $id = $this->make($b['sales']);
        $this->make($other['sales'], ['name' => 'Other Agent Customer', 'phone' => '0877111222']);

        $this->actingAs($other['agen'])->getJson("/api/v1/crm/customers/{$id}")->assertNotFound();
        $this->actingAs($other['sales'])->patchJson("/api/v1/crm/customers/{$id}", ['name' => 'X'])->assertNotFound();
        $this->actingAs($other['sales'])->postJson("/api/v1/crm/customers/{$id}/visits", ['visit_date' => '2026-10-29', 'status' => '1'])->assertNotFound();

        $names = collect($this->actingAs($b['agen'])->getJson('/api/v1/crm/customers')->assertOk()->json('data.data'))->pluck('name');
        $this->assertSame(['Ibu Rina'], $names->all());

        $this->actingAs($b['korsal'])->getJson('/api/v1/crm/customers')->assertOk()->assertJsonCount(1, 'data.data');
        $this->actingAs($b['sks'])->getJson('/api/v1/crm/customers')->assertOk()->assertJsonCount(0, 'data.data');
    }

    public function test_duplicate_phone_per_owner_is_rejected_after_normalization(): void
    {
        $b = $this->branch();
        $this->make($b['sales'], ['phone' => '081234567890']);

        $this->actingAs($b['sales'])->postJson('/api/v1/crm/customers', ['name' => 'Dup', 'phone' => '+6281234567890'])->assertStatus(422);
        // A different seller may hold the same contact separately.
        $this->actingAs($b['sks'])->postJson('/api/v1/crm/customers', ['name' => 'Dup', 'phone' => '081234567890'])->assertCreated();
    }

    public function test_visit_vocabulary_rejects_fu_and_d_and_bad_prospect(): void
    {
        $b = $this->branch();
        $id = $this->make($b['sales']);

        // Owner 2026-10-30: FU is a follow-up (plan_next_date), not a status; D is the alias of 7 (tested below).
        foreach (['FU', 'fu', '8', '0', ''] as $status) {
            $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$id}/visits", ['visit_date' => '2026-10-29', 'status' => $status])->assertStatus(422);
        }
        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$id}/visits", ['visit_date' => '2026-10-29', 'status' => 'N', 'prospect_percent' => 33])->assertStatus(422);
        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$id}/visits", ['visit_date' => '2026-10-29', 'status' => 'N', 'plan_next_date' => '2026-10-01'])->assertStatus(422);
        $this->assertSame(0, CrmVisit::withoutGlobalScopes()->count());

        foreach (['N', '1', '7'] as $status) {
            $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$id}/visits", ['visit_date' => '2026-10-29', 'status' => $status])->assertCreated();
        }
    }

    public function test_d_is_stored_as_deal_7_and_stage_filters_use_the_existing_follow_up_mechanism(): void
    {
        $b = $this->branch();
        $deal = $this->make($b['sales'], ['name' => 'Deal', 'phone' => '0811100001']);
        $follow = $this->make($b['sales'], ['name' => 'Follow', 'phone' => '0811100002']);
        $plain = $this->make($b['sales'], ['name' => 'Plain', 'phone' => '0811100003']);

        $stored = $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$deal}/visits", ['visit_date' => '2026-10-20', 'status' => 'D'])
            ->assertCreated()->json('data.status');
        $this->assertSame('7', $stored, 'D must normalize to 7, never a duplicate status');
        $this->assertSame(0, CrmVisit::withoutGlobalScopes()->where('status', 'D')->count());

        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$follow}/visits", ['visit_date' => '2026-10-20', 'status' => '2', 'plan_next_date' => '2026-11-01'])->assertCreated();
        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$plain}/visits", ['visit_date' => '2026-10-20', 'status' => '2'])->assertCreated();

        $ids = fn (string $stage) => collect($this->actingAs($b['agen'])->getJson("/api/v1/crm/customers?stage={$stage}")->assertOk()->json('data.data'))->pluck('id')->all();
        $this->assertSame([$deal], $ids('D'));
        $this->assertSame([$follow], $ids('FU'));

        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$follow}/visits", ['visit_date' => '2026-10-21', 'status' => 'FU'])
            ->assertStatus(422);
    }

    public function test_visit_history_updates_stage_and_audits_changes(): void
    {
        $b = $this->branch();
        $id = $this->make($b['sales']);

        $first = $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$id}/visits", [
            'visit_date' => '2026-10-20', 'status' => '1', 'prospect_percent' => 25, 'note' => 'Intro',
        ])->assertCreated()->json('data.id');
        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$id}/visits", [
            'visit_date' => '2026-10-27', 'status' => '3', 'prospect_percent' => 75,
        ])->assertCreated();

        $this->assertSame(75, CrmCustomer::findOrFail($id)->prospect_percent);
        $dates = collect($this->actingAs($b['agen'])->getJson("/api/v1/crm/customers/{$id}/visits")->assertOk()->json('data'))->pluck('visit_date');
        $this->assertCount(2, $dates);
        $this->assertStringStartsWith('2026-10-27', $dates[0]);

        $this->actingAs($b['sales'])->patchJson("/api/v1/crm/visits/{$first}", ['note' => 'Intro (corrected)'])->assertOk();
        $log = ActivityLog::where('event', 'crm.visit.updated')->firstOrFail();
        $this->assertSame('Intro', $log->properties['old']['note']);
        $this->assertSame($b['sales']->id, $log->causer_id);

        // Another seller cannot edit it; read-only roles cannot either.
        $this->actingAs($b['sks'])->patchJson("/api/v1/crm/visits/{$first}", ['note' => 'x'])->assertForbidden();
        $this->actingAs($b['agen'])->patchJson("/api/v1/crm/visits/{$first}", ['note' => 'x'])->assertForbidden();
    }

    public function test_follow_ups_use_latest_plan_per_customer_inside_read_scope(): void
    {
        $b = $this->branch();
        $a = $this->make($b['sales'], ['name' => 'A', 'phone' => '0811000001']);
        $c = $this->make($b['sales'], ['name' => 'C', 'phone' => '0811000002']);
        $other = $this->make($b['sks'], ['name' => 'Other', 'phone' => '0811000003']);

        // A: older plan is superseded by a later visit with no plan -> not due.
        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$a}/visits", ['visit_date' => '2026-10-01', 'status' => '1', 'plan_next_date' => '2026-10-05'])->assertCreated();
        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$a}/visits", ['visit_date' => '2026-10-06', 'status' => '2'])->assertCreated();
        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$c}/visits", ['visit_date' => '2026-10-02', 'status' => '1', 'plan_next_date' => '2026-10-10'])->assertCreated();
        $this->actingAs($b['sks'])->postJson("/api/v1/crm/customers/{$other}/visits", ['visit_date' => '2026-10-02', 'status' => '1', 'plan_next_date' => '2026-10-11'])->assertCreated();

        $mine = $this->actingAs($b['sales'])->getJson('/api/v1/crm/follow-ups?until=2026-10-29')->assertOk()->json('data');
        $this->assertSame([$c], array_column($mine, 'crm_customer_id'));

        $branch = $this->actingAs($b['agen'])->getJson('/api/v1/crm/follow-ups?until=2026-10-29')->assertOk()->json('data');
        $this->assertCount(2, $branch);

        $this->actingAs($b['sales'])->getJson('/api/v1/crm/follow-ups?until=2026-10-09')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_archive_is_reversible_and_hidden_from_default_list(): void
    {
        $b = $this->branch();
        $id = $this->make($b['sales']);

        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$id}/archive")->assertOk();
        $this->actingAs($b['sales'])->getJson('/api/v1/crm/customers')->assertJsonCount(0, 'data.data');
        $this->actingAs($b['sales'])->getJson('/api/v1/crm/customers?archived=1')->assertJsonCount(1, 'data.data');

        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$id}/archive", ['restore' => true])->assertOk();
        $this->actingAs($b['sales'])->getJson('/api/v1/crm/customers')->assertJsonCount(1, 'data.data');
        $this->assertSame(2, ActivityLog::whereIn('event', ['crm.customer.archived', 'crm.customer.restored'])->count());
    }
}
