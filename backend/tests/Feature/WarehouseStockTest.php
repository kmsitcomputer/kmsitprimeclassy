<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariation;
use App\Models\ProductVariationStock;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Services\Stock\WarehouseStockService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WarehouseStockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_warehouse_bucket_rows_are_agent_scoped_and_readable_without_exposing_another_branch(): void
    {
        $agentA = User::factory()->agen()->create();
        $agentA->update(['agent_id' => $agentA->id]);
        $agentB = User::factory()->agen()->create();
        $agentB->update(['agent_id' => $agentB->id]);
        $product = Product::create(['sku' => 'BUCKET-'.uniqid(), 'name' => 'Bucket Cake', 'slug' => 'bucket-cake-'.uniqid(), 'has_variations' => false, 'status' => 'active']);

        WarehouseStock::create(['agent_id' => $agentA->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 12]);
        WarehouseStock::create(['agent_id' => $agentB->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 40]);

        $response = $this->actingAs($agentA)->getJson('/api/v1/warehouse-stock');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(12, $response->json('data.0.quantity'));
    }

    public function test_reconcile_command_reports_bucket_rows_and_fails_for_negative_quantity(): void
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $product = Product::create(['sku' => 'RECONCILE-'.uniqid(), 'name' => 'Reconcile Cake', 'slug' => 'reconcile-cake-'.uniqid(), 'has_variations' => false, 'status' => 'active']);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 3]);

        $this->artisan('warehouse:reconcile')
            ->expectsOutput('warehouse_rows=1 negative_rows=0')
            ->assertSuccessful();
    }

    public function test_only_gudang_can_receive_transit_and_receipt_creates_movement(): void
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $admin = User::factory()->admin()->create(['agent_id' => $agent->id]);
        $product = Product::create(['sku' => 'RECV-'.uniqid(), 'name' => 'Receive Cake', 'slug' => 'receive-'.uniqid(), 'has_variations' => false, 'status' => 'active']);
        $payload = ['product_id' => $product->id, 'quantity' => 10, 'reference' => 'DO-123'];

        $this->actingAs($agent)->postJson('/api/v1/warehouse/transit/receive', $payload)->assertForbidden();
        $this->actingAs($admin)->postJson('/api/v1/warehouse/transit/receive', $payload)->assertForbidden();
        $this->actingAs($gudang)->postJson('/api/v1/warehouse/transit/receive', $payload)->assertCreated();
        $this->assertDatabaseHas('warehouse_stocks', ['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 10]);
        $this->assertDatabaseHas('stock_movements', ['agent_id' => $agent->id, 'product_id' => $product->id, 'type' => 'factory_in', 'stock_type' => 'transit', 'quantity' => 10, 'note' => 'DO-123']);
        $this->actingAs($gudang)->postJson('/api/v1/warehouse/transit/receive', $payload)->assertCreated();
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_admin_toggle_and_gudang_plan_mutation_are_commitment_safe(): void
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $admin = User::factory()->admin()->create(['agent_id' => $agent->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $product = Product::create(['sku' => 'PLAN-'.uniqid(), 'name' => 'Plan Cake', 'slug' => 'plan-'.uniqid(), 'has_variations' => false, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 20]);

        $this->actingAs($admin)->patchJson('/api/v1/warehouse/settings/factory-plan', ['factory_plan_enabled' => true])->assertOk();
        $this->actingAs($gudang)->postJson('/api/v1/warehouse/factory-plan', ['product_id' => $product->id, 'delta' => 30, 'reference' => 'PLAN-1'])->assertOk();
        $this->actingAs($gudang)->getJson('/api/v1/warehouse/sellable?product_id='.$product->id)->assertOk()->assertJsonPath('data.available', 10);
        $this->actingAs($admin)->patchJson('/api/v1/warehouse/settings/factory-plan', ['factory_plan_enabled' => false])->assertUnprocessable();
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $product->id, 'stock_type' => 'factory_plan', 'quantity' => 30]);
    }

    public function test_variation_sellable_calculation_excludes_plan_when_disabled_and_never_counts_plan_as_physical(): void
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $product = Product::create(['name' => 'Variation Plan Cake', 'slug' => 'variation-plan-'.uniqid(), 'has_variations' => true, 'status' => 'active']);
        $variation = ProductVariation::create(['product_id' => $product->id, 'sku' => 'VP-'.uniqid(), 'price' => 1000, 'weight_grams' => 100, 'is_active' => true]);
        ProductVariationStock::create(['agent_id' => $agent->id, 'product_variation_id' => $variation->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 20]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_variation_id' => $variation->id, 'stock_type' => 'transit', 'quantity' => 100]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_variation_id' => $variation->id, 'stock_type' => 'factory_plan', 'quantity' => 50]);
        WarehouseSetting::create(['agent_id' => $agent->id, 'factory_plan_enabled' => true]);

        $service = app(WarehouseStockService::class);
        $active = $service->sellableForVariation($agent->id, $variation->id);
        $this->assertSame(130, $active['available']);
        $this->assertSame(100, $active['physical_stock']);
        $this->assertFalse($active['has_commitment_deficit']);

        WarehouseSetting::query()->where('agent_id', $agent->id)->update(['factory_plan_enabled' => false]);
        $inactive = $service->sellableForVariation($agent->id, $variation->id);
        $this->assertSame(80, $inactive['available']);
        $this->assertSame(50, $inactive['factory_plan']);
        $this->assertSame(100, $inactive['physical_stock']);
    }

    public function test_sellable_formula_excludes_sub_and_shipping_and_includes_enabled_plan(): void
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $product = Product::create(['sku' => 'CUTOVER-'.uniqid(), 'name' => 'Cutover Cake', 'slug' => 'cutover-'.uniqid(), 'has_variations' => false, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 999, 'quantity_reserved' => 20]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 100]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'shipping', 'quantity' => 30]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'factory_plan', 'quantity' => 50]);
        WarehouseSetting::create(['agent_id' => $agent->id, 'factory_plan_enabled' => true]);
        $result = app(WarehouseStockService::class)->sellableForProduct($agent->id, $product->id);
        $this->assertSame(130, $result['available']);
        $this->assertSame(100, $result['physical_stock']);
        $this->assertSame('warehouse', $result['source']);
    }

    public function test_authenticated_product_resource_uses_sellable_stock_and_guest_receives_no_numeric_stock(): void
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $product = Product::create(['sku' => 'API-CUTOVER-'.uniqid(), 'name' => 'API Cutover Cake', 'slug' => 'api-cutover-'.uniqid(), 'has_variations' => false, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 999, 'quantity_reserved' => 2]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 10]);
        $this->actingAs($agent)->getJson('/api/v1/products/'.$product->slug)->assertOk()->assertJsonPath('data.stock.available', 8)->assertJsonPath('data.stock.login_to_view', false);
        $this->app['auth']->forgetGuards();
        $guest = $this->getJson('/api/v1/products/'.$product->slug)->assertOk();
        $this->assertArrayNotHasKey('stock', $guest->json('data'));
        $this->assertArrayNotHasKey('agent_available_quantity', $guest->json('data'));
    }

    public function test_legacy_stock_adjustment_is_denied_in_warehouse_authoritative_mode(): void
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $product = Product::create(['sku' => 'LOCKDOWN-'.uniqid(), 'name' => 'Lockdown Cake', 'slug' => 'lockdown-'.uniqid(), 'has_variations' => false, 'status' => 'active']);
        Config::set('warehouse.authoritative', true);
        $this->actingAs($agent)->postJson('/api/v1/stock/adjust', ['product_id' => $product->id, 'delta' => 1, 'reason' => 'legacy'])->assertForbidden();
        $this->assertDatabaseMissing('product_stocks', ['agent_id' => $agent->id, 'product_id' => $product->id]);
        Config::set('warehouse.authoritative', false);
    }

    public function test_variation_resources_expose_each_variations_sellable_quantity_independently(): void
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $product = Product::create(['name' => 'Variation API Cake', 'slug' => 'variation-api-'.uniqid(), 'has_variations' => true, 'status' => 'active']);
        $first = ProductVariation::create(['product_id' => $product->id, 'sku' => 'VAR-A-'.uniqid(), 'price' => 1000, 'weight_grams' => 100, 'is_active' => true]);
        $second = ProductVariation::create(['product_id' => $product->id, 'sku' => 'VAR-B-'.uniqid(), 'price' => 1000, 'weight_grams' => 100, 'is_active' => true]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_variation_id' => $first->id, 'stock_type' => 'transit', 'quantity' => 5]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_variation_id' => $second->id, 'stock_type' => 'transit', 'quantity' => 20]);
        $response = $this->actingAs($agent)->getJson('/api/v1/products/'.$product->slug)->assertOk();
        $variations = collect($response->json('data.variations'))->keyBy('id');
        $this->assertSame(5, $variations[$first->id]['stock']['available']);
        $this->assertSame(20, $variations[$second->id]['stock']['available']);
    }
}
