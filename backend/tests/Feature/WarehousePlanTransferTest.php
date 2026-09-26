<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariation;
use App\Models\ProductVariationStock;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Services\Stock\SellableStockService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehousePlanTransferTest extends TestCase
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
        return Product::create(['sku' => 'PTRF-'.uniqid(), 'name' => 'Plan Transfer Cake', 'slug' => 'plan-transfer-'.uniqid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
    }

    private function bucket(int $agentId, int $productId, string $type, ?int $variationId = null): int
    {
        return (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('stock_type', $type)
            ->when($variationId, fn ($q) => $q->where('product_variation_id', $variationId))
            ->when(! $variationId, fn ($q) => $q->where('product_id', $productId))->sum('quantity');
    }

    private function seedPlan(int $agentId, int $productId, int $plan = 100, int $transit = 20, int $reserved = 10): void
    {
        WarehouseSetting::firstOrCreate(['agent_id' => $agentId], ['factory_plan_enabled' => true]);
        ProductStock::create(['agent_id' => $agentId, 'product_id' => $productId, 'quantity_on_hand' => 0, 'quantity_reserved' => $reserved]);
        WarehouseStock::create(['agent_id' => $agentId, 'product_id' => $productId, 'stock_type' => 'factory_plan', 'quantity' => $plan]);
        WarehouseStock::create(['agent_id' => $agentId, 'product_id' => $productId, 'stock_type' => 'transit', 'quantity' => $transit]);
    }

    public function test_gudang_creates_pending_transfer_with_zero_inventory_effect(): void
    {
        $b = $this->branch();
        $product = $this->product();
        $this->seedPlan($b['agent']->id, $product->id);
        $before = app(SellableStockService::class)->forProduct($b['agent']->id, $product->id)['available'];

        $response = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/transfers/plan-to-transit', [
            'items' => [['product_id' => $product->id, 'quantity' => 30]],
        ])->assertCreated();

        $this->assertSame('pending', $response->json('data.status'));
        $this->assertSame('factory_plan', $response->json('data.source_stock_type'));
        $this->assertSame('transit', $response->json('data.destination_stock_type'));
        $this->assertSame(100, $this->bucket($b['agent']->id, $product->id, 'factory_plan'));
        $this->assertSame(20, $this->bucket($b['agent']->id, $product->id, 'transit'));
        $this->assertSame($before, app(SellableStockService::class)->forProduct($b['agent']->id, $product->id)['available']);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_sellable_invariant_explicit(): void
    {
        $b = $this->branch();
        $product = $this->product();
        $this->seedPlan($b['agent']->id, $product->id);

        $beforeSellable = app(SellableStockService::class)->forProduct($b['agent']->id, $product->id)['available'];
        $this->assertSame(110, $beforeSellable);

        $transfer = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/transfers/plan-to-transit', [
            'items' => [['product_id' => $product->id, 'quantity' => 30]],
        ])->assertCreated()->json('data');

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/approve")
            ->assertOk()->assertJsonPath('data.status', 'completed');

        $this->assertSame(70, $this->bucket($b['agent']->id, $product->id, 'factory_plan'));
        $this->assertSame(50, $this->bucket($b['agent']->id, $product->id, 'transit'));
        $afterSellable = app(SellableStockService::class)->forProduct($b['agent']->id, $product->id)['available'];
        $this->assertSame(110, $afterSellable);
        $this->assertSame($beforeSellable, $afterSellable);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseHas('stock_movements', ['transfer_id' => $transfer['id'], 'type' => 'transfer_out', 'stock_type' => 'factory_plan', 'counterpart_stock_type' => 'transit', 'quantity' => -30]);
        $this->assertDatabaseHas('stock_movements', ['transfer_id' => $transfer['id'], 'type' => 'transfer_in', 'stock_type' => 'transit', 'counterpart_stock_type' => 'factory_plan', 'quantity' => 30]);
    }

    public function test_double_approval_does_not_double_move(): void
    {
        $b = $this->branch();
        $product = $this->product();
        $this->seedPlan($b['agent']->id, $product->id);

        $transfer = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/transfers/plan-to-transit', [
            'items' => [['product_id' => $product->id, 'quantity' => 30]],
        ])->assertCreated()->json('data');

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/approve")->assertOk();
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/approve")->assertOk();

        $this->assertSame(70, $this->bucket($b['agent']->id, $product->id, 'factory_plan'));
        $this->assertSame(50, $this->bucket($b['agent']->id, $product->id, 'transit'));
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_rejection_and_approve_after_reject_move_nothing(): void
    {
        $b = $this->branch();
        $product = $this->product();
        $this->seedPlan($b['agent']->id, $product->id);

        $transfer = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/transfers/plan-to-transit', [
            'items' => [['product_id' => $product->id, 'quantity' => 30]],
        ])->assertCreated()->json('data');

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/reject", ['reason' => 'jadwal pabrik mundur'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertSame(100, $this->bucket($b['agent']->id, $product->id, 'factory_plan'));
        $this->assertSame(20, $this->bucket($b['agent']->id, $product->id, 'transit'));
        $this->assertSame(0, StockMovement::query()->count());

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/approve")->assertUnprocessable();
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/reject", ['reason' => 'lagi'])->assertOk();
        $this->assertSame(100, $this->bucket($b['agent']->id, $product->id, 'factory_plan'));
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_approve_then_reject_keeps_completed_and_single_movement(): void
    {
        $b = $this->branch();
        $product = $this->product();
        $this->seedPlan($b['agent']->id, $product->id);

        $transfer = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/transfers/plan-to-transit', [
            'items' => [['product_id' => $product->id, 'quantity' => 30]],
        ])->assertCreated()->json('data');

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/approve")->assertOk();
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/reject", ['reason' => 'terlambat'])->assertUnprocessable();

        $this->assertSame('completed', StockTransfer::withoutGlobalScopes()->findOrFail($transfer['id'])->status);
        $this->assertSame(70, $this->bucket($b['agent']->id, $product->id, 'factory_plan'));
        $this->assertSame(50, $this->bucket($b['agent']->id, $product->id, 'transit'));
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_double_reject_keeps_rejected_with_zero_movement(): void
    {
        $b = $this->branch();
        $product = $this->product();
        $this->seedPlan($b['agent']->id, $product->id);

        $transfer = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/transfers/plan-to-transit', [
            'items' => [['product_id' => $product->id, 'quantity' => 30]],
        ])->assertCreated()->json('data');

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/reject", ['reason' => 'pertama'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/reject", ['reason' => 'kedua'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertSame('rejected', StockTransfer::withoutGlobalScopes()->findOrFail($transfer['id'])->status);
        $this->assertSame(100, $this->bucket($b['agent']->id, $product->id, 'factory_plan'));
        $this->assertSame(20, $this->bucket($b['agent']->id, $product->id, 'transit'));
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_server_status_filter_paginates_filtered_dataset(): void
    {
        $b = $this->branch();
        $product = $this->product();
        $this->seedPlan($b['agent']->id, $product->id, plan: 1000);

        $pendingId = null;
        for ($i = 0; $i < 12; $i++) {
            $row = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/transfers/plan-to-transit', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])->assertCreated()->json('data');
            if ($i < 11) {
                $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/transfers/{$row['id']}/approve")->assertOk();
            } else {
                $pendingId = $row['id'];
            }
        }

        $filtered = $this->actingAs($b['admin'])->getJson('/api/v1/warehouse/transfers?status=pending&per_page=10')->assertOk();
        $this->assertSame(1, $filtered->json('meta.total'));
        $this->assertSame(1, $filtered->json('meta.last_page'));
        $this->assertSame([$pendingId], collect($filtered->json('data'))->pluck('id')->all());

        $completed = $this->actingAs($b['admin'])->getJson('/api/v1/warehouse/transfers?status=completed&per_page=10')->assertOk();
        $this->assertSame(11, $completed->json('meta.total'));
        $this->assertSame(2, $completed->json('meta.last_page'));
        foreach ($completed->json('data') as $row) {
            $this->assertSame('completed', $row['status']);
        }

        $foreign = $this->actingAs($b['foreignAdmin'])->getJson('/api/v1/warehouse/transfers?status=pending&per_page=10')->assertOk();
        $this->assertSame(0, $foreign->json('meta.total'));

        $this->actingAs($b['admin'])->getJson('/api/v1/warehouse/transfers?status=bogus')->assertUnprocessable();
    }

    public function test_authorization_boundaries(): void
    {
        $b = $this->branch();
        $product = $this->product();
        $this->seedPlan($b['agent']->id, $product->id);

        $transfer = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/transfers/plan-to-transit', [
            'items' => [['product_id' => $product->id, 'quantity' => 10]],
        ])->assertCreated()->json('data');

        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/approve")->assertForbidden();
        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/reject", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($b['foreignAdmin'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/approve")->assertNotFound();
        $this->actingAs($b['foreignAdmin'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/reject", ['reason' => 'x'])->assertNotFound();
        $this->actingAs($b['foreignGudang'])->getJson("/api/v1/warehouse/transfers/{$transfer['id']}")->assertNotFound();
        $superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($superAdmin)->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/approve")->assertForbidden();

        $this->assertSame('pending', StockTransfer::withoutGlobalScopes()->findOrFail($transfer['id'])->status);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_insufficient_plan_fails_safely_without_negative_stock(): void
    {
        $b = $this->branch();
        $product = $this->product();
        $this->seedPlan($b['agent']->id, $product->id, plan: 30);

        $transfer = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/transfers/plan-to-transit', [
            'items' => [['product_id' => $product->id, 'quantity' => 25]],
        ])->assertCreated()->json('data');

        WarehouseStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)
            ->where('product_id', $product->id)->where('stock_type', 'factory_plan')->update(['quantity' => 10]);

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/approve")->assertUnprocessable();

        $this->assertSame(10, $this->bucket($b['agent']->id, $product->id, 'factory_plan'));
        $this->assertSame(20, $this->bucket($b['agent']->id, $product->id, 'transit'));
        $this->assertSame('pending', StockTransfer::withoutGlobalScopes()->findOrFail($transfer['id'])->status);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_multi_item_approval_is_atomic(): void
    {
        $b = $this->branch();
        $productA = $this->product();
        $productB = $this->product();
        $this->seedPlan($b['agent']->id, $productA->id, plan: 50);
        $this->seedPlan($b['agent']->id, $productB->id, plan: 5);

        $transfer = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/transfers/plan-to-transit', [
            'items' => [
                ['product_id' => $productA->id, 'quantity' => 10],
                ['product_id' => $productB->id, 'quantity' => 10],
            ],
        ])->assertCreated()->json('data');

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/approve")->assertUnprocessable();

        $this->assertSame(50, $this->bucket($b['agent']->id, $productA->id, 'factory_plan'));
        $this->assertSame(20, $this->bucket($b['agent']->id, $productA->id, 'transit'));
        $this->assertSame(5, $this->bucket($b['agent']->id, $productB->id, 'factory_plan'));
        $this->assertSame(20, $this->bucket($b['agent']->id, $productB->id, 'transit'));
        $this->assertSame('pending', StockTransfer::withoutGlobalScopes()->findOrFail($transfer['id'])->status);
        $this->assertSame(0, StockMovement::query()->count());

        WarehouseStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)
            ->where('product_id', $productB->id)->where('stock_type', 'factory_plan')->update(['quantity' => 40]);

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/approve")->assertOk();
        $this->assertSame(40, $this->bucket($b['agent']->id, $productA->id, 'factory_plan'));
        $this->assertSame(30, $this->bucket($b['agent']->id, $productA->id, 'transit'));
        $this->assertSame(30, $this->bucket($b['agent']->id, $productB->id, 'factory_plan'));
        $this->assertSame(30, $this->bucket($b['agent']->id, $productB->id, 'transit'));
        $this->assertDatabaseCount('stock_movements', 4);
    }

    public function test_variations_are_independent_and_no_other_bucket_moves(): void
    {
        $b = $this->branch();
        $product = Product::create(['name' => 'Plan Transfer Variations', 'slug' => 'plan-transfer-var-'.uniqid(), 'has_variations' => true, 'status' => 'active']);
        $small = ProductVariation::create(['product_id' => $product->id, 'sku' => 'PTV-S-'.uniqid(), 'price' => 1000, 'weight_grams' => 100, 'is_active' => true]);
        $large = ProductVariation::create(['product_id' => $product->id, 'sku' => 'PTV-L-'.uniqid(), 'price' => 1000, 'weight_grams' => 100, 'is_active' => true]);
        WarehouseSetting::create(['agent_id' => $b['agent']->id, 'factory_plan_enabled' => true]);
        foreach ([$small, $large] as $variation) {
            ProductVariationStock::create(['agent_id' => $b['agent']->id, 'product_variation_id' => $variation->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
            WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_variation_id' => $variation->id, 'stock_type' => 'factory_plan', 'quantity' => 40]);
            WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_variation_id' => $variation->id, 'stock_type' => 'transit', 'quantity' => 5]);
        }

        $transfer = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/transfers/plan-to-transit', [
            'items' => [['product_variation_id' => $small->id, 'quantity' => 15]],
        ])->assertCreated()->json('data');

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/transfers/{$transfer['id']}/approve")->assertOk();

        $this->assertSame(25, $this->bucket($b['agent']->id, 0, 'factory_plan', $small->id));
        $this->assertSame(20, $this->bucket($b['agent']->id, 0, 'transit', $small->id));
        $this->assertSame(40, $this->bucket($b['agent']->id, 0, 'factory_plan', $large->id));
        $this->assertSame(5, $this->bucket($b['agent']->id, 0, 'transit', $large->id));
        $this->assertSame(0, WarehouseStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->whereIn('stock_type', ['shipping', 'sub'])->count());
        $this->assertDatabaseMissing('stock_movements', ['transfer_id' => $transfer['id'], 'stock_type' => 'factory_plan', 'counterpart_stock_type' => 'shipping']);
    }

    public function test_no_arbitrary_source_destination_through_plan_transfer_api(): void
    {
        $b = $this->branch();
        $product = $this->product();
        $this->seedPlan($b['agent']->id, $product->id);

        $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/transfers/plan-to-transit', [
            'source_stock_type' => 'transit', 'destination_stock_type' => 'shipping',
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ])->assertCreated();

        $transfer = StockTransfer::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame('factory_plan', $transfer->source_stock_type);
        $this->assertSame('transit', $transfer->destination_stock_type);

        $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/transfers/plan-to-transit', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 5],
                ['product_id' => $product->id, 'quantity' => 5],
            ],
        ])->assertUnprocessable();

        $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/transfers/plan-to-transit', [
            'items' => [['product_id' => $product->id, 'product_variation_id' => 999999, 'quantity' => 5]],
        ])->assertUnprocessable();
    }

    public function test_plan_inactive_blocks_new_transfer_but_phase1_addition_survives(): void
    {
        $b = $this->branch();
        $product = $this->product();
        WarehouseSetting::create(['agent_id' => $b['agent']->id, 'factory_plan_enabled' => false]);

        $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/transfers/plan-to-transit', [
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('stock_transfers', 0);

        $addition = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/stock-addition-requests', [
            'product_id' => $product->id, 'quantity' => 10, 'target_stock_type' => 'transit',
        ])->assertCreated()->json('data');
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/stock-addition-requests/{$addition['id']}/approve")->assertOk();
        $this->assertSame(10, $this->bucket($b['agent']->id, $product->id, 'transit'));
    }
}
