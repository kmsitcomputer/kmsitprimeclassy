<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Commission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductFee;
use App\Models\ProductImage;
use App\Models\ProductStock;
use App\Models\ProductVariation;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use App\Services\Order\CourierService;
use App\Services\Order\OrderService;
use App\Services\Referral\ReferralService;
use App\Services\User\UserManagementService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * PBR-001 / PBR-002 / PBR-003 targeted regression coverage.
 *
 * PBR-001: Sales-Kurir referral visibility — new accounts get SK-* in the
 * API response, converted Sales keep SA-*, SK-* registration attributes,
 * and self-profile exposes the code.
 * PBR-002: Sales-Kurir dashboard sees BOTH sales and courier fee totals
 * as separate keys, backed by two distinct ledger rows.
 * PBR-003: warehouse API payloads expose human-readable product identity
 * (name/SKU/variant label), never bare IDs alone.
 */
class PbrRemediationTest extends TestCase
{
    use HasTestRegion;
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
        AgentProfile::create(['user_id' => $agent->id, 'store_name' => 'PBR Branch', 'address' => 'Test', 'latitude' => -6.2, 'longitude' => 106.8]);
        $korsal = User::factory()->korsal()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $admin = User::factory()->admin()->create(['agent_id' => $agent->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);

        return compact('agent', 'korsal', 'admin', 'gudang');
    }

    private function createSalesKurir(User $creator, User $korsal): User
    {
        return app(UserManagementService::class)->create($creator, 'sales-kurir-sub', [
            'name' => 'PBR Sales Kurir',
            'email' => 'pbr-sk-'.Str::uuid().'@example.test',
            'phone' => '0812000000',
            'password' => 'password123',
            'korsal_id' => $korsal->id,
        ]);
    }

    /* ---------------- PBR-001 ---------------- */

    public function test_new_sales_kurir_receives_s_k_prefix_referral_code_in_api_response(): void
    {
        $b = $this->branch();

        $response = $this->actingAs($b['agent'])->postJson('/api/v1/users', [
            'name' => 'New SK', 'email' => 'new-sk-'.uniqid().'@example.test', 'phone' => '0812000001',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'role' => 'sales-kurir-sub', 'korsal_id' => $b['korsal']->id,
        ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('data.referral_code'));
        $this->assertStringStartsWith('SS-', $response->json('data.referral_code'));
    }

    public function test_converted_sales_preserves_historical_s_a_referral_code(): void
    {
        $b = $this->branch();
        $sales = User::factory()->sales()->create([
            'agent_id' => $b['agent']->id, 'korsal_id' => $b['korsal']->id,
            'parent_id' => $b['korsal']->id, 'referral_code' => 'SA-KEEP01',
        ]);

        $this->actingAs($b['agent'])->patchJson("/api/v1/users/{$sales->id}/convert-to-sales-kurir-sub")->assertOk();

        $this->assertSame('SA-KEEP01', $sales->fresh()->referral_code);
        $this->assertSame('sales-kurir-sub', $sales->fresh()->role->slug);
    }

    public function test_konsumen_registers_with_s_k_referral_code_and_attribution_links_to_sales_kurir(): void
    {
        $b = $this->branch();
        $salesKurir = $this->createSalesKurir($b['korsal'], $b['korsal']);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'SK Buyer', 'email' => 'sk-buyer-'.Str::uuid().'@example.test',
            'phone' => '0812111111', 'password' => 'password123', 'password_confirmation' => 'password123',
            'referral_code' => $salesKurir->referral_code,
        ]);

