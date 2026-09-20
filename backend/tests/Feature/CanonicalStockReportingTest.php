<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariationStock;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use App\Services\GoogleSheets\DatasetRegistry;
use App\Services\Report\ReportService;
use App\Services\Stock\SellableStockService;
use App\Services\Stock\WarehouseStockService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CanonicalStockReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_warehouse_receipt_updates_network_and_sheets_without_legacy_double_counting(): void
    {
        $branch = $this->branch();
        $product = $this->product('DIVERGENCE');
        ProductStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'quantity_on_hand' => 100, 'quantity_reserved' => 0]);

        $warehouse = app(WarehouseStockService::class);
        $warehouse->receiveFactoryStock($branch['gudang'], $product->id, null, 100, 'INITIAL-100');
        $warehouse->receiveFactoryStock($branch['gudang'], $product->id, null, 20, 'MUTATION-20');

        $this->assertSame(120, app(SellableStockService::class)->forProduct($branch['agent']->id, $product->id)['available']);
        $this->assertSame(120, $this->networkStock($branch['agent']));
        $this->assertSame(120, (int) $this->sheetRows($branch['agent'])->sole()->quantity);
        $this->assertSame(100, (int) ProductStock::withoutGlobalScopes()->where('agent_id', $branch['agent']->id)->value('quantity_on_hand'));
    }

    public function test_plan_reserved_and_excluded_buckets_have_identical_reporting_semantics(): void
    {
        $branch = $this->branch();
        $product = $this->product('BUCKETS');
        ProductStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'quantity_on_hand' => 999, 'quantity_reserved' => 20]);
        WarehouseSetting::create(['agent_id' => $branch['agent']->id, 'factory_plan_enabled' => true]);
        WarehouseStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 100]);
        WarehouseStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'factory_plan', 'quantity' => 50]);
        WarehouseStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'shipping', 'quantity' => 30]);
        $sub = WarehouseSubLocation::create(['agent_id' => $branch['agent']->id, 'code' => 'SUB-1', 'name' => 'Sub', 'created_by' => $branch['agent']->id]);
        WarehouseStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $sub->id, 'quantity' => 40]);

        $this->assertStockEverywhere($branch['agent'], $product, 130);

        WarehouseSetting::where('agent_id', $branch['agent']->id)->update(['factory_plan_enabled' => false]);
        $this->assertStockEverywhere($branch['agent'], $product, 80);
    }

    public function test_variations_and_agents_are_isolated_in_one_bulk_projection(): void
    {
        $a = $this->branch();
        $b = $this->branch();
        $product = Product::create(['name' => 'Variation Cake', 'slug' => 'variation-'.Str::uuid(), 'has_variations' => true, 'status' => 'active']);
        $quantities = [10, 20, 30];

        foreach ($quantities as $index => $quantity) {
            $variation = $product->variations()->create(['sku' => 'VAR-'.$index.'-'.Str::uuid(), 'price' => 1000, 'weight_grams' => 100, 'is_active' => true]);
            ProductVariationStock::create(['agent_id' => $a['agent']->id, 'product_variation_id' => $variation->id, 'quantity_on_hand' => 500, 'quantity_reserved' => 0]);
            WarehouseStock::create(['agent_id' => $a['agent']->id, 'product_variation_id' => $variation->id, 'stock_type' => 'transit', 'quantity' => $quantity]);
            ProductVariationStock::create(['agent_id' => $b['agent']->id, 'product_variation_id' => $variation->id, 'quantity_on_hand' => 700, 'quantity_reserved' => 0]);
            WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_variation_id' => $variation->id, 'stock_type' => 'transit', 'quantity' => 100 + $quantity]);
        }

        $rows = $this->sheetRows($a['agent']);
        $this->assertCount(3, $rows);
        $this->assertSame(60, (int) $rows->sum('quantity'));
        $this->assertSame(60, $this->networkStock($a['agent']));
        $this->assertSame(360, $this->networkStock($b['agent']));

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });
        app(SellableStockService::class)->projection([$a['agent']->id])->get();
        $this->assertSame(1, $queryCount, 'bulk projection must execute as one query, not one query per target');
    }

    public function test_unmigrated_historical_stock_remains_agent_scoped_compatibility_data(): void
    {
        $live = $this->branch();
        $historical = $this->branch();
        $product = $this->product('QUARANTINE');
        ProductStock::create(['agent_id' => $live['agent']->id, 'product_id' => $product->id, 'quantity_on_hand' => 130, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $live['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 130]);
        ProductStock::create(['agent_id' => $historical['agent']->id, 'product_id' => $product->id, 'quantity_on_hand' => 560, 'quantity_reserved' => 0]);

        $this->assertSame(130, $this->networkStock($live['agent']));
        $this->assertSame(130, (int) $this->sheetRows($live['agent'])->sum('quantity'));
        $this->assertSame(560, $this->networkStock($historical['agent']));
        $this->assertSame('legacy', app(SellableStockService::class)->projection([$historical['agent']->id])->value('source'));
    }

    public function test_network_summary_reports_stock_for_an_agent_with_zero_orders(): void
    {
        $branch = $this->branch(withOrder: false);
        $product = $this->product('NO-ORDERS');
        WarehouseStock::create(['agent_id' => $branch['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 130]);

        $summary = app(ReportService::class)->networkSummary($branch['agent'], []);

        $this->assertSame(0, data_get($summary, 'per_agen.0.order_count'));
        $this->assertSame(130, data_get($summary, 'per_agen.0.stock_quantity'));
    }

    private function assertStockEverywhere(User $agent, Product $product, int $expected): void
    {
        $this->assertSame($expected, app(SellableStockService::class)->forProduct($agent->id, $product->id)['available']);
        $this->assertSame($expected, $this->networkStock($agent));
        $this->assertSame($expected, (int) $this->sheetRows($agent)->sole()->quantity);
    }

    private function sheetRows(User $agent)
    {
        return app(DatasetRegistry::class)->query('stock', $agent->id)->get();
    }

    private function networkStock(User $agent): int
    {
        return (int) data_get(app(ReportService::class)->networkSummary($agent, []), 'per_agen.0.stock_quantity', 0);
    }

    private function branch(bool $withOrder = true): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $customer = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        if ($withOrder) {
            Order::withoutGlobalScopes()->create([
                'order_no' => 'PC-'.Str::upper(Str::random(12)), 'konsumen_id' => $customer->id, 'agent_id' => $agent->id,
                'status' => 'diproses', 'payment_status' => 'unpaid', 'subtotal_amount' => 0, 'total_amount' => 0,
                'recipient_name_snapshot' => $customer->name, 'recipient_phone_snapshot' => $customer->phone, 'address_snapshot' => 'Test',
            ]);
        }

        return compact('agent', 'gudang', 'customer');
    }

    private function product(string $sku): Product
    {
        return Product::create([
            'sku' => $sku.'-'.Str::uuid(), 'name' => $sku.' Cake', 'slug' => strtolower($sku).'-'.Str::uuid(),
            'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active',
        ]);
    }
}
