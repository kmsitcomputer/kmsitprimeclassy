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
use App\Models\WarehouseSubLocation;
use App\Services\Stock\SellableStockService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseSubLocationStockTest extends TestCase
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
        $foreignGudang = User::factory()->gudang()->create(['agent_id' => $foreign->id, 'parent_id' => $foreign->id]);

        return compact('agent', 'admin', 'gudang', 'foreign', 'foreignAdmin', 'foreignGudang');
    }

    private function product(): Product
    {
        return Product::create(['sku' => 'SUB-'.uniqid(), 'name' => 'Sub Cake', 'slug' => 'sub-'.uniqid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
    }

    private function location(int $agentId, string $code = 'SUB-A'): WarehouseSubLocation
    {
        return WarehouseSubLocation::create(['agent_id' => $agentId, 'code' => $code.'-'.uniqid(), 'name' => 'Sub '.$code, 'address' => 'Jl. Test', 'contact_number' => '0812000111', 'created_by' => $agentId]);
    }

    private function subStock(int $agentId, int $locationId, int $productId, ?int $variationId = null): int
    {
        return (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('stock_type', 'sub')->where('sub_location_id', $locationId)
            ->when($variationId, fn ($q) => $q->where('product_variation_id', $variationId))
            ->when(! $variationId, fn ($q) => $q->where('product_id', $productId))->sum('quantity');
    }

    private function bucket(int $agentId, int $productId, string $type): int
    {
        return (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('stock_type', $type)->where('product_id', $productId)->sum('quantity');
    }

    public function test_gudang_creates_location_with_contact_and_zero_inventory(): void
    {
        $b = $this->branch();

        $response = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/sub-locations', [
            'code' => 'CIMAHI', 'name' => 'Sub Gudang Cimahi', 'address' => 'Jl. Contoh No. 10', 'contact_number' => '0812999888',
        ])->assertCreated();

        $this->assertSame('0812999888', $response->json('data.contact_number'));
        $this->assertSame($b['agent']->id, $response->json('data.agent_id'));
        $this->assertSame(0, WarehouseStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->count());
        $this->assertSame(0, StockMovement::query()->count());

        $this->actingAs($b['foreignGudang'])->getJson('/api/v1/warehouse/sub-locations/'.$response->json('data.id'))->assertNotFound();
    }

    public function test_pending_sub_adjustment_changes_no_inventory(): void
    {
        $b = $this->branch();
        $product = $this->product();
        $loc = $this->location($b['agent']->id);
        WarehouseSetting::create(['agent_id' => $b['agent']->id, 'factory_plan_enabled' => true]);
        ProductStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 20]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 100]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'factory_plan', 'quantity' => 50]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $loc->id, 'quantity' => 10]);
        $before = app(SellableStockService::class)->forProduct($b['agent']->id, $product->id)['available'];

        $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/sub-adjustment-requests', [
            'product_id' => $product->id, 'sub_location_id' => $loc->id, 'delta' => 20,
        ])->assertCreated()->assertJsonPath('data.status', 'pending');

        $this->assertSame(10, $this->subStock($b['agent']->id, $loc->id, $product->id));
        $this->assertSame(100, $this->bucket($b['agent']->id, $product->id, 'transit'));
        $this->assertSame(50, $this->bucket($b['agent']->id, $product->id, 'factory_plan'));
        $this->assertSame($before, app(SellableStockService::class)->forProduct($b['agent']->id, $product->id)['available']);
        $this->assertSame(0, StockMovement::query()->count());

        $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/sub-adjustment-requests', [
            'product_id' => $product->id, 'sub_location_id' => $loc->id, 'delta' => 0,
        ])->assertUnprocessable();
    }

    public function test_approval_isolation_sellable_transit_plan_shipping_reserved(): void
    {
        $b = $this->branch();
        $product = $this->product();
        $locA = $this->location($b['agent']->id, 'BANDUNG');
        $locB = $this->location($b['agent']->id, 'CIMAHI');
        WarehouseSetting::create(['agent_id' => $b['agent']->id, 'factory_plan_enabled' => true]);
        ProductStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 20]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 100]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'factory_plan', 'quantity' => 50]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'shipping', 'quantity' => 7]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $locA->id, 'quantity' => 10]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $locB->id, 'quantity' => 30]);
        $beforeSellable = app(SellableStockService::class)->forProduct($b['agent']->id, $product->id)['available'];
        $this->assertSame(130, $beforeSellable);

        $request = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/sub-adjustment-requests', [
            'product_id' => $product->id, 'sub_location_id' => $locA->id, 'delta' => 20,
        ])->assertCreated()->json('data');

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/stock-addition-requests/{$request['id']}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertSame(30, $this->subStock($b['agent']->id, $locA->id, $product->id));
        $this->assertSame(30, $this->subStock($b['agent']->id, $locB->id, $product->id));
        $this->assertSame(100, $this->bucket($b['agent']->id, $product->id, 'transit'));
        $this->assertSame(50, $this->bucket($b['agent']->id, $product->id, 'factory_plan'));
        $this->assertSame(7, $this->bucket($b['agent']->id, $product->id, 'shipping'));
        $this->assertSame(20, (int) ProductStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->value('quantity_reserved'));
        $afterSellable = app(SellableStockService::class)->forProduct($b['agent']->id, $product->id)['available'];
        $this->assertSame(130, $afterSellable);
        $this->assertSame($beforeSellable, $afterSellable);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_movements', [
            'agent_id' => $b['agent']->id, 'product_id' => $product->id, 'type' => 'sub_adjustment',
            'stock_type' => 'sub', 'sub_location_id' => $locA->id, 'quantity' => 20,
            'warehouse_stock_request_id' => $request['id'],
        ]);
    }

    public function test_negative_adjustment_and_negative_final_stock(): void
    {
        $b = $this->branch();
        $product = $this->product();
        $loc = $this->location($b['agent']->id);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $loc->id, 'quantity' => 10]);

        $request = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/sub-adjustment-requests', [
            'product_id' => $product->id, 'sub_location_id' => $loc->id, 'delta' => -5,
        ])->assertCreated()->json('data');
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/stock-addition-requests/{$request['id']}/approve")->assertOk();
        $this->assertSame(5, $this->subStock($b['agent']->id, $loc->id, $product->id));
        $this->assertSame(0, $this->bucket($b['agent']->id, $product->id, 'transit'));

        $bad = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/sub-adjustment-requests', [
            'product_id' => $product->id, 'sub_location_id' => $loc->id, 'delta' => -10,
        ])->assertCreated()->json('data');
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/stock-addition-requests/{$bad['id']}/approve")->assertUnprocessable();
        $this->assertSame(5, $this->subStock($b['agent']->id, $loc->id, $product->id));
        $this->assertSame('pending', WarehouseStockRequest::withoutGlobalScopes()->findOrFail($bad['id'])->status);
        $this->assertSame(1, StockMovement::query()->count());
    }

    public function test_authorization_and_terminal_transitions(): void
    {
        $b = $this->branch();
        $product = $this->product();
        $loc = $this->location($b['agent']->id);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $loc->id, 'quantity' => 8]);

        $request = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/sub-adjustment-requests', [
            'product_id' => $product->id, 'sub_location_id' => $loc->id, 'delta' => 4,
        ])->assertCreated()->json('data');

        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/stock-addition-requests/{$request['id']}/approve")->assertForbidden();
        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/stock-addition-requests/{$request['id']}/reject", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($b['foreignAdmin'])->postJson("/api/v1/warehouse/stock-addition-requests/{$request['id']}/approve")->assertNotFound();
        $this->actingAs($b['foreignAdmin'])->postJson("/api/v1/warehouse/stock-addition-requests/{$request['id']}/reject", ['reason' => 'x'])->assertNotFound();
        $superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($superAdmin)->postJson("/api/v1/warehouse/stock-addition-requests/{$request['id']}/approve")->assertForbidden();
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        $this->actingAs(User::factory()->admin()->create(['agent_id' => $agen->id]))->postJson("/api/v1/warehouse/stock-addition-requests/{$request['id']}/approve")->assertNotFound();

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/stock-addition-requests/{$request['id']}/approve")->assertOk();
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/stock-addition-requests/{$request['id']}/approve")->assertOk();
        $this->assertSame(12, $this->subStock($b['agent']->id, $loc->id, $product->id));
        $this->assertDatabaseCount('stock_movements', 1);

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/stock-addition-requests/{$request['id']}/reject", ['reason' => 'late'])->assertUnprocessable();

        $second = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/sub-adjustment-requests', [
            'product_id' => $product->id, 'sub_location_id' => $loc->id, 'delta' => 2,
        ])->assertCreated()->json('data');
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/stock-addition-requests/{$second['id']}/reject", ['reason' => 'stok cukup'])->assertOk();
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/stock-addition-requests/{$second['id']}/reject", ['reason' => 'lagi'])->assertOk();
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/stock-addition-requests/{$second['id']}/approve")->assertUnprocessable();
        $this->assertSame(12, $this->subStock($b['agent']->id, $loc->id, $product->id));
        $this->assertSame(1, StockMovement::query()->count());
    }

    public function test_variation_independence_and_xor_and_server_filters(): void
    {
        $b = $this->branch();
        $product = Product::create(['name' => 'Sub Var Cake', 'slug' => 'sub-var-'.uniqid(), 'has_variations' => true, 'status' => 'active']);
        $small = ProductVariation::create(['product_id' => $product->id, 'sku' => 'SUBV-S-'.uniqid(), 'price' => 1000, 'weight_grams' => 100, 'is_active' => true]);
        $large = ProductVariation::create(['product_id' => $product->id, 'sku' => 'SUBV-L-'.uniqid(), 'price' => 1000, 'weight_grams' => 100, 'is_active' => true]);
        $loc = $this->location($b['agent']->id);
        foreach ([$small, $large] as $variation) {
            ProductVariationStock::create(['agent_id' => $b['agent']->id, 'product_variation_id' => $variation->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
            WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_variation_id' => $variation->id, 'stock_type' => 'sub', 'sub_location_id' => $loc->id, 'quantity' => 6]);
        }

        $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/sub-adjustment-requests', [
            'product_id' => $product->id, 'variation_id' => $small->id, 'sub_location_id' => $loc->id, 'delta' => 3,
        ])->assertUnprocessable();
        $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/sub-adjustment-requests', [
            'sub_location_id' => $loc->id, 'delta' => 3,
        ])->assertUnprocessable();

        $request = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/sub-adjustment-requests', [
            'variation_id' => $small->id, 'sub_location_id' => $loc->id, 'delta' => 3,
        ])->assertCreated()->json('data');
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/stock-addition-requests/{$request['id']}/approve")->assertOk();

        $this->assertSame(9, $this->subStock($b['agent']->id, $loc->id, 0, $small->id));
        $this->assertSame(6, $this->subStock($b['agent']->id, $loc->id, 0, $large->id));

        $cards = $this->actingAs($b['gudang'])->getJson("/api/v1/warehouse/sub-locations/{$loc->id}/stock-cards?per_page=50")->assertOk()->json('data');
        $smallCard = collect($cards)->firstWhere('product_variation_id', $small->id);
        $this->assertSame(9, $smallCard['sub_stock']);

        $mine = $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/stock-addition-requests?scope=mine&request_type=sub_adjustment&status=pending&per_page=10')->assertOk();
        $this->assertSame(0, $mine->json('meta.total'));

        $queue = $this->actingAs($b['admin'])->getJson('/api/v1/warehouse/stock-addition-requests?request_type=sub_adjustment&sub_location_id='.$loc->id.'&per_page=10')->assertOk();
        $this->assertSame(1, $queue->json('meta.total'));
        $this->assertSame($loc->id, $queue->json('data.0.sub_location_id'));
        $pending = $this->actingAs($b['admin'])->getJson('/api/v1/warehouse/stock-addition-requests?request_type=sub_adjustment&status=pending&per_page=10')->assertOk();
        $this->assertSame(0, $pending->json('meta.total'));

        $this->actingAs($b['foreignGudang'])->getJson("/api/v1/warehouse/sub-locations/{$loc->id}/stock-cards")->assertNotFound();
    }
}
