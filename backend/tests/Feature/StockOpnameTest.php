<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use App\Services\Stock\WarehouseStockService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockOpnameTest extends TestCase
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

    private function product(): Product
    {
        return Product::create(['sku' => 'OPN-'.uniqid(), 'name' => 'Opname Cake', 'slug' => 'opname-'.uniqid(), 'has_variations' => false, 'status' => 'active']);
    }

    public function test_index_returns_items_array_with_pagination_meta_and_agent_scope(): void
    {
        $b = $this->branch();
        $other = $this->branch();
        $p = $this->product();
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $p->id, 'stock_type' => 'transit', 'quantity' => 100]);
        for ($i = 0; $i < 16; $i++) {
            $opname = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/opnames', ['opname_type' => 'physical_opname', 'stock_type' => 'transit', 'items' => [['product_id' => $p->id]]])->assertCreated()->json('data');
            $this->actingAs($b['gudang'])->patchJson("/api/v1/warehouse/opnames/{$opname['id']}/count", ['counts' => [['item_id' => $opname['items'][0]['id'], 'counted_quantity' => 100]]])->assertOk();
        }
        $foreign = $this->actingAs($other['gudang'])->postJson('/api/v1/warehouse/opnames', ['opname_type' => 'physical_opname', 'stock_type' => 'transit', 'items' => [['product_id' => $p->id]]])->assertCreated()->json('data');

        $page1 = $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/opnames?per_page=15')->assertOk();
        $this->assertIsArray($page1->json('data'));
        $this->assertCount(15, $page1->json('data'));
        $this->assertSame(1, $page1->json('meta.current_page'));
        $this->assertSame(2, $page1->json('meta.last_page'));
        $this->assertSame(15, $page1->json('meta.per_page'));
        $this->assertSame(16, $page1->json('meta.total'));
        $this->assertArrayNotHasKey('data', $page1->json('data.0') ?? []);
        foreach ($page1->json('data') as $row) {
            $this->assertArrayHasKey('id', $row);
            $this->assertArrayHasKey('status', $row);
        }

        $page2 = $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/opnames?per_page=15&page=2')->assertOk();
        $this->assertCount(1, $page2->json('data'));
        $this->assertSame(2, $page2->json('meta.current_page'));
        $this->assertNotSame(collect($page1->json('data'))->pluck('id')->all(), collect($page2->json('data'))->pluck('id')->all());
        $this->assertNotContains($foreign['id'], collect($page2->json('data'))->pluck('id')->all());
    }

    public function test_gudang_draft_and_submit_do_not_mutate_stock_and_only_admin_approves(): void
    {
        $b = $this->branch();
        $p = $this->product();
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $p->id, 'stock_type' => 'transit', 'quantity' => 100]);
        $opname = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/opnames', ['opname_type' => 'physical_opname', 'stock_type' => 'transit', 'items' => [['product_id' => $p->id]]])->assertCreated()->json('data');
        $this->actingAs($b['gudang'])->patchJson("/api/v1/warehouse/opnames/{$opname['id']}/count", ['counts' => [['item_id' => $opname['items'][0]['id'], 'counted_quantity' => 98]]])->assertOk();
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $p->id, 'stock_type' => 'transit', 'quantity' => 100]);
        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/opnames/{$opname['id']}/submit")->assertOk();
        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/opnames/{$opname['id']}/approve")->assertForbidden();
        $this->actingAs($b['agent'])->postJson("/api/v1/warehouse/opnames/{$opname['id']}/approve")->assertForbidden();
        $this->actingAs(User::factory()->superAdmin()->create())->postJson("/api/v1/warehouse/opnames/{$opname['id']}/approve")->assertForbidden();
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/opnames/{$opname['id']}/approve")->assertOk();
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $p->id, 'stock_type' => 'transit', 'quantity' => 98]);
        $this->assertDatabaseHas('stock_movements', ['opname_id' => $opname['id'], 'type' => 'opname_adjustment', 'quantity' => -2]);
    }

    public function test_stale_snapshot_rejects_approval_without_mutation(): void
    {
        $b = $this->branch();
        $p = $this->product();
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $p->id, 'stock_type' => 'transit', 'quantity' => 100]);
        $o = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/opnames', ['opname_type' => 'physical_opname', 'stock_type' => 'transit', 'items' => [['product_id' => $p->id]]])->json('data');
        $this->actingAs($b['gudang'])->patchJson("/api/v1/warehouse/opnames/{$o['id']}/count", ['counts' => [['item_id' => $o['items'][0]['id'], 'counted_quantity' => 98]]]);
        WarehouseStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->where('product_id', $p->id)->where('stock_type', 'transit')->update(['quantity' => 90]);
        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/opnames/{$o['id']}/submit");
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/opnames/{$o['id']}/approve")->assertStatus(409);
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $p->id, 'stock_type' => 'transit', 'quantity' => 90]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_sub_physical_opname_is_location_scoped_and_plan_uses_plan_movement(): void
    {
        $b = $this->branch();
        $p = $this->product();
        $sub = WarehouseSubLocation::create(['agent_id' => $b['agent']->id, 'code' => 'OPN-SUB', 'name' => 'Sub', 'created_by' => $b['agent']->id]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $p->id, 'stock_type' => 'sub', 'sub_location_id' => $sub->id, 'quantity' => 50]);
        $o = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/opnames', ['opname_type' => 'physical_opname', 'stock_type' => 'sub', 'sub_location_id' => $sub->id, 'items' => [['product_id' => $p->id]]])->assertCreated()->json('data');
        $this->actingAs($b['gudang'])->patchJson("/api/v1/warehouse/opnames/{$o['id']}/count", ['counts' => [['item_id' => $o['items'][0]['id'], 'counted_quantity' => 47]]]);
        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/opnames/{$o['id']}/submit");
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/opnames/{$o['id']}/approve")->assertOk();
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $p->id, 'stock_type' => 'sub', 'sub_location_id' => $sub->id, 'quantity' => 47]);
        WarehouseSetting::create(['agent_id' => $b['agent']->id, 'factory_plan_enabled' => true]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $p->id, 'stock_type' => 'factory_plan', 'quantity' => 100]);
        $plan = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/opnames', ['opname_type' => 'plan_reconciliation', 'stock_type' => 'factory_plan', 'items' => [['product_id' => $p->id]]])->assertCreated()->json('data');
        $this->actingAs($b['gudang'])->patchJson("/api/v1/warehouse/opnames/{$plan['id']}/count", ['counts' => [['item_id' => $plan['items'][0]['id'], 'counted_quantity' => 90]]]);
        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/opnames/{$plan['id']}/submit");
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/opnames/{$plan['id']}/approve")->assertOk();
        $this->assertDatabaseHas('stock_movements', ['opname_id' => $plan['id'], 'type' => 'factory_plan_out', 'quantity' => -10]);
        $this->assertDatabaseMissing('stock_movements', ['opname_id' => $plan['id'], 'type' => 'opname_adjustment']);
    }

    public function test_physical_shortage_preserves_reservation_and_surfaces_deficit(): void
    {
        $b = $this->branch();
        $p = $this->product();
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $p->id, 'stock_type' => 'transit', 'quantity' => 100]);
        ProductStock::create(['agent_id' => $b['agent']->id, 'product_id' => $p->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 90]);
        $o = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/opnames', ['opname_type' => 'physical_opname', 'stock_type' => 'transit', 'items' => [['product_id' => $p->id]]])->json('data');
        $this->actingAs($b['gudang'])->patchJson("/api/v1/warehouse/opnames/{$o['id']}/count", ['counts' => [['item_id' => $o['items'][0]['id'], 'counted_quantity' => 80]]]);
        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/opnames/{$o['id']}/submit");
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/opnames/{$o['id']}/approve")->assertOk();
        $this->assertDatabaseHas('product_stocks', ['product_id' => $p->id, 'quantity_reserved' => 90]);
        $this->assertSame(0, app(WarehouseStockService::class)->sellableForProduct($b['agent']->id, $p->id)['available']);
    }
}
