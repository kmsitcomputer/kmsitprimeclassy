<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\StockRequest;
use App\Models\User;
use App\Models\WarehouseStock;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DS-PBR follow-up targeted coverage (NOT a repeat of the 605-test suite).
 *
 * DS-PBR-002: warehouse list + stock-request item images resolve from the
 * eager-loaded product.images collection — no per-row images() query —
 * with primary-first / sort_order-fallback / imageless-null semantics.
 * DS-PBR-003: derived product/variation fields render correctly through the
 * relationLoaded() form (no MissingValue leak), and the name/variant/SKU
 * contract plus agent scoping hold.
 * DS-PBR-001 (search source): the catalog search backing the warehouse
 * product picker matches by product SKU in addition to name.
 */
class DsPbrFollowUpTest extends TestCase
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
        AgentProfile::create(['user_id' => $agent->id, 'store_name' => 'DS-PBR Branch', 'address' => 'Test', 'latitude' => -6.2, 'longitude' => 106.8]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $otherAgent = User::factory()->agen()->create();
        $otherAgent->update(['agent_id' => $otherAgent->id]);

        return compact('agent', 'gudang', 'otherAgent');
    }

    public function test_warehouse_stock_list_uses_eager_loaded_images_with_no_per_row_image_query(): void
    {
        $b = $this->branch();
        $product = Product::create(['sku' => 'DSPBR-'.uniqid(), 'name' => 'DS-PBR Cake', 'slug' => 'dspbr-'.uniqid(), 'has_variations' => false, 'status' => 'active']);
        ProductImage::create(['product_id' => $product->id, 'path' => 'products/secondary.jpg', 'is_primary' => false, 'sort_order' => 5]);
        ProductImage::create(['product_id' => $product->id, 'path' => 'products/primary.jpg', 'is_primary' => true, 'sort_order' => 9]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 3]);

        $response = $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse-stock?stock_type=transit');
        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('product_id', $product->id);
        $this->assertNotNull($row);
        $this->assertSame('DS-PBR Cake', $row['product_name']);
        $this->assertSame($product->sku, $row['sku']);
        $this->assertStringContainsString('/storage/products/primary.jpg', (string) $row['product_image_url']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse-stock?stock_type=transit')->assertOk();
        $imageQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'product_images'))->all();
        DB::disableQueryLog();
        // One batched eager load for all rows — never one images query per row.
        $this->assertCount(1, $imageQueries);
    }

    public function test_warehouse_stock_list_falls_back_to_lowest_sort_order_and_null_when_imageless(): void
    {
        $b = $this->branch();
        $fallback = Product::create(['sku' => 'DSPBR-FB-'.uniqid(), 'name' => 'DS-PBR Fallback', 'slug' => 'dspbr-fb-'.uniqid(), 'has_variations' => false, 'status' => 'active']);
        ProductImage::create(['product_id' => $fallback->id, 'path' => 'products/high.jpg', 'is_primary' => false, 'sort_order' => 9]);
        ProductImage::create(['product_id' => $fallback->id, 'path' => 'products/low.jpg', 'is_primary' => false, 'sort_order' => 1]);
        $bare = Product::create(['sku' => 'DSPBR-BARE-'.uniqid(), 'name' => 'DS-PBR Bare', 'slug' => 'dspbr-bare-'.uniqid(), 'has_variations' => false, 'status' => 'active']);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $fallback->id, 'stock_type' => 'transit', 'quantity' => 1]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $bare->id, 'stock_type' => 'transit', 'quantity' => 2]);

        $response = $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse-stock?stock_type=transit');
        $response->assertOk();
        $rows = collect($response->json('data'));
        $this->assertStringContainsString('/storage/products/low.jpg', (string) $rows->firstWhere('product_id', $fallback->id)['product_image_url']);
        $this->assertNull($rows->firstWhere('product_id', $bare->id)['product_image_url']);
    }

    public function test_legacy_stock_request_detail_is_retired_and_order_list_is_branch_scoped(): void
    {
        $b = $this->branch();
        $product = Product::create(['sku' => 'DSPBR-SR-'.uniqid(), 'name' => 'DS-PBR Request Cake', 'slug' => 'dspbr-sr-'.uniqid(), 'has_variations' => false, 'status' => 'active']);
        ProductImage::create(['product_id' => $product->id, 'path' => 'products/sr.jpg', 'is_primary' => true, 'sort_order' => 0]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 10]);
        // Another agent's identically-shaped row must never leak into this branch.
        $foreign = Product::create(['sku' => 'DSPBR-FX-'.uniqid(), 'name' => 'DS-PBR Foreign', 'slug' => 'dspbr-fx-'.uniqid(), 'has_variations' => false, 'status' => 'active']);
        WarehouseStock::create(['agent_id' => $b['otherAgent']->id, 'product_id' => $foreign->id, 'stock_type' => 'transit', 'quantity' => 99]);

        $konsumen = User::factory()->konsumen()->create(['agent_id' => $b['agent']->id]);
        $order = Order::create(['order_no' => 'DSPBR-'.uniqid(), 'konsumen_id' => $konsumen->id, 'agent_id' => $b['agent']->id, 'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'), 'status' => 'diproses', 'payment_status' => 'unpaid', 'subtotal_amount' => 1000, 'total_amount' => 1000, 'recipient_name_snapshot' => 'Test', 'recipient_phone_snapshot' => '0811', 'address_snapshot' => 'Test']);
        $orderItem = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku, 'unit_price_snapshot' => 1000, 'subtotal_snapshot' => 2000, 'original_quantity' => 2, 'fulfilled_quantity' => 2, 'status' => 'diproses']);
        $stockRequest = StockRequest::withoutGlobalScopes()->create(['agent_id' => $b['agent']->id, 'order_id' => $order->id, 'request_number' => 'DSR-'.Str::uuid(), 'status' => 'pending']);
        $stockRequest->items()->create(['order_item_id' => $orderItem->id, 'product_id' => $product->id, 'sku_snapshot' => $product->sku, 'requested_qty' => 2, 'fulfilled_qty' => 0, 'remaining_qty' => 2]);

        $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/stock-requests/'.$stockRequest->id)->assertNotFound();
        $orders = $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/orders/diproses')->assertOk();
        $this->assertSame($order->id, $orders->json('data.0.id'));
        $this->assertArrayNotHasKey('total_amount', $orders->json('data.0'));

        $list = $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse-stock?stock_type=transit')->assertOk()->json('data');
        $this->assertNull(collect($list)->firstWhere('product_id', $foreign->id));
    }

    public function test_catalog_search_backing_the_picker_matches_by_sku_as_well_as_name(): void
    {
        Product::create(['sku' => 'DSPBR-SKU-'.uniqid(), 'name' => 'DS-PBR Picker Cake', 'slug' => 'dspbr-pick-'.uniqid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);

        $byName = $this->getJson('/api/v1/products?search=Picker Cake');
        $byName->assertOk();
        $this->assertContains('DS-PBR Picker Cake', collect($byName->json('data'))->pluck('name')->all());

        $sku = Product::where('name', 'DS-PBR Picker Cake')->value('sku');
        $bySku = $this->getJson('/api/v1/products?search='.$sku);
        $bySku->assertOk();
        $this->assertContains('DS-PBR Picker Cake', collect($bySku->json('data'))->pluck('name')->all());
    }
}
