<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariation;
use App\Models\ShippingConfiguration;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\Order\CourierService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * R-04 — order authority/projection, Kurir queue minimization, and Admin Product/Variation
 * CRU-no-Delete. The backend is the authority; every assertion is a forged direct API call.
 */
class R04OrderProjectionTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);

        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Toko', 'address' => 'Jl. X', 'latitude' => -6.2, 'longitude' => 106.8]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $agen->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agen->id]);
        $kurir = User::factory()->kurir()->create(['agent_id' => $agen->id]);
        $courier = Courier::create(['type' => 'internal', 'user_id' => $kurir->id, 'agent_id' => $agen->id, 'name' => 'Kurir A', 'is_active' => true]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true]);
        $product = Product::create(['sku' => 'R04-'.Str::uuid(), 'name' => 'R04 Cake', 'slug' => 'r04-'.uniqid(), 'has_variations' => false, 'base_price' => 10000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 20, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 30]);

        $this->b = compact('agen', 'admin', 'keuangan', 'gudang', 'kurir', 'courier', 'konsumen', 'product');
    }

    private function placeAgentOrder(int $qty = 2): Order
    {
        $id = $this->actingAs($this->b['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $this->b['product']->id, 'quantity' => $qty]],
                'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated()->json('data.id');

        return Order::withoutGlobalScopes()->findOrFail($id);
    }

    private function assertNoFinancialFields(array $order): void
    {
        foreach (['payment_status', 'payment_summary', 'subtotal_amount', 'shipping_fee_amount', 'admin_fee_amount', 'total_amount', 'dp_amount', 'paid_amount', 'remaining_amount', 'payment_method', 'payment_transaction'] as $key) {
            $this->assertArrayNotHasKey($key, $order, "financial field [{$key}] must not be exposed");
        }
        foreach ($order['items'] ?? [] as $item) {
            $this->assertArrayNotHasKey('unit_price', $item);
            $this->assertArrayNotHasKey('subtotal', $item);
        }
    }

    public function test_gudang_order_list_is_operational_only_and_detail_is_forbidden(): void
    {
        $order = $this->placeAgentOrder();

        $list = $this->actingAs($this->b['gudang'])->getJson('/api/v1/orders')->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $order->id);
        $this->assertNotNull($row);
        $this->assertArrayHasKey('order_no', $row);
        $this->assertNoFinancialFields($row);

        // Gudang has no per-record order-detail authority (OrderPolicy::view).
        $this->actingAs($this->b['gudang'])->getJson("/api/v1/orders/{$order->id}")->assertForbidden();
    }

    public function test_kurir_order_access_is_operational_only_including_shipment_status_response(): void
    {
        $order = $this->placeAgentOrder();

        $list = $this->actingAs($this->b['kurir'])->getJson('/api/v1/orders')->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $order->id);
        $this->assertNotNull($row);
        $this->assertNoFinancialFields($row);

        $detail = $this->actingAs($this->b['kurir'])->getJson("/api/v1/orders/{$order->id}")->assertOk();
        $this->assertNoFinancialFields($detail->json('data'));

        // A logistics status flip must not hand the kurir the monetary OrderResource either.
        $shipment = $order->items()->firstOrFail()->shipment;
        $flip = $this->actingAs($this->b['kurir'])->patchJson("/api/v1/shipments/{$shipment->id}/status", ['status' => 'dikirim'])->assertOk();
        $this->assertNoFinancialFields($flip->json('data'));
    }

    public function test_financial_roles_and_konsumen_still_see_the_financial_projection(): void
    {
        $order = $this->placeAgentOrder();

        foreach (['admin', 'keuangan', 'agen'] as $role) {
            $data = $this->actingAs($this->b[$role])->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data');
            $this->assertArrayHasKey('payment_summary', $data, "[{$role}] keeps the financial projection");
            $this->assertArrayHasKey('total_amount', $data);
        }

        $konsumen = $this->actingAs($this->b['konsumen'])->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data');
        $this->assertArrayHasKey('payment_summary', $konsumen);
    }

    public function test_kurir_queue_is_minimal_before_claim_and_full_after_assignment(): void
    {
        $order = $this->placeAgentOrder();

        $before = collect($this->actingAs($this->b['kurir'])->getJson('/api/v1/kurir/orders?status=diproses')->assertOk()->json('data'))
            ->firstWhere('id', $order->id);
        $this->assertNotNull($before);
        $this->assertFalse($before['detail_available']);
        $this->assertArrayNotHasKey('recipient_name', $before);
        $this->assertArrayNotHasKey('recipient_phone', $before);
        $this->assertArrayNotHasKey('address', $before);
        $this->assertArrayNotHasKey('latitude', $before);

        $shipment = $order->items()->firstOrFail()->shipment;
        app(CourierService::class)->assignCourier($shipment->fresh(), $this->b['courier'], $this->b['admin']);

        $after = collect($this->actingAs($this->b['kurir'])->getJson('/api/v1/kurir/orders?status=diproses')->assertOk()->json('data'))
            ->firstWhere('id', $order->id);
        $this->assertNotNull($after);
        $this->assertTrue($after['detail_available']);
        $this->assertArrayHasKey('recipient_name', $after);
        $this->assertArrayHasKey('address', $after);
    }

    public function test_admin_product_and_variation_delete_denied_but_cru_allowed(): void
    {
        // Admin CREATE + UPDATE allowed (variation product: no product-level sku/price).
        $created = $this->actingAs($this->b['admin'])->postJson('/api/v1/products', [
            'name' => 'CRU Product', 'has_variations' => true, 'status' => 'active',
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->b['admin'])->patchJson("/api/v1/products/{$created}", ['name' => 'CRU Product v2'])->assertOk();

        // Admin DELETE denied (product + variation).
        $this->actingAs($this->b['admin'])->deleteJson("/api/v1/products/{$created}")->assertForbidden();

        $variation = ProductVariation::create(['product_id' => $created, 'sku' => 'V-'.Str::uuid(), 'price' => 1000, 'weight_grams' => 100, 'is_active' => true]);
        $this->actingAs($this->b['admin'])->deleteJson("/api/v1/products/{$created}/variations/{$variation->id}")->assertForbidden();

        // Higher roles keep delete authority.
        $this->actingAs($this->b['agen'])->deleteJson("/api/v1/products/{$created}/variations/{$variation->id}")->assertOk();
        $this->actingAs($this->b['agen'])->deleteJson("/api/v1/products/{$created}")->assertOk();
    }
}
