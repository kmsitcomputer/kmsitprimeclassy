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
 * CRU-no-Delete (plus the MAJOR-12 Kurir generic-order boundary). Every assertion is a forged
 * direct API call; the backend is the authority.
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
        $kurirB = User::factory()->kurir()->create(['agent_id' => $agen->id]);
        $courierB = Courier::create(['type' => 'internal', 'user_id' => $kurirB->id, 'agent_id' => $agen->id, 'name' => 'Kurir B', 'is_active' => true]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true]);

        $productA = Product::create(['sku' => 'R04A-'.Str::uuid(), 'name' => 'R04 A', 'slug' => 'r04a-'.uniqid(), 'has_variations' => false, 'base_price' => 10000, 'weight_grams' => 500, 'status' => 'active']);
        $productB = Product::create(['sku' => 'R04B-'.Str::uuid(), 'name' => 'R04 B', 'slug' => 'r04b-'.uniqid(), 'has_variations' => false, 'base_price' => 20000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $productA->id, 'quantity_on_hand' => 20, 'quantity_reserved' => 0]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $productB->id, 'quantity_on_hand' => 20, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $productA->id, 'stock_type' => 'transit', 'quantity' => 30]);

        $this->b = compact('agen', 'admin', 'keuangan', 'gudang', 'kurir', 'courier', 'kurirB', 'courierB', 'konsumen', 'productA', 'productB');
    }

    private function placeAgentOrder(int $qty = 2): Order
    {
        return $this->placeTwoItemOrder();
    }

    private function placeTwoItemOrder(): Order
    {
        $id = $this->actingAs($this->b['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [
                    ['product_id' => $this->b['productA']->id, 'quantity' => 1],
                    ['product_id' => $this->b['productB']->id, 'quantity' => 1],
                ],
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

        $this->actingAs($this->b['gudang'])->getJson("/api/v1/orders/{$order->id}")->assertForbidden();
    }

    public function test_sales_kurir_sub_keeps_the_financial_projection_of_its_own_orders(): void
    {
        // Package A regression guard: a Sales-Kurir-Sub is Sales + Kurir, not an operational-only
        // role — it buys for itself and for its referral consumers and must see what is owed.
        $sub = User::factory()->salesKurirSub()->create(['agent_id' => $this->b['agen']->id, 'parent_id' => $this->b['agen']->id]);
        $id = $this->actingAs($sub)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $this->b['productA']->id, 'quantity' => 1]],
                'recipient_name' => 'Sub Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sub',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated()->json('data.id');

        $row = collect($this->actingAs($sub)->getJson('/api/v1/orders')->assertOk()->json('data'))->firstWhere('id', $id);
        foreach (['payment_status', 'payment_summary', 'total_amount', 'remaining_amount', 'payment_method'] as $key) {
            $this->assertArrayHasKey($key, $row, "[{$key}] must stay visible to the Sales-Kurir-Sub");
        }
        $this->assertArrayHasKey('unit_price', $row['items'][0]);
    }

    /* ---------------- MAJOR-12: normal Kurir generic-order boundary ---------------- */

    public function test_normal_kurir_is_denied_the_generic_order_list_and_detail(): void
    {
        $order = $this->placeAgentOrder();

        // Generic list/detail must not be available to a normal Kurir at all — /kurir/orders is the
        // only authorized (minimized) discovery surface.
        $this->actingAs($this->b['kurir'])->getJson('/api/v1/orders')->assertForbidden();
        $this->actingAs($this->b['kurir'])->getJson("/api/v1/orders/{$order->id}")->assertForbidden();
    }

    public function test_kurir_shipment_status_response_uses_the_courier_projection_not_generic_order(): void
    {
        $order = $this->placeAgentOrder();
        $shipment = $order->items()->firstOrFail()->shipment;

        $flip = $this->actingAs($this->b['kurir'])->patchJson("/api/v1/shipments/{$shipment->id}/status", ['status' => 'dikirim'])->assertOk();
        $data = $flip->json('data');

        $this->assertArrayHasKey('detail_available', $data, 'courier projection, not the generic OrderResource');
        $this->assertArrayNotHasKey('sales', $data);
        $this->assertArrayNotHasKey('korsal', $data);
        $this->assertNoFinancialFields($data);
    }

    public function test_kurir_queue_is_minimal_before_claim_and_full_after_assignment(): void
    {
        $order = $this->placeAgentOrder();

        $before = collect($this->actingAs($this->b['kurir'])->getJson('/api/v1/kurir/orders?status=diproses')->assertOk()->json('data'))
            ->firstWhere('id', $order->id);
        $this->assertNotNull($before);
        $this->assertFalse($before['detail_available']);
        foreach (['recipient_name', 'recipient_phone', 'address', 'latitude'] as $key) {
            $this->assertArrayNotHasKey($key, $before);
        }

        $shipment = $order->items()->firstOrFail()->shipment;
        app(CourierService::class)->assignCourier($shipment->fresh(), $this->b['courier'], $this->b['admin']);

        $after = collect($this->actingAs($this->b['kurir'])->getJson('/api/v1/kurir/orders?status=diproses')->assertOk()->json('data'))
            ->firstWhere('id', $order->id);
        $this->assertNotNull($after);
        $this->assertTrue($after['detail_available']);
        $this->assertArrayHasKey('recipient_name', $after);
        $this->assertArrayHasKey('address', $after);

        // Another Kurir never obtains the assigned detail.
        $sibling = collect($this->actingAs($this->b['kurirB'])->getJson('/api/v1/kurir/orders?status=diproses')->assertOk()->json('data'))
            ->firstWhere('id', $order->id);
        $this->assertTrue($sibling === null || $sibling['detail_available'] === false);
    }

    public function test_mixed_shipment_order_does_not_leak_sibling_couriers_item_detail(): void
    {
        $order = $this->placeTwoItemOrder();
        $items = $order->items()->get();
        [$itemA, $itemB] = [$items[0], $items[1]];

        // Same date => one shared shipment; B gets its own delivery date so each Kurir can hold a separate shipment.
        $this->actingAs($this->b['admin'])->patchJson("/api/v1/orders/{$order->id}/items/{$itemB->id}/reschedule", ['requested_delivery_date' => now()->addDays(4)->toDateString(), 'reason' => 'Tanggal berbeda'])->assertOk();
        $itemA->refresh();
        $itemB->refresh();

        app(CourierService::class)->assignCourier($itemA->shipment()->first(), $this->b['courier'], $this->b['admin']);
        app(CourierService::class)->assignCourier($itemB->shipment()->first(), $this->b['courierB'], $this->b['admin']);

        // Each Kurir picks up their own shipment.
        $this->actingAs($this->b['kurir'])->patchJson("/api/v1/shipments/{$itemA->shipment_id}/status", ['status' => 'dikirim'])->assertOk();
        $this->actingAs($this->b['kurirB'])->patchJson("/api/v1/shipments/{$itemB->shipment_id}/status", ['status' => 'dikirim'])->assertOk();

        $viewA = collect($this->actingAs($this->b['kurir'])->getJson('/api/v1/kurir/orders?status=dikirim')->assertOk()->json('data'))
            ->firstWhere('id', $order->id);
        $this->assertNotNull($viewA);
        $itemIdsA = collect($viewA['items'])->pluck('id')->all();
        $this->assertContains($itemA->id, $itemIdsA);
        $this->assertNotContains($itemB->id, $itemIdsA, "Kurir A must not see Kurir B's sibling item");

        $viewB = collect($this->actingAs($this->b['kurirB'])->getJson('/api/v1/kurir/orders?status=dikirim')->assertOk()->json('data'))
            ->firstWhere('id', $order->id);
        $this->assertNotNull($viewB);
        $itemIdsB = collect($viewB['items'])->pluck('id')->all();
        $this->assertContains($itemB->id, $itemIdsB);
        $this->assertNotContains($itemA->id, $itemIdsB);
    }

    public function test_another_agent_kurir_cannot_access_this_branch_order(): void
    {
        $order = $this->placeAgentOrder();

        $agen2 = User::factory()->agen()->create();
        $agen2->update(['agent_id' => $agen2->id]);
        $kurir2 = User::factory()->kurir()->create(['agent_id' => $agen2->id]);
        Courier::create(['type' => 'internal', 'user_id' => $kurir2->id, 'agent_id' => $agen2->id, 'name' => 'Foreign', 'is_active' => true]);

        $ids = collect($this->actingAs($kurir2)->getJson('/api/v1/kurir/orders')->assertOk()->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($order->id));

        // Cross-branch access is refused (403 policy, or 404 agent-scoped binding) — never data.
        $response = $this->actingAs($kurir2)->getJson("/api/v1/orders/{$order->id}");
        $this->assertContains($response->status(), [403, 404]);
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
