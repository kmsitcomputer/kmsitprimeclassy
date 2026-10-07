<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockRequest;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IMP-001 UAT remediation (Gap 2) — Gudang "Order Diproses" work queue.
 *
 * The user-facing "Stock Request" feature is removed. Gudang now works
 * orders through the warehouse queue:
 *
 *   order.status === 'diproses'  AND  no courier assigned
 *       → visible in Gudang's queue ("Order Diproses")
 *       → Gudang opens the order
 *       → Gudang submits a fulfillment proposal (canonical stock-request
 *         proposal endpoint)
 *       → Admin reviews / approves / rejects
 *       → existing canonical fulfillment/inventory process continues.
 *
 * CRITICAL VISIBILITY INVARIANT (server-side, never client-filtered):
 * Gudang may see an order ONLY when BOTH hold. If status leaves 'diproses'
 * OR a courier is assigned, the order disappears from the queue AND direct
 * API/detail access is denied (OrderPolicy::view), AND proposing for it is
 * refused (StockRequestProposalService).
 */
class GudangOrderQueueTest extends TestCase
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
        $admin = User::factory()->admin()->create(['agent_id' => $agent->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        $courierUser = User::factory()->kurir()->create(['agent_id' => $agent->id]);
        $courier = Courier::create(['type' => 'internal', 'user_id' => $courierUser->id, 'agent_id' => $agent->id, 'name' => 'Kurir A', 'is_active' => true]);

        return compact('agent', 'admin', 'gudang', 'konsumen', 'courier');
    }

    private function product(array $b, string $tag = 'P'): Product
    {
        return Product::create([
            'sku' => 'GQ-'.$tag.'-'.uniqid(), 'name' => 'Kue '.$tag, 'slug' => 'gq-'.$tag.'-'.uniqid(),
            'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active',
        ]);
    }

    /** Creates an order in 'diterima' and moves it to 'diproses' via the canonical endpoint. */
    private function diprosesOrder(array $b, Product $product, string $tag = 'O', int $qty = 10): Order
    {
        $order = Order::create([
            'order_no' => 'GQ-'.strtoupper($tag.'-'.uniqid()), 'konsumen_id' => $b['konsumen']->id,
            'agent_id' => $b['agent']->id, 'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'),
            'status' => 'diterima', 'payment_status' => 'unpaid',
            'subtotal_amount' => $qty * 1000, 'total_amount' => $qty * 1000,
            'recipient_name_snapshot' => 'Test', 'recipient_phone_snapshot' => '0811', 'address_snapshot' => 'Test',
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_name_snapshot' => $product->name,
            'sku_snapshot' => $product->sku, 'unit_price_snapshot' => 1000, 'subtotal_snapshot' => $qty * 1000,
            'original_quantity' => $qty, 'fulfilled_quantity' => 0, 'status' => 'diterima',
        ]);
        ProductStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => $qty]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 20]);
        WarehouseSetting::create(['agent_id' => $b['agent']->id, 'factory_plan_enabled' => false]);

        // The canonical order flow gives every item its own Shipment (OrderService::createOrder).
        $shipment = \App\Models\Shipment::create([
            'order_id' => $order->id,
            'shipping_provider_code' => 'free',
            'origin_latitude' => -6.2, 'origin_longitude' => 106.8166,
            'destination_latitude' => -6.2, 'destination_longitude' => 106.8,
            'distance_km' => 1, 'shipping_fee_snapshot' => 0,
            'status' => 'pending', 'delivery_mode' => 'standard',
        ]);
        $item->update(['shipment_id' => $shipment->id]);

        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();

        return $order->fresh();
    }

    private function assignCourier(array $b, Order $order): void
    {
        $shipment = $order->shipments()->firstOrFail();
        $this->actingAs($b['admin'])->patchJson("/api/v1/shipments/{$shipment->id}/courier", ['courier_id' => $b['courier']->id])->assertOk();
    }

    // ---------- visibility invariant ----------

    public function test_gudang_sees_only_diproses_and_no_courier_orders_in_the_queue(): void
    {
        $b = $this->branch();
        $product = $this->product($b, 'A');
        $eligible = $this->diprosesOrder($b, $product, 'A');

        // An order that stays 'diterima' must NOT appear.
        $pending = Order::create([
            'order_no' => 'GQ-DITERIMA-'.uniqid(), 'konsumen_id' => $b['konsumen']->id,
            'agent_id' => $b['agent']->id, 'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'),
            'status' => 'diterima', 'payment_status' => 'unpaid', 'subtotal_amount' => 1000, 'total_amount' => 1000,
            'recipient_name_snapshot' => 'T', 'recipient_phone_snapshot' => '0', 'address_snapshot' => 'T',
        ]);

        $response = $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/orders/diproses')->assertOk()->json('data');
        $ids = collect($response)->pluck('id')->all();
        $this->assertContains($eligible->id, $ids);
        $this->assertNotContains($pending->id, $ids, 'a non-diproses order must never appear in Gudang queue');
    }

    public function test_gudang_queue_drops_order_once_a_courier_is_assigned(): void
    {
        $b = $this->branch();
        $product = $this->product($b, 'B');
        $order = $this->diprosesOrder($b, $product, 'B');

        $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/orders/diproses')->assertOk()
            ->assertJsonFragment(['id' => $order->id]);

        $this->assignCourier($b, $order);

        $response = $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/orders/diproses')->assertOk()->json('data');
        $this->assertNotContains($order->id, collect($response)->pluck('id')->all(), 'once a courier is assigned the order must disappear from the queue');
    }

    public function test_gudang_queue_drops_order_once_status_leaves_diproses(): void
    {
        $b = $this->branch();
        $product = $this->product($b, 'C');
        $order = $this->diprosesOrder($b, $product, 'C');

        $this->assignCourier($b, $order);
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'dikirim'])->assertOk();

        $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/orders/diproses')->assertOk()
            ->assertJsonMissing(['id' => $order->id]);
    }

    public function test_gudang_cannot_bypass_the_scope_rule_via_direct_apis(): void
    {
        $b = $this->branch();
        $product = $this->product($b, 'D');

        // A diproses + no-courier order IS viewable by gudang (queue scope).
        $eligible = $this->diprosesOrder($b, $product, 'D');
        $this->actingAs($b['gudang'])->getJson("/api/v1/orders/{$eligible->id}")->assertOk();

        // Once a courier is assigned, the SAME detail endpoint must refuse the gudang.
        $this->assignCourier($b, $eligible);
        $this->actingAs($b['gudang'])->getJson("/api/v1/orders/{$eligible->id}")->assertForbidden();

        // A non-diproses order is refused even with no courier.
        $other = Order::create([
            'order_no' => 'GQ-NOP-'.uniqid(), 'konsumen_id' => $b['konsumen']->id,
            'agent_id' => $b['agent']->id, 'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'),
            'status' => 'terkirim', 'payment_status' => 'paid', 'subtotal_amount' => 1000, 'total_amount' => 1000,
            'recipient_name_snapshot' => 'T', 'recipient_phone_snapshot' => '0', 'address_snapshot' => 'T',
        ]);
        $this->actingAs($b['gudang'])->getJson("/api/v1/orders/{$other->id}")->assertForbidden();
    }

    public function test_gudang_cannot_propose_for_an_order_that_left_the_queue(): void
    {
        $b = $this->branch();
        $product = $this->product($b, 'E');
        $order = $this->diprosesOrder($b, $product, 'E');
        $request = StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->firstOrFail();
        $item = $request->items()->firstOrFail();

        // Eligible right now — the internal stock-request endpoint is readable and a proposal works.
        $stockRequest = $this->actingAs($b['gudang'])->getJson("/api/v1/warehouse/orders/{$order->id}/stock-request")->assertOk()->json('data');
        $this->assertSame($request->id, $stockRequest['id']);
        $this->assertCount(1, $stockRequest['items']);

        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/stock-requests/{$request->id}/proposals", ['items' => [['item_id' => $item->id, 'quantity' => 2]]])->assertCreated();

        // Once a courier is assigned, the internal stock-request endpoint and proposing are both refused.
        $this->assignCourier($b, $order);
        $this->actingAs($b['gudang'])->getJson("/api/v1/warehouse/orders/{$order->id}/stock-request")->assertNotFound();
        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/stock-requests/{$request->id}/proposals", ['items' => [['item_id' => $item->id, 'quantity' => 2]]])->assertUnprocessable();
    }

    public function test_internal_stock_request_endpoint_is_scoped_to_gudang_and_same_branch_only(): void
    {
        $b = $this->branch();
        $other = $this->branch();
        $order = $this->diprosesOrder($b, $this->product($b, 'K'), 'K');
        $foreignOrder = $this->diprosesOrder($other, $this->product($other, 'L'), 'L');

        // A non-gudang role cannot read it.
        $this->actingAs($b['admin'])->getJson("/api/v1/warehouse/orders/{$order->id}/stock-request")->assertStatus(403);

        // A gudang from another branch cannot read it (same-Agent scope -> 404).
        $this->actingAs($other['gudang'])->getJson("/api/v1/warehouse/orders/{$order->id}/stock-request")->assertNotFound();

        // A gudang cannot read another branch's eligible order.
        $this->actingAs($b['gudang'])->getJson("/api/v1/warehouse/orders/{$foreignOrder->id}/stock-request")->assertNotFound();
    }

    // ---------- the positive workflow ----------

    public function test_gudang_submits_proposal_and_admin_approves_through_the_canonical_flow(): void
    {
        $b = $this->branch();
        $product = $this->product($b, 'F');
        $order = $this->diprosesOrder($b, $product, 'F');
        $request = StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->firstOrFail();
        $item = $request->items()->firstOrFail();

        $proposal = $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/stock-requests/{$request->id}/proposals", ['items' => [['item_id' => $item->id, 'quantity' => 6]]])
            ->assertCreated()->json('data');

        // Gudang cannot approve/reject its own proposal.
        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/approve")->assertForbidden();
        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/reject", ['reason' => 'x'])->assertForbidden();

        // Admin approves — canonical inventory move (transit -> shipping, reserved released).
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertSame(14, (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->where('product_id', $product->id)->where('stock_type', 'transit')->value('quantity'));
        $this->assertSame(6, (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->where('product_id', $product->id)->where('stock_type', 'shipping')->value('quantity'));
        $this->assertSame(4, (int) ProductStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->where('product_id', $product->id)->value('quantity_reserved'));
        $this->assertSame('partial', $request->fresh()->status);
    }

    public function test_admin_can_reject_a_gudang_proposal(): void
    {
        $b = $this->branch();
        $product = $this->product($b, 'G');
        $order = $this->diprosesOrder($b, $product, 'G');
        $request = StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->firstOrFail();
        $item = $request->items()->firstOrFail();

        $proposal = $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/stock-requests/{$request->id}/proposals", ['items' => [['item_id' => $item->id, 'quantity' => 3]]])
            ->assertCreated()->json('data');

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/reject", ['reason' => 'stok belum siap'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertSame(20, (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->where('product_id', $product->id)->where('stock_type', 'transit')->value('quantity'));
        $this->assertSame('pending', $request->fresh()->status);
    }

    // ---------- cross-agent isolation ----------

    public function test_gudang_never_sees_another_branches_orders(): void
    {
        $b = $this->branch();
        $other = $this->branch();
        $this->diprosesOrder($b, $this->product($b, 'H'), 'H');
        $this->diprosesOrder($other, $this->product($other, 'I'), 'I');

        $ids = collect($this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/orders/diproses')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertCount(1, $ids);

        $foreignOrder = Order::withoutGlobalScopes()->where('agent_id', $other['agent']->id)->firstOrFail();
        $this->actingAs($b['gudang'])->getJson("/api/v1/orders/{$foreignOrder->id}")->assertNotFound();
    }

    public function test_non_gudang_roles_cannot_read_the_warehouse_queue(): void
    {
        $b = $this->branch();
        $this->diprosesOrder($b, $this->product($b, 'J'), 'J');

        foreach ([$b['admin'], $b['konsumen']] as $actor) {
            $this->actingAs($actor)->getJson('/api/v1/warehouse/orders/diproses')->assertStatus(403);
        }
    }
}