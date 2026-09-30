<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WarehouseSubLocation;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finding 3 — the Sub Location owner selector must be able to reach ANY eligible Sales-Kurir-Sub,
 * not just page 1 of the generic user listing, and must never present a candidate the write
 * endpoints will reject. The endpoint is server-authoritative; the create/assign endpoints stay the
 * real gate.
 */
class SubLocationEligibleOwnersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function branch(): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $admin = User::factory()->admin()->create(['agent_id' => $agent->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);

        return compact('agent', 'admin', 'gudang');
    }

    private function eligible(User $agent, string $name): User
    {
        return User::factory()->salesKurirSub()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id, 'name' => $name]);
    }

    public function test_an_eligible_owner_on_a_later_page_and_by_search_is_reachable(): void
    {
        $b = $this->branch();
        $names = ['Owner 01', 'Owner 02', 'Owner 03', 'Owner 04', 'Owner 05'];
        foreach ($names as $name) {
            $this->eligible($b['agent'], $name);
        }

        $first = $this->actingAs($b['admin'])->getJson('/api/v1/warehouse/sub-locations/eligible-owners?per_page=2&page=1')->assertOk();
        $this->assertSame(5, $first->json('meta.total'));
        $this->assertSame(3, $first->json('meta.last_page'));
        $this->assertSame(['Owner 01', 'Owner 02'], array_column($first->json('data'), 'name'));

        $third = $this->actingAs($b['admin'])->getJson('/api/v1/warehouse/sub-locations/eligible-owners?per_page=2&page=3')->assertOk();
        $this->assertSame(['Owner 05'], array_column($third->json('data'), 'name'));

        $search = $this->actingAs($b['admin'])->getJson('/api/v1/warehouse/sub-locations/eligible-owners?search=Owner 05')->assertOk();
        $this->assertSame(['Owner 05'], array_column($search->json('data'), 'name'));
    }

    public function test_inactive_deleted_already_assigned_foreign_and_normal_sales_are_all_excluded(): void
    {
        $b = $this->branch();
        $other = $this->branch();

        $eligible = $this->eligible($b['agent'], 'Eligible Owner');
        $this->eligible($b['agent'], 'Inactive Owner')->update(['status' => 'inactive']);
        $this->eligible($b['agent'], 'Deleted Owner')->delete();
        $alreadyAssigned = $this->eligible($b['agent'], 'Assigned Owner');
        $this->eligible($b['agent'], 'Foreign Owner')->update(['agent_id' => $other['agent']->id]);
        User::factory()->sales()->create(['agent_id' => $b['agent']->id, 'parent_id' => $b['agent']->id, 'name' => 'Normal Sales']);

        $location = WarehouseSubLocation::create(['agent_id' => $b['agent']->id, 'code' => 'OWN', 'name' => 'Owned', 'created_by' => $b['agent']->id]);
        $location->forceFill(['owner_user_id' => $alreadyAssigned->id])->save();

        $response = $this->actingAs($b['admin'])->getJson('/api/v1/warehouse/sub-locations/eligible-owners?per_page=50')->assertOk();

        $this->assertSame([$eligible->id], array_column($response->json('data'), 'id'));
        $this->assertSame('sales-kurir-sub', $response->json('data.0.role'));
    }

    public function test_gudang_and_non_managers_cannot_use_the_owner_selector(): void
    {
        $b = $this->branch();
        $sub = $this->eligible($b['agent'], 'Owner');
        $sales = User::factory()->sales()->create(['agent_id' => $b['agent']->id, 'parent_id' => $b['agent']->id]);

        $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/sub-locations/eligible-owners')->assertForbidden();
        $this->actingAs($sales)->getJson('/api/v1/warehouse/sub-locations/eligible-owners')->assertForbidden();
        $this->actingAs($sub)->getJson('/api/v1/warehouse/sub-locations/eligible-owners')->assertForbidden();
        // The literal route must win over the `/{subLocation}` wildcard.
        $this->actingAs($b['admin'])->getJson('/api/v1/warehouse/sub-locations/eligible-owners')->assertOk();
    }

    public function test_forged_owner_submission_is_still_rejected_by_the_write_endpoints(): void
    {
        $b = $this->branch();
        $other = $this->branch();
        $foreignSub = $this->eligible($other['agent'], 'Foreign Owner');
        $inactiveSub = $this->eligible($b['agent'], 'Inactive Owner');
        $inactiveSub->update(['status' => 'inactive']);

        $this->actingAs($b['admin'])->postJson('/api/v1/warehouse/sub-locations', ['owner_user_id' => $foreignSub->id, 'code' => 'F1', 'name' => 'x'])->assertUnprocessable();
        $this->actingAs($b['admin'])->postJson('/api/v1/warehouse/sub-locations', ['owner_user_id' => $inactiveSub->id, 'code' => 'F2', 'name' => 'x'])->assertUnprocessable();

        $legacy = WarehouseSubLocation::create(['agent_id' => $b['agent']->id, 'code' => 'LEG', 'name' => 'Legacy', 'created_by' => $b['agent']->id]);
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/sub-locations/{$legacy->id}/assign-owner", ['owner_user_id' => $foreignSub->id])->assertUnprocessable();

        $this->assertSame(0, WarehouseSubLocation::withoutGlobalScopes()->whereNotNull('owner_user_id')->count());
    }
}
