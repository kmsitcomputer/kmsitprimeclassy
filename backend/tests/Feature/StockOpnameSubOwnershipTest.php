<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\User;
use App\Models\WarehouseSubLocation;
use App\Services\Stock\StockOpnameService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Group 1 / 4.2: the shared opname service enforces Sub Location ownership by itself. */
class StockOpnameSubOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function branch(): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $sub = WarehouseSubLocation::create(['agent_id' => $agent->id, 'code' => 'S'.$agent->id, 'name' => 'Sub', 'created_by' => $agent->id]);

        return compact('agent', 'gudang', 'sub');
    }

    private function create(User $gudang, int $loc)
    {
        $p = Product::firstOrCreate(['sku' => 'SO-1'], ['name' => 'P', 'slug' => 'so-1', 'has_variations' => false, 'status' => 'active']);

        return app(StockOpnameService::class)->create($gudang, 'physical_opname', 'sub', $loc, [['product_id' => $p->id]]);
    }

    public function test_own_active_sub_location_is_accepted(): void
    {
        $b = $this->branch();
        $this->assertSame('draft', $this->create($b['gudang'], $b['sub']->id)->fresh()->status);
    }

    public function test_cross_agent_sub_location_is_rejected_at_service_level_and_over_http(): void
    {
        $b = $this->branch();
        $other = $this->branch();

        try {
            $this->create($b['gudang'], $other['sub']->id);
            $this->fail('cross-Agent Sub Location must be rejected');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status());
        }
        $p = Product::first();
        $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/opnames', ['opname_type' => 'physical_opname', 'stock_type' => 'sub', 'sub_location_id' => $other['sub']->id, 'items' => [['product_id' => $p->id]]])->assertStatus(422);
        $this->assertSame(0, \App\Models\StockOpname::withoutGlobalScopes()->count());
    }

    public function test_inactive_sub_location_is_rejected(): void
    {
        $b = $this->branch();
        $b['sub']->update(['is_active' => false]);
        $this->expectException(ApiException::class);
        $this->create($b['gudang'], $b['sub']->id);
    }
}
