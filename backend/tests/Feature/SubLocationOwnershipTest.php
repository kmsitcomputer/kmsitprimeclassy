<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WarehouseSubLocation;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** R-01 — 1 Sales-Kurir-Sub user = exactly 1 Sub Location, enforced server-side. */
class SubLocationOwnershipTest extends TestCase
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
        $sub = User::factory()->salesKurirSub()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);

        return compact('agent', 'admin', 'gudang', 'sub');
    }

    private function create(User $actor, ?User $owner, string $code): TestResponse
    {
        return $this->actingAs($actor)->postJson('/api/v1/warehouse/sub-locations', ['owner_user_id' => $owner?->id, 'code' => $code, 'name' => 'Sub '.$code]);
    }

    public function test_agent_creates_a_sub_location_owned_by_exactly_one_sales_kurir_sub(): void
    {
        $b = $this->branch();

        $id = $this->create($b['agent'], $b['sub'], 'S1')->assertCreated()->assertJsonPath('data.owner_user_id', $b['sub']->id)->json('data.id');

        $this->assertSame($id, $b['sub']->fresh()->ownedSubLocation->id);
    }

    public function test_gudang_cannot_create_a_sub_location_or_assign_an_owner(): void
    {
        $b = $this->branch();
        $legacy = WarehouseSubLocation::create(['agent_id' => $b['agent']->id, 'code' => 'LEG', 'name' => 'Legacy', 'created_by' => $b['agent']->id]);

        $this->create($b['gudang'], $b['sub'], 'S1')->assertForbidden();
        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/sub-locations/{$legacy->id}/assign-owner", ['owner_user_id' => $b['sub']->id])->assertForbidden();
        $this->assertDatabaseCount('warehouse_sub_locations', 1);
        $this->assertNull($legacy->fresh()->owner_user_id);
    }

    public function test_one_user_cannot_own_two_sub_locations(): void
    {
        $b = $this->branch();
        $this->create($b['agent'], $b['sub'], 'S1')->assertCreated();

        $this->create($b['agent'], $b['sub'], 'S2')->assertUnprocessable();

        $this->assertSame(1, WarehouseSubLocation::withoutGlobalScopes()->where('owner_user_id', $b['sub']->id)->count());
    }

    public function test_database_unique_index_rejects_two_locations_for_one_owner_and_two_owners_for_one_location(): void
    {
        $b = $this->branch();
        $a = WarehouseSubLocation::create(['agent_id' => $b['agent']->id, 'code' => 'A', 'name' => 'A', 'created_by' => $b['agent']->id]);
        $c = WarehouseSubLocation::create(['agent_id' => $b['agent']->id, 'code' => 'C', 'name' => 'C', 'created_by' => $b['agent']->id]);
        $a->forceFill(['owner_user_id' => $b['sub']->id])->save();

        $this->expectException(QueryException::class);
        $c->forceFill(['owner_user_id' => $b['sub']->id])->save();
    }

    public function test_one_sub_location_cannot_get_a_second_owner_or_be_silently_reassigned(): void
    {
        $b = $this->branch();
        $other = User::factory()->salesKurirSub()->create(['agent_id' => $b['agent']->id, 'parent_id' => $b['agent']->id]);
        $id = $this->create($b['agent'], $b['sub'], 'S1')->assertCreated()->json('data.id');

        $this->actingAs($b['agent'])->postJson("/api/v1/warehouse/sub-locations/{$id}/assign-owner", ['owner_user_id' => $other->id])->assertUnprocessable();

        $this->assertSame($b['sub']->id, WarehouseSubLocation::withoutGlobalScopes()->find($id)->owner_user_id);
    }

    public function test_cross_agent_ownership_and_forged_owner_ids_are_rejected(): void
    {
        $a = $this->branch();
        $other = $this->branch();
        $nonSub = User::factory()->sales()->create(['agent_id' => $a['agent']->id, 'parent_id' => $a['agent']->id]);

        $this->create($a['agent'], $other['sub'], 'X1')->assertUnprocessable();      // other Agent's Sales-Kurir-Sub
        $this->create($a['agent'], $nonSub, 'X2')->assertUnprocessable();            // wrong role
        $this->actingAs($a['agent'])->postJson('/api/v1/warehouse/sub-locations', ['owner_user_id' => 999999, 'code' => 'X3', 'name' => 'x'])->assertUnprocessable(); // forged id
        $this->actingAs($a['agent'])->postJson('/api/v1/warehouse/sub-locations', ['code' => 'X4', 'name' => 'x'])->assertUnprocessable();                          // owner required
        $this->create($a['agent'], $a['sub'], 'OK')->assertCreated();
        $legacy = WarehouseSubLocation::create(['agent_id' => $a['agent']->id, 'code' => 'LEG', 'name' => 'Legacy', 'created_by' => $a['agent']->id]);
        $this->actingAs($other['agent'])->postJson("/api/v1/warehouse/sub-locations/{$legacy->id}/assign-owner", ['owner_user_id' => $other['sub']->id])->assertNotFound();

        $this->assertSame(2, WarehouseSubLocation::withoutGlobalScopes()->count());
    }

    public function test_legacy_unowned_location_is_only_mapped_by_an_explicit_assignment(): void
    {
        $b = $this->branch();
        $legacy = WarehouseSubLocation::create(['agent_id' => $b['agent']->id, 'code' => 'LEG', 'name' => 'Legacy', 'created_by' => $b['agent']->id]);
        $this->assertNull($legacy->fresh()->owner_user_id, 'existing Sub Locations are never auto-mapped');
        $this->assertNull($b['sub']->fresh()->ownedSubLocation);

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/sub-locations/{$legacy->id}/assign-owner", ['owner_user_id' => $b['sub']->id])->assertOk();

        $this->assertSame($b['sub']->id, $legacy->fresh()->owner_user_id);
    }

    public function test_deactivation_releases_ownership_so_the_owner_can_be_remapped(): void
    {
        $b = $this->branch();
        $id = $this->create($b['agent'], $b['sub'], 'S1')->assertCreated()->json('data.id');

        $this->actingAs($b['agent'])->postJson("/api/v1/warehouse/sub-locations/{$id}/deactivate")->assertOk();

        $row = WarehouseSubLocation::withoutGlobalScopes()->find($id);
        $this->assertNull($row->owner_user_id);
        $this->assertSame($b['sub']->id, $row->previous_owner_user_id);
        $this->create($b['agent'], $b['sub'], 'S2')->assertCreated();
    }
}
