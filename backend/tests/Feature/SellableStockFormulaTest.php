<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariationStock;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use App\Services\Stock\SellableStockService;
use App\Services\Stock\StockService;
use App\Services\Stock\WarehouseStockService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * L-006 — sellable = transit + (factory_plan when enabled) − reserved, with
 * shipping and sub excluded. Exactly one calculator (SellableStockService)
 * may own that formula; every other production call path must delegate to it.
 *
 * These tests deliberately measure the *checkout* path (StockService's
 * reservation guard) and the catalog path (WarehouseStockService) against the
 * canonical service so a second, drifting copy of the formula fails loudly.
 */
class SellableStockFormulaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_all_sellable_call_paths_agree_when_factory_plan_is_enabled(): void
    {
        [$agent, $product] = $this->branchAndProduct();
        $this->warehouseBuckets($agent, $product, transit: 100, shipping: 30, sub: 200, factoryPlan: 50);
        WarehouseSetting::create(['agent_id' => $agent->id, 'factory_plan_enabled' => true]);
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 999, 'quantity_reserved' => 20]);

        // transit 100 + plan 50 − reserved 20 = 130; shipping 30 and sub 200 excluded.
        $this->assertSame(130, app(SellableStockService::class)->forProduct($agent->id, $product->id)['available'], 'canonical SellableStockService');
        $this->assertSame(130, app(WarehouseStockService::class)->sellableForProduct($agent->id, $product->id)['available'], 'WarehouseStockService must delegate to the canonical service');
        $this->assertSame('ok', $this->reserveOutcome($agent, $product, 130), 'checkout must accept exactly the canonical sellable quantity');
        $this->assertSame('insufficient', $this->reserveOutcome($agent, $product, 131), 'checkout must never accept more than the canonical sellable quantity');
    }

    public function test_all_sellable_call_paths_agree_when_factory_plan_is_disabled(): void
    {
        [$agent, $product] = $this->branchAndProduct();
        $this->warehouseBuckets($agent, $product, transit: 100, shipping: 30, sub: 200, factoryPlan: 50);
        WarehouseSetting::create(['agent_id' => $agent->id, 'factory_plan_enabled' => false]);
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 999, 'quantity_reserved' => 20]);

        // transit 100 − reserved 20 = 80; factory_plan 50 ignored while disabled.
        $this->assertSame(80, app(SellableStockService::class)->forProduct($agent->id, $product->id)['available'], 'canonical SellableStockService');
        $this->assertSame(80, app(WarehouseStockService::class)->sellableForProduct($agent->id, $product->id)['available'], 'WarehouseStockService must delegate to the canonical service');
        $this->assertSame('ok', $this->reserveOutcome($agent, $product, 80), 'checkout must accept exactly the canonical sellable quantity');
        $this->assertSame('insufficient', $this->reserveOutcome($agent, $product, 81), 'checkout must never accept more than the canonical sellable quantity');
    }

    public function test_catalog_path_exposes_the_canonical_sellable_quantity(): void
    {
        [$agent, $product] = $this->branchAndProduct();
        $this->warehouseBuckets($agent, $product, transit: 100, shipping: 30, sub: 200, factoryPlan: 50);
        WarehouseSetting::create(['agent_id' => $agent->id, 'factory_plan_enabled' => true]);
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 999, 'quantity_reserved' => 20]);

        $this->actingAs($agent)->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.stock.available', 130)
            ->assertJsonPath('data.agent_available_quantity', 130);
    }

    public function test_migrated_and_historical_agents_keep_separate_stock_sources_without_double_counting(): void
    {
        [$liveAgent, $product] = $this->branchAndProduct();
        $historicalAgent = User::factory()->agen()->create();
        $historicalAgent->update(['agent_id' => $historicalAgent->id]);

        ProductStock::create(['agent_id' => $liveAgent->id, 'product_id' => $product->id, 'quantity_on_hand' => 130, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $liveAgent->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 130]);
        ProductStock::create(['agent_id' => $historicalAgent->id, 'product_id' => $product->id, 'quantity_on_hand' => 560, 'quantity_reserved' => 0]);

        $service = app(SellableStockService::class);
        $live = $service->forProduct($liveAgent->id, $product->id);
        $historical = $service->forProduct($historicalAgent->id, $product->id);

        $this->assertSame('warehouse', $live['source']);
        $this->assertSame(130, $live['available']);
        $this->assertSame('legacy', $historical['source']);
        $this->assertSame(560, $historical['available']);
    }

    /**
     * Shipping/sub are excluded buckets: holding stock there must never make a
     * product sellable, even when a stale legacy on-hand row still exists.
     */
    public function test_sellable_is_zero_when_only_excluded_buckets_hold_stock(): void
    {
        [$agent, $product] = $this->branchAndProduct();
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'shipping', 'quantity' => 30]);
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 999, 'quantity_reserved' => 0]);

        $this->assertSame(0, app(SellableStockService::class)->forProduct($agent->id, $product->id)['available'], 'canonical SellableStockService');
        $this->assertSame('insufficient', $this->reserveOutcome($agent, $product, 1), 'checkout must not sell stock that only exists in excluded buckets');
    }

    public function test_variation_buckets_follow_the_same_canonical_formula(): void
    {
        [$agent, $product] = $this->branchAndProduct(withVariation: true);
        $variation = $product->variations()->firstOrFail();
        WarehouseStock::create(['agent_id' => $agent->id, 'product_variation_id' => $variation->id, 'stock_type' => 'transit', 'quantity' => 12]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_variation_id' => $variation->id, 'stock_type' => 'factory_plan', 'quantity' => 4]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_variation_id' => $variation->id, 'stock_type' => 'shipping', 'quantity' => 9]);
        WarehouseSetting::create(['agent_id' => $agent->id, 'factory_plan_enabled' => true]);
        ProductVariationStock::create(['agent_id' => $agent->id, 'product_variation_id' => $variation->id, 'quantity_on_hand' => 500, 'quantity_reserved' => 2]);

        // transit 12 + plan 4 − reserved 2 = 14.
        $this->assertSame(14, app(SellableStockService::class)->forVariation($agent->id, $variation->id)['available'], 'canonical SellableStockService');
        $this->assertSame(14, app(WarehouseStockService::class)->sellableForVariation($agent->id, $variation->id)['available'], 'WarehouseStockService must delegate to the canonical service');
        $this->assertSame('ok', $this->variationReserveOutcome($agent, $product, 14), 'checkout must accept exactly the canonical sellable quantity');
        $this->assertSame('insufficient', $this->variationReserveOutcome($agent, $product, 15), 'checkout must never accept more than the canonical sellable quantity');
    }

    /** @return array{0: User, 1: Product} */
    private function branchAndProduct(bool $withVariation = false): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);

        $product = Product::create([
            'name' => 'Sellable Formula Cake',
            'slug' => 'sellable-'.Str::uuid(),
            'has_variations' => $withVariation,
            'sku' => $withVariation ? null : 'SF-'.Str::uuid(),
            'status' => 'active',
        ]);

        if ($withVariation) {
            $product->variations()->create([
                'sku' => 'SFV-'.Str::uuid(), 'price' => 1000, 'weight_grams' => 100, 'is_active' => true,
            ]);
        }

        return [$agent, $product->fresh()];
    }

    private function warehouseBuckets(User $agent, Product $product, int $transit, int $shipping, int $sub, int $factoryPlan): void
    {
        // `sub` is a physically located bucket: MySQL enforces
        // chk_warehouse_sub_location_type (sub ⇒ sub_location_id NOT NULL).
        $subLocationId = WarehouseSubLocation::create([
            'agent_id' => $agent->id, 'code' => 'SUB-'.Str::uuid(), 'name' => 'Rak Sub',
            'is_active' => true, 'created_by' => $agent->id,
        ])->id;

        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => $transit]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'shipping', 'quantity' => $shipping]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $subLocationId, 'quantity' => $sub]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'factory_plan', 'quantity' => $factoryPlan]);
    }

    /** Runs the real checkout guard and reports whether it accepted $qty. */
    private function reserveOutcome(User $agent, Product $product, int $qty): string
    {
        try {
            DB::transaction(function () use ($agent, $product, $qty) {
                app(StockService::class)->reserveForProduct($agent->id, $product, $qty, 'sellable-formula-test', 1, $agent->id);
            });

            return 'ok';
        } catch (InsufficientStockException) {
            return 'insufficient';
        }
    }

    private function variationReserveOutcome(User $agent, Product $product, int $qty): string
    {
        try {
            DB::transaction(function () use ($agent, $product, $qty) {
                app(StockService::class)->reserveForVariation($agent->id, $product->variations()->firstOrFail(), $qty, 'sellable-formula-test', 1, $agent->id);
            });

            return 'ok';
        } catch (InsufficientStockException) {
            return 'insufficient';
        }
    }
}
