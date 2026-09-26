<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariation;
use App\Models\ProductVariationStock;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Models\WarehouseStockRequest;
use App\Services\Stock\SellableStockService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseStockAdditionApprovalTest extends TestCase
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
        $admin = User::factory()->admin()->create(['agent_id' => $agent->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $foreign = User::factory()->agen()->create();
        $foreign->update(['agent_id' => $foreign->id]);
        $foreignAdmin = User::factory()->admin()->create(['agent_id' => $foreign->id]);

        return compact('agent', 'admin', 'gudang', 'foreign', 'foreignAdmin');
    }

    private function product(): Product
    {
        return Product::create(['sku' => 'ADD-'.uniqid(), 'name' => 'Addition Cake', 'slug' => 'addition-'.uniqid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
    }

    private function transit(int $agentId, int $productId, ?int $variationId = null): int
    {
        return (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('stock_type', 'transit')
            ->when($variationId, fn ($q) => $q->where('product_variation_id', $variationId))
            ->when(! $variationId, fn ($q) => $q->where('product_id', $productId))->sum('quantity');
    }

    private function plan(int $agentId, int $productId, ?int $variationId = null): int
    {
        return (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('stock_type', 'factory_plan')
            ->when($variationId, fn ($q) => $q->where('product_variation_id', $variationId))
            ->when(! $variationId, fn ($q) => $q->where('product_id', $productId))->sum('quantity');
    }

    public function test_gudang_creates_transit_request_with_zero_inventory_effect(): void
    {
        $b = $this->branch();
        $product = $this->product();
        WarehouseSetting::create(['agent_id' => $b['agent']->id, 'factory_plan_enabled' => true]);
        ProductStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 50]);
        $before = app(SellableStockService::class)->forProduct($b['agent']->id, $product->id)['available'];

        $response = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/stock-addition-requests', [
            'product_id' => $product->id, 'quantity' => 20, 'target_stock_type' => 'transit', 'reference' => 'DO-1',
        ])->assertCreated();

        $this->assertSame('pending', $response->json('data.status'));
        $this->assertSame(50, $this->transit($b['agent']->id, $product->id));
        $this->assertSame($before, app(SellableStockService::class)->forProduct($b['agent']->id, $product->id)['available']);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_admin_approves_transit_request_exactly_once(): void
    {
        $b = $this->branch();
        $product = $this->product();
        WarehouseSetting::create(['agent_id' => $b['agent']->id, 'factory_plan_enabled' => false]);
        ProductStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);

        $request = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/stock-addition-requests', [
            'product_id' => $product->id, 'quantity' => 25, 'target_stock_type' => 'transit', 'reference' => 'DO-2',
        ])->assertCreated()->json('data');

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/stock-addition-requests/{$request['id']}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertSame(25, $this->transit($b['agent']->id, $product->id));
        $this->assertSame(25, app(SellableStockService::class)->forProduct($b['agent']->id, $product->id)['available']);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_movements', [
            'agent_id' => $b['agent']->id, 'product_id' => $product->id, 'type' => 'factory_in',
            'stock_type' => 'transit', 'quantity' => 25, 'warehouse_stock_request_id' => $request['id'],
        ]);
    }

    public function test_double_approval_does_not_double_inventory(): void
    {
        $b = $this->branch();
        $product = $this->product();
        WarehouseSetting::create(['agent_id' => $b['agent']->id, 'factory_plan_enabled' => false]);

        $request = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/stock-addition-requests', [
            'product_id' => $product->id, 'quantity' => 10, 'target_stock_type' => 'transit', 'reference' => 'DO-3',
        ])->assertCreated()->json('data');

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/stock-addition-requests/{$request['id']}/approve")->assertOk();
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/stock-addition-requests/{$request['id']}/approve")->assertOk();

        $this->assertSame(10, $this->transit($b['agent']->id, $product->id));
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_rejection_changes_no_inventory(): void
    {
        $b = $this->branch();
        $product = $this->product();
        WarehouseSetting::create(['agent_id' => $b['agent']->id, 'factory_plan_enabled' => false]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 7]);

        $request = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/stock-addition-requests', [
            'product_id' => $product->id, 'quantity' => 10, 'target_stock_type' => 'transit',
        ])->assertCreated()->json('data');

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/stock-addition-requests/{$request['id']}/reject", ['reason' => 'dokumen kurang'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertSame(7, $this->transit($b['agent']->id, $product->id));
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertDatabaseHas('warehouse_stock_requests', ['id' => $request['id'], 'status' => 'rejected']);
    }

    public function test_plan_approval_contributes_to_sellable_only_when_enabled(): void
    {
        $b = $this->branch();
        $product = $this->product();
        WarehouseSetting::create(['agent_id' => $b['agent']->id, 'factory_plan_enabled' => false]);
        ProductStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);

        $request = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/stock-addition-requests', [
            'product_id' => $product->id, 'quantity' => 40, 'target_stock_type' => 'factory_plan', 'reference' => 'PLAN-1',
        ])->assertCreated()->json('data');

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/stock-addition-requests/{$request['id']}/approve")->assertOk();

        $this->assertSame(40, $this->plan($b['agent']->id, $product->id));
        $this->assertSame(0, app(SellableStockService::class)->forProduct($b['agent']->id, $product->id)['available']);
        $this->assertDatabaseHas('stock_movements', ['type' => 'factory_plan_in', 'quantity' => 40, 'warehouse_stock_request_id' => $request['id']]);

        WarehouseSetting::query()->where('agent_id', $b['agent']->id)->update(['factory_plan_enabled' => true]);
        $this->assertSame(40, app(SellableStockService::class)->forProduct($b['agent']->id, $product->id)['available']);
    }

    public function test_authorization_boundaries(): void
    {
        $b = $this->branch();
        $product = $this->product();
        WarehouseSetting::create(['agent_id' => $b['agent']->id, 'factory_plan_enabled' => false]);

        $request = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/stock-addition-requests', [
            'product_id' => $product->id, 'quantity' => 5, 'target_stock_type' => 'transit',
        ])->assertCreated()->json('data');

        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/stock-addition-requests/{$request['id']}/approve")->assertForbidden();
        $this->actingAs($b['foreignAdmin'])->postJson("/api/v1/warehouse/stock-addition-requests/{$request['id']}/approve")->assertNotFound();
        $this->actingAs($b['foreignAdmin'])->postJson("/api/v1/warehouse/stock-addition-requests/{$request['id']}/reject", ['reason' => 'x'])->assertNotFound();
        $superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($superAdmin)->postJson("/api/v1/warehouse/stock-addition-requests/{$request['id']}/approve")->assertForbidden();
        $this->actingAs($superAdmin)->getJson('/api/v1/warehouse/stock-addition-requests')->assertForbidden();

        $this->assertSame('pending', WarehouseStockRequest::withoutGlobalScopes()->findOrFail($request['id'])->status);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_old_direct_mutation_bypass_is_inaccessible(): void
    {
        $b = $this->branch();
        $product = $this->product();
        $payload = ['product_id' => $product->id, 'quantity' => 10, 'reference' => 'DO-X'];

        $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/transit/receive', $payload)->assertNotFound();
        $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/factory-plan', ['product_id' => $product->id, 'delta' => 5, 'reference' => 'PLAN-X'])->assertNotFound();
        $this->actingAs($b['admin'])->postJson('/api/v1/warehouse/transit/receive', $payload)->assertNotFound();

        $this->assertSame(0, WarehouseStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->count());

        $this->actingAs($b['admin'])->patchJson('/api/v1/warehouse/settings/factory-plan', ['factory_plan_enabled' => true])->assertOk();
    }

    public function test_pending_request_does_not_change_storefront_stock(): void
    {
        $b = $this->branch();
        $product = $this->product();
        WarehouseSetting::create(['agent_id' => $b['agent']->id, 'factory_plan_enabled' => true]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 12]);

        $storefrontBefore = $this->actingAs($b['agent'])->getJson('/api/v1/products/'.$product->slug)->assertOk()->json('data.stock.available');

        $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/stock-addition-requests', [
            'product_id' => $product->id, 'quantity' => 30, 'target_stock_type' => 'transit',
        ])->assertCreated();

        $storefrontAfter = $this->actingAs($b['agent'])->getJson('/api/v1/products/'.$product->slug)->assertOk()->json('data.stock.available');
        $this->assertSame($storefrontBefore, $storefrontAfter);
        $this->assertSame(12, $storefrontAfter);
    }

    public function test_target_invariant_enforced(): void
    {
        $b = $this->branch();
        $product = $this->product();

        $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/stock-addition-requests', [
            'quantity' => 5, 'target_stock_type' => 'transit',
        ])->assertUnprocessable();
        $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/stock-addition-requests', [
            'product_id' => $product->id, 'variation_id' => 999999, 'quantity' => 5, 'target_stock_type' => 'transit',
        ])->assertUnprocessable();
        $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/stock-addition-requests', [
            'product_id' => $product->id, 'quantity' => 0, 'target_stock_type' => 'transit',
        ])->assertUnprocessable();
        $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/stock-addition-requests', [
            'product_id' => $product->id, 'quantity' => 5, 'target_stock_type' => 'sub',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('warehouse_stock_requests', 0);
    }

    public function test_variation_target_approval(): void
    {
        $b = $this->branch();
        $product = Product::create(['name' => 'Variation Addition Cake', 'slug' => 'variation-add-'.uniqid(), 'has_variations' => true, 'status' => 'active']);
        $variation = ProductVariation::create(['product_id' => $product->id, 'sku' => 'VADD-'.uniqid(), 'price' => 1000, 'weight_grams' => 100, 'is_active' => true]);
        ProductVariationStock::create(['agent_id' => $b['agent']->id, 'product_variation_id' => $variation->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
        WarehouseSetting::create(['agent_id' => $b['agent']->id, 'factory_plan_enabled' => false]);

        $request = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/stock-addition-requests', [
            'variation_id' => $variation->id, 'quantity' => 15, 'target_stock_type' => 'transit',
        ])->assertCreated()->json('data');

        $this->assertNull($request['product_id']);
        $this->assertSame($variation->id, $request['product_variation_id']);

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/stock-addition-requests/{$request['id']}/approve")->assertOk();

        $this->assertSame(15, $this->transit($b['agent']->id, 0, $variation->id));
        $this->assertSame(15, app(SellableStockService::class)->forVariation($b['agent']->id, $variation->id)['available']);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_scope_mine_returns_own_requests_even_when_buried_under_network_pagination(): void
    {
        $b = $this->branch();
        $product = $this->product();
        $gudangB = User::factory()->gudang()->create(['agent_id' => $b['agent']->id, 'parent_id' => $b['agent']->id]);

        $mine = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/stock-addition-requests', [
            'product_id' => $product->id, 'quantity' => 5, 'target_stock_type' => 'transit',
        ])->assertCreated()->json('data');

        for ($i = 0; $i < 12; $i++) {
            $this->actingAs($gudangB)->postJson('/api/v1/warehouse/stock-addition-requests', [
                'product_id' => $product->id, 'quantity' => 1, 'target_stock_type' => 'transit',
            ])->assertCreated();
        }

        $unfiltered = $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/stock-addition-requests?per_page=10')->assertOk();
        $this->assertSame(13, $unfiltered->json('meta.total'));
        $this->assertSame(2, $unfiltered->json('meta.last_page'));

        $scoped = $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/stock-addition-requests?scope=mine&per_page=10')->assertOk();
        $scopedIds = collect($scoped->json('data'))->pluck('id')->all();
        $this->assertContains($mine['id'], $scopedIds);
        $this->assertSame(1, $scoped->json('meta.total'));
        $this->assertSame(1, $scoped->json('meta.last_page'));
        foreach ($scoped->json('data') as $row) {
            $this->assertSame($b['gudang']->id, $row['requested_by']);
        }

        $other = $this->actingAs($gudangB)->getJson('/api/v1/warehouse/stock-addition-requests?scope=mine&per_page=10')->assertOk();
        $this->assertNotContains($mine['id'], collect($other->json('data'))->pluck('id')->all());
        $this->assertSame(12, $other->json('meta.total'));

        $queue = $this->actingAs($b['admin'])->getJson('/api/v1/warehouse/stock-addition-requests?per_page=20')->assertOk();
        $this->assertSame(13, $queue->json('meta.total'));
        $this->assertContains($mine['id'], collect($queue->json('data'))->pluck('id')->all());
    }
}