        $response->assertCreated();
        $buyer = User::where('email', $response->json('data.user.email'))->firstOrFail();
        $this->assertSame($salesKurir->id, $buyer->sales_id);
        $this->assertSame($salesKurir->id, $buyer->parent_id);
    }

    public function test_sales_kurir_self_profile_exposes_referral_code(): void
    {
        $b = $this->branch();
        $salesKurir = $this->createSalesKurir($b['korsal'], $b['korsal']);

        $response = $this->actingAs($salesKurir)->getJson('/api/v1/auth/me');

        $response->assertOk();
        $this->assertNotNull($response->json('data.user.referral_code'));
        $this->assertStringStartsWith('SS-', $response->json('data.user.referral_code'));
    }

    /* ---------------- PBR-002 ---------------- */

    public function test_sales_kurir_dashboard_sees_both_sales_and_courier_fee_totals_separately(): void
    {
        $f = $this->dualFeeFixture();
        $salesKurir = $f['sales_kurir'];
        $item = $f['item'];

        $summary = $this->actingAs($salesKurir)->getJson('/api/v1/commissions/summary');
        $summary->assertOk();
        $this->assertSame(500.0, (float) $summary->json('data.total_sales_fee'));
        $this->assertSame(250.0, (float) $summary->json('data.total_courier_fee'));
        $this->assertArrayNotHasKey('total_agent_fee', $summary->json('data'));

        $list = $this->actingAs($salesKurir)->getJson('/api/v1/commissions');
        $list->assertOk();
        $roles = collect($list->json('data'))->pluck('beneficiary_role')->sort()->values()->all();
        $this->assertSame(['courier', 'sales'], $roles);
        $this->assertSame($item->id, $list->json('data.0.order_item_id'));
    }

    public function test_same_sales_kurir_receives_two_distinct_ledger_rows_for_one_order(): void
    {
        $f = $this->dualFeeFixture();
        $salesKurir = $f['sales_kurir'];
        $item = $f['item'];

        $salesFee = Commission::query()->where('order_item_id', $item->id)->where('beneficiary_user_id', $salesKurir->id)->where('beneficiary_role', 'sales')->get();
        $courierFee = Commission::query()->where('order_item_id', $item->id)->where('beneficiary_user_id', $salesKurir->id)->where('beneficiary_role', 'courier')->get();

        $this->assertCount(1, $salesFee);
        $this->assertCount(1, $courierFee);
        $this->assertNotSame($salesFee->first()->id, $courierFee->first()->id);
        $this->assertSame(500.0, (float) $salesFee->first()->amount);
        $this->assertSame(250.0, (float) $courierFee->first()->amount);
        $this->assertSame(1000.0, (float) Commission::query()->where('order_item_id', $item->id)->where('beneficiary_role', 'agent')->value('amount'));
    }

    public function test_plain_sales_still_sees_only_sales_fee_and_plain_kurir_only_courier_fee(): void
    {
        $f = $this->dualFeeFixture();

        $sales = User::factory()->sales()->create(['agent_id' => $f['agent']->id, 'korsal_id' => $f['korsal']->id, 'referral_code' => 'SA-'.uniqid()]);
        $salesSummary = $this->actingAs($sales)->getJson('/api/v1/commissions/summary');
        $salesSummary->assertOk();
        $this->assertArrayNotHasKey('total_courier_fee', $salesSummary->json('data'));
        $this->assertArrayNotHasKey('total_agent_fee', $salesSummary->json('data'));

        $kurirUser = User::factory()->kurir()->create(['agent_id' => $f['agent']->id]);
        $kurirSummary = $this->actingAs($kurirUser)->getJson('/api/v1/commissions/summary');
        $kurirSummary->assertOk();
        $this->assertArrayNotHasKey('total_sales_fee', $kurirSummary->json('data'));
        $this->assertArrayNotHasKey('total_agent_fee', $kurirSummary->json('data'));
    }

    /* ---------------- PBR-003 ---------------- */

    public function test_warehouse_stock_list_returns_human_readable_product_identity(): void
    {
        $b = $this->branch();
        $product = Product::create(['sku' => 'PBR-'.uniqid(), 'name' => 'PBR Chocolate Cake', 'slug' => 'pbr-cake-'.uniqid(), 'has_variations' => false, 'status' => 'active']);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 20]);

        $response = $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse-stock?stock_type=transit');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('product_id', $product->id);
        $this->assertNotNull($row);
        $this->assertSame('PBR Chocolate Cake', $row['product_name']);
        $this->assertSame($product->sku, $row['sku']);
        $this->assertSame('PBR Chocolate Cake', $row['product']['name']);
    }

    public function test_warehouse_stock_searches_by_product_name_and_sku(): void
    {
        $b = $this->branch();
        $wanted = Product::create(['sku' => 'PBR-SEARCH-'.uniqid(), 'name' => 'PBR Searchable Tart', 'slug' => 'pbr-tart-'.uniqid(), 'has_variations' => false, 'status' => 'active']);
        $other = Product::create(['sku' => 'PBR-OTHER-'.uniqid(), 'name' => 'Unrelated Pie', 'slug' => 'pbr-pie-'.uniqid(), 'has_variations' => false, 'status' => 'active']);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $wanted->id, 'stock_type' => 'transit', 'quantity' => 5]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $other->id, 'stock_type' => 'transit', 'quantity' => 7]);

        $byName = $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse-stock?search=Searchable');
        $byName->assertOk();
        $this->assertCount(1, $byName->json('data'));
        $this->assertSame('PBR Searchable Tart', $byName->json('data.0.product_name'));

        $bySku = $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse-stock?search='.$wanted->sku);
        $bySku->assertOk();
        $this->assertCount(1, $bySku->json('data'));
    }

    public function test_warehouse_transfers_list_includes_product_names_in_items(): void
    {
        $b = $this->branch();
        $product = Product::create(['sku' => 'PBR-TRF-'.uniqid(), 'name' => 'PBR Transfer Cake', 'slug' => 'pbr-trf-'.uniqid(), 'has_variations' => false, 'status' => 'active']);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 50]);

        $sub = \App\Models\WarehouseSubLocation::create(['agent_id' => $b['agent']->id, 'code' => 'PBR-SUB', 'name' => 'Sub', 'created_by' => $b['agent']->id]);
        $transfer = $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/transfers', [
            'source_stock_type' => 'transit', 'destination_stock_type' => 'sub', 'destination_sub_location_id' => $sub->id,
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ])->assertCreated()->json('data');

        $list = $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/transfers');
        $list->assertOk();
        $found = collect($list->json('data'))->firstWhere('id', $transfer['id']);
        $this->assertNotNull($found);
        $this->assertSame('PBR Transfer Cake', $found['items'][0]['product']['name']);
        $this->assertSame($product->sku, $found['items'][0]['product']['sku']);
    }

    public function test_warehouse_opnames_include_product_relations_in_items(): void
    {
        $b = $this->branch();
        $product = Product::create(['sku' => 'PBR-OPN-'.uniqid(), 'name' => 'PBR Opname Cake', 'slug' => 'pbr-opn-'.uniqid(), 'has_variations' => false, 'status' => 'active']);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 30]);

        $this->actingAs($b['gudang'])->postJson('/api/v1/warehouse/opnames', [
            'opname_type' => 'physical_opname', 'stock_type' => 'transit',
            'items' => [['product_id' => $product->id]],
        ])->assertCreated();

        $list = $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/opnames');
        $list->assertOk();
        $item = $list->json('data.0.items.0');
        $this->assertSame('PBR Opname Cake', $item['product']['name']);
        $this->assertSame($product->sku, $item['product']['sku']);
    }

    public function test_stock_request_list_is_retired_and_gudang_sees_operational_orders(): void
    {
        $b = $this->branch();
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $b['agent']->id]);
        $product = Product::create(['sku' => 'PBR-REQ-'.uniqid(), 'name' => 'PBR Request Cake', 'slug' => 'pbr-req-'.uniqid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
        ProductImage::create(['product_id' => $product->id, 'path' => 'products/pbr-req.jpg', 'is_primary' => true, 'sort_order' => 0]);
        ProductStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 10]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 100]);
        $order = Order::create(['order_no' => 'PBR-REQ-'.uniqid(), 'konsumen_id' => $konsumen->id, 'agent_id' => $b['agent']->id, 'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'), 'status' => 'diterima', 'payment_status' => 'unpaid', 'subtotal_amount' => 10000, 'total_amount' => 10000, 'recipient_name_snapshot' => 'Test', 'recipient_phone_snapshot' => '0811', 'address_snapshot' => 'Test']);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku, 'unit_price_snapshot' => 1000, 'subtotal_snapshot' => 10000, 'original_quantity' => 10, 'fulfilled_quantity' => 10, 'status' => 'diterima']);

        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();

        $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/stock-requests')->assertNotFound();
        $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/orders/diproses')->assertOk()->assertJsonPath('data.0.id', $order->id);
    }

    public function test_stock_request_list_is_retired_for_variation_orders_too(): void
    {
        $b = $this->branch();
        $superAdmin = User::factory()->superAdmin()->create();
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $b['agent']->id]);
        $product = Product::create(['name' => 'PBR Variant Cake', 'slug' => 'pbr-var-'.uniqid(), 'has_variations' => true, 'status' => 'active']);

        $variationId = $this->actingAs($superAdmin)->postJson("/api/v1/products/{$product->id}/variations", [
            'sku' => 'PBR-VAR-'.uniqid(), 'price' => 1000, 'weight_grams' => 100,
            'attributes' => ['Ukuran' => '500gr', 'Rasa' => 'Coklat'],
        ])->assertCreated()->json('data.id');
        $variation = ProductVariation::query()->findOrFail($variationId);

        $order = Order::create(['order_no' => 'PBR-VAR-'.uniqid(), 'konsumen_id' => $konsumen->id, 'agent_id' => $b['agent']->id, 'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'), 'status' => 'diterima', 'payment_status' => 'unpaid', 'subtotal_amount' => 1000, 'total_amount' => 1000, 'recipient_name_snapshot' => 'Test', 'recipient_phone_snapshot' => '0811', 'address_snapshot' => 'Test']);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_variation_id' => $variation->id, 'product_name_snapshot' => $product->name, 'variation_label_snapshot' => $variation->label(), 'sku_snapshot' => $variation->sku, 'unit_price_snapshot' => 1000, 'subtotal_snapshot' => 1000, 'original_quantity' => 1, 'fulfilled_quantity' => 1, 'status' => 'diterima']);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_variation_id' => $variation->id, 'stock_type' => 'transit', 'quantity' => 10]);

        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();

        $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/stock-requests')->assertNotFound();
        $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/orders/diproses')->assertOk()->assertJsonPath('data.0.id', $order->id);
    }

    /* ---------------- fixtures ---------------- */

    private function dualFeeFixture(): array
    {
        $b = $this->branch();
        $salesKurir = $this->createSalesKurir($b['korsal'], $b['korsal']);

        // Fixture uses zero HTTP-auth (service-layer only): checkout and
        // referral attribution run through the same services the endpoints
        // call, so each test performs exactly ONE actingAs() switch. A
        // stateful POST before a user switch corrupts Sanctum's session
        // binding in this suite (TEST HARNESS ARTIFACT — production auth
        // verified unaffected).
        $chain = app(ReferralService::class)->resolveChainByCode($salesKurir->referral_code);
        $buyer = User::factory()->konsumen()->create([
            'email' => 'pbr-buyer-'.Str::uuid().'@example.test',
            'agent_id' => $chain['agent_id'], 'korsal_id' => $chain['korsal_id'],
            'sales_id' => $chain['sales_id'], 'parent_id' => $chain['parent_id'],
        ]);

        $product = Product::create([
            'sku' => 'PBR-DUAL-'.Str::uuid(), 'name' => 'PBR Dual Cake', 'slug' => 'pbr-dual-'.Str::uuid(),
            'has_variations' => false, 'base_price' => 10000, 'weight_grams' => 100, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);
        // R-03 / decision A: a Sub-sourced order is self-delivered by the Sales-Kurir-Sub (first
        // class path, no Courier assignment) — the dual-fee behavior stays, now via self-delivery.
        $location = WarehouseSubLocation::create(['agent_id' => $b['agent']->id, 'code' => 'PBR1', 'name' => 'PBR1', 'created_by' => $b['agent']->id]);
        $location->forceFill(['owner_user_id' => $salesKurir->id])->save();
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $location->id, 'quantity' => 10]);
        ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'agent', 'amount' => 1000, 'is_active' => true]);
        ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'sales', 'amount' => 500, 'is_active' => true]);
        ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'courier', 'amount' => 250, 'is_active' => true]);

        $order = app(OrderService::class)->createOrder(
            $buyer,
            [['product_id' => $product->id, 'product_variation_id' => null, 'quantity' => 1]],
            ['address_id' => null, 'recipient_name' => 'PBR Dual Buyer', 'recipient_phone' => '0812111111', 'address_line' => 'Test Address', 'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810],
            $salesKurir,
            'cod',
            null,
            (string) Str::uuid(),
            null,
            null,
            null,
            'sub',
        );
        $item = $order->items()->firstOrFail();
        $shipment = $order->shipments()->firstOrFail();

        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'dikirim', $salesKurir);
        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'terkirim', $salesKurir, UploadedFile::fake()->image('pbr-proof.jpg'));

        return [...$b, 'sales_kurir' => $salesKurir->fresh(), 'buyer' => $buyer, 'product' => $product, 'order' => $order->fresh(), 'item' => $item->fresh()];
    }
}
