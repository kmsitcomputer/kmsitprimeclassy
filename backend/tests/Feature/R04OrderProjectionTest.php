<?php

namespace Tests\Feature;

use App\Models\AgentPaymentGatewayConfig;
use App\Models\AgentProfile;
use App\Models\Courier;
use App\Models\Order;
use App\Models\PaymentMethod;
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

        // bank_transfer needs the branch's destination account configured before checkout accepts it.
        $bankTransfer = PaymentMethod::where('code', 'bank_transfer')->firstOrFail();
        AgentPaymentGatewayConfig::create([
            'agent_id' => $agen->id, 'payment_method_id' => $bankTransfer->id, 'environment' => 'sandbox',
            'config' => ['bank_name' => 'BCA', 'account_name' => 'Toko', 'account_number' => '123'],
        ]);

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

    public function test_gudang_order_list_is_operational_only_and_scoped_to_the_warehouse_queue(): void
    {
        // A COD order is created straight into 'diproses' (OrderService): with
        // no courier it is immediately in the warehouse queue.
        $order = $this->placeAgentOrder();
        $this->assertSame('diproses', $order->status);

        $list = $this->actingAs($this->b['gudang'])->getJson('/api/v1/orders')->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $order->id);
        $this->assertNotNull($row, 'a COD order already in diproses (no courier) must appear in the gudang order list');
        $this->assertArrayHasKey('order_no', $row);
        $this->assertNoFinancialFields($row);
        $this->actingAs($this->b['gudang'])->getJson("/api/v1/orders/{$order->id}")->assertOk();

        // The moment a courier is assigned, the order leaves the gudang surface.
        $shipment = $order->shipments()->firstOrFail();
        $this->actingAs($this->b['admin'])->patchJson("/api/v1/shipments/{$shipment->id}/courier", ['courier_id' => $this->b['courier']->id])->assertOk();
        $this->assertNull(collect($this->actingAs($this->b['gudang'])->getJson('/api/v1/orders')->assertOk()->json('data'))->firstWhere('id', $order->id), 'once a courier is assigned the order must leave the gudang list');
        $this->actingAs($this->b['gudang'])->getJson("/api/v1/orders/{$order->id}")->assertForbidden();
    }

    public function test_gudang_never_sees_a_non_diproses_order(): void
    {
        // A manual-transfer order starts in 'diterima' — nowhere visible to Gudang.
        $id = $this->actingAs($this->b['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'bank_transfer',
                'items' => [['product_id' => $this->b['productA']->id, 'quantity' => 1]],
                'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated()->json('data.id');
        $order = Order::withoutGlobalScopes()->findOrFail($id);
        $this->assertSame('diterima', $order->status);

        $this->assertNull(collect($this->actingAs($this->b['gudang'])->getJson('/api/v1/orders')->assertOk()->json('data'))->firstWhere('id', $id), 'a non-diproses order must never appear in the gudang order list');
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

        // Two couriers means two real deliveries. Under the LOCKED grouping rule (Human UAT-005)
        // two same-date items now share ONE canonical shipment, which by design cannot carry two
        // executors — so give item B its own requested delivery date first.
        $this->assertSame($itemA->shipment_id, $itemB->shipment_id, 'precondition: same-date items share one shipment');
        \App\Models\Shipment::where('order_id', $order->id)->update(['shipping_provider_code' => 'openroute']);
        app(\App\Services\Order\OrderFulfillmentService::class)->rescheduleItemDeliveryDate(
            $itemB->fresh(), now()->addDays(3)->toDateString(), $this->b['admin'], 'Separate delivery',
        );
        $itemA = $itemA->fresh();
        $itemB = $itemB->fresh();
        $this->assertNotSame($itemA->shipment_id, $itemB->shipment_id);

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

    /* ------------- A1-14: operational order detail must not leak refund money ------------- */

    public function test_koordinator_order_detail_keeps_operational_return_status_but_never_refund_amounts(): void
    {
        $order = $this->placeAgentOrder();
        $item = $order->items()->firstOrFail();

        // A return with a distinctive pending refund on the order.
        $return = \App\Models\ReturnRequest::create([
            'order_id' => $order->id, 'requested_by' => $this->b['konsumen']->id,
            'reason' => 'rusak', 'status' => 'requested', 'total_refund_amount' => 12345.00,
        ]);
        \App\Models\ReturnItem::create([
            'return_id' => $return->id, 'order_item_id' => $item->id,
            'quantity_returned' => 1, 'refund_amount' => 12345.00,
            'status' => 'pending', 'refund_status' => 'pending',
        ]);

        $koordinator = User::factory()->koordinatorKurir()->create(['agent_id' => $this->b['agen']->id]);
        $data = $this->actingAs($koordinator)->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data');

        // Operational projection: no money fields at all…
        $this->assertNoFinancialFields($data);
        // …and the nested return's amount is gated too (A1-14): the koordinator
        // still learns the return exists (operational status/quantity preserved)
        // but never learns the exact Rp amount.
        $this->assertCount(1, $data['returns']);
        $this->assertSame('requested', $data['returns'][0]['status']);
        $this->assertSame(1, $data['returns'][0]['items'][0]['quantity_returned']);
        $this->assertArrayNotHasKey('total_refund_amount', $data['returns'][0]);

        // The financial viewer (same branch admin) keeps the canonical amount.
        $admin = $this->actingAs($this->b['admin'])->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data');
        $this->assertSame('12345.00', $admin['returns'][0]['total_refund_amount']);
    }

    /* ------------- A1-15: consumer invoice capability contract ------------- */

    public function test_viewer_can_download_invoice_capability_is_derived_server_side_for_owner_and_financial_roles_only(): void
    {
        $order = $this->placeAgentOrder();

        // The order's own konsumen gets the capability (the UI's only authorized
        // ownership signal — konsumen_id is never broadcast).
        $owner = $this->actingAs($this->b['konsumen'])->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data');
        $this->assertTrue($this->b['admin'] !== null && $owner['viewer_can_download_invoice'] === true);
        $this->assertArrayNotHasKey('konsumen_id', $owner, 'ownership id is never serialized (A1-15)');

        // Same-branch financial roles keep it.
        $this->assertTrue($this->actingAs($this->b['admin'])->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data')['viewer_can_download_invoice']);
        $this->assertTrue($this->actingAs($this->b['keuangan'])->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data')['viewer_can_download_invoice']);

        // Operational roles (koordinator) may view the order but never the invoice.
        $koordinator = User::factory()->koordinatorKurir()->create(['agent_id' => $this->b['agen']->id]);
        $op = $this->actingAs($koordinator)->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data');
        $this->assertFalse($koordinator !== null && ($op['viewer_can_download_invoice'] ?? true));

        // Cross-agent: invisible (BelongsToAgentScope 404) — nothing to assert beyond denial.
        $other = User::factory()->konsumen()->create();   // no agent_id → different scope
        $this->actingAs($other)->getJson("/api/v1/orders/{$order->id}")->assertNotFound();
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
