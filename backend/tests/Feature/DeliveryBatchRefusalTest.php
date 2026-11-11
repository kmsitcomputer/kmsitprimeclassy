<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\DeliveryRefusal;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\StockRequest;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REM-003 — Kurir Toko subsequent batches (split of an undispatched part of a line) and delivery
 * refusal with reverse custody (courier → Koordinator → redelivery / Gudang).
 */
class DeliveryBatchRefusalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function fixture(int $qty = 10): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $b = [
            'agent' => $agent,
            'admin' => User::factory()->admin()->create(['agent_id' => $agent->id]),
            'gudang' => User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]),
            'koordinator' => User::factory()->koordinatorKurir()->create(['agent_id' => $agent->id]),
            'konsumen' => User::factory()->konsumen()->create(['agent_id' => $agent->id]),
            'kurir' => User::factory()->kurir()->create(['agent_id' => $agent->id]),
            'kurir2' => User::factory()->kurir()->create(['agent_id' => $agent->id]),
        ];
        $b['courier'] = Courier::create(['type' => 'internal', 'user_id' => $b['kurir']->id, 'agent_id' => $agent->id, 'name' => 'K1', 'is_active' => true]);
        Courier::create(['type' => 'internal', 'user_id' => $b['kurir2']->id, 'agent_id' => $agent->id, 'name' => 'K2', 'is_active' => true]);

        $product = Product::create(['sku' => 'BR-'.uniqid(), 'name' => 'Batch Cake', 'slug' => 'br-'.uniqid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
        $order = Order::create(['order_no' => 'BR-'.Str::upper(Str::random(10)), 'konsumen_id' => $b['konsumen']->id, 'agent_id' => $agent->id, 'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'), 'status' => 'diterima', 'payment_status' => 'unpaid', 'subtotal_amount' => $qty * 1000, 'total_amount' => $qty * 1000, 'recipient_name_snapshot' => 'T', 'recipient_phone_snapshot' => '0811', 'address_snapshot' => 'T']);
        $shipment = Shipment::create(['order_id' => $order->id, 'status' => 'pending', 'delivery_mode' => 'standard', 'shipping_provider_code' => 'openroute']);
        $item = OrderItem::create(['order_id' => $order->id, 'shipment_id' => $shipment->id, 'product_id' => $product->id, 'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku, 'unit_price_snapshot' => 1000, 'subtotal_snapshot' => $qty * 1000, 'original_quantity' => $qty, 'fulfilled_quantity' => $qty, 'status' => 'diterima']);
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => $qty]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 30]);
        WarehouseSetting::create(['agent_id' => $agent->id, 'factory_plan_enabled' => false]);
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();

        return [$b, $product, $order->fresh(), $item->fresh(), $shipment, StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->firstOrFail()];
    }

    private function receive(array $b, StockRequest $request, int $qty): void
    {
        $item = $request->items()->orderBy('id')->get()->firstWhere(fn ($i) => $i->remaining_qty >= $qty);
        $proposal = $this->actingAs($b['gudang'])->post("/api/v1/warehouse/stock-requests/{$request->id}/proposals", $this->withHandoverProof(['items' => [['item_id' => $item->id, 'quantity' => $qty]]]), ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->actingAs($b['koordinator'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/receive", ['items' => [['item_id' => $proposal['items'][0]['id'], 'accepted' => $qty, 'short' => 0, 'damaged' => 0]]])->assertOk();
    }

    private function bucket(int $agentId, int $productId, string $type): int
    {
        return (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('stock_type', $type)->where('product_id', $productId)->sum('quantity');
    }

    public function test_later_batch_is_split_after_the_first_batch_shipped_without_duplicating_demand(): void
    {
        [$b, $product, $order, $item, $shipment, $request] = $this->fixture(10);
        $this->receive($b, $request, 6);

        // Carve the not-yet-accepted 4 into its own later batch.
        $resp = $this->actingAs($b['koordinator'])->postJson("/api/v1/dispatch/order-items/{$item->id}/split-batch", ['quantity' => 4])->assertCreated()->json('data');
        $this->assertSame(4, $resp['quantity']);
        $this->assertNotSame($shipment->id, $resp['shipment_id']);
        $this->assertSame(6, $item->fresh()->fulfilled_quantity);
        $parentDemand = $request->items()->where('order_item_id', $item->id)->firstOrFail();
        $childDemand = $request->items()->where('order_item_id', $resp['order_item_id'])->firstOrFail();
        $this->assertSame([6, 6, 0], [$parentDemand->requested_qty, $parentDemand->fulfilled_qty, $parentDemand->remaining_qty]);
        $this->assertSame([4, 0, 4], [$childDemand->requested_qty, $childDemand->fulfilled_qty, $childDemand->remaining_qty]);
        $this->assertSame(1, StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->count());
        $this->assertEquals(10000, (float) OrderItem::where('order_id', $order->id)->sum('subtotal_snapshot'), 'price conserved');

        // Batch 1 ships.
        $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$shipment->id}/courier", ['courier_id' => $b['courier']->id])->assertOk();
        $this->actingAs($b['kurir'])->patchJson("/api/v1/shipments/{$shipment->id}/status", ['status' => 'dikirim'])->assertOk();
        $this->assertSame('dikirim', $order->fresh()->status);

        // The shipped line can no longer be split; the later batch cannot be assigned before it is accepted.
        $this->actingAs($b['koordinator'])->postJson("/api/v1/dispatch/order-items/{$item->id}/split-batch", ['quantity' => 1])->assertStatus(422);
        $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$resp['shipment_id']}/courier", ['courier_id' => $b['courier']->id])->assertStatus(422);

        // Order is still in Gudang's scope for the outstanding 4; prepare + receive, then assign batch 2.
        $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/orders/diproses')->assertOk()->assertJsonFragment(['id' => $order->id]);
        $this->receive($b, $request->fresh(), 4);
        $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$resp['shipment_id']}/courier", ['courier_id' => $b['courier']->id])->assertOk();
        $this->actingAs($b['kurir'])->patchJson("/api/v1/shipments/{$resp['shipment_id']}/status", ['status' => 'dikirim'])->assertOk();
        $this->assertSame(10, $this->bucket($b['agent']->id, $product->id, 'shipping'), 'every unit accepted once, none twice');
    }

    public function test_split_is_koordinator_only_and_rejects_invalid_quantities(): void
    {
        [$b, , , $item] = $this->fixture(10);
        $url = "/api/v1/dispatch/order-items/{$item->id}/split-batch";
        foreach (['admin', 'gudang', 'kurir'] as $role) {
            $this->actingAs($b[$role])->postJson($url, ['quantity' => 2])->assertForbidden();
        }
        foreach ([0, 10, 11] as $q) {
            $this->actingAs($b['koordinator'])->postJson($url, ['quantity' => $q])->assertStatus(422);
        }
    }

    private function dispatchedItem(array $b, StockRequest $request, Shipment $shipment, OrderItem $item): void
    {
        $this->receive($b, $request, 10);
        $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$shipment->id}/courier", ['courier_id' => $b['courier']->id])->assertOk();
        $this->actingAs($b['kurir'])->patchJson("/api/v1/shipments/{$shipment->id}/items/{$item->id}/status", ['status' => 'dikirim'])->assertOk();
    }

    public function test_refusal_runs_courier_handback_then_koordinator_receipt_then_redelivery(): void
    {
        [$b, $product, $order, $item, $shipment, $request] = $this->fixture(10);
        $this->dispatchedItem($b, $request, $shipment, $item);

        // Only the executing courier can refuse; the item stays out for delivery.
        $this->actingAs($b['kurir2'])->postJson("/api/v1/shipments/{$shipment->id}/items/{$item->id}/refuse", ['reason' => 'x'])->assertForbidden();
        $r = $this->actingAs($b['kurir'])->postJson("/api/v1/shipments/{$shipment->id}/items/{$item->id}/refuse", ['reason' => 'Pelanggan menolak'])->assertCreated()->json('data');
        $this->assertSame('with_courier', $r['status']);
        $this->actingAs($b['kurir'])->postJson("/api/v1/shipments/{$shipment->id}/items/{$item->id}/refuse", ['reason' => 'dup'])->assertStatus(422);

        // Refused goods can never be marked delivered while the refusal is open.
        $this->actingAs($b['kurir'])->patch("/api/v1/shipments/{$shipment->id}/items/{$item->id}/status", ['status' => 'terkirim', 'proof' => \Illuminate\Http\UploadedFile::fake()->image('p.jpg')], ['Accept' => 'application/json'])->assertStatus(422);

        // Two-party custody: the Koordinator cannot receive before the hand-back, nobody else can receive.
        $this->actingAs($b['koordinator'])->postJson("/api/v1/delivery-refusals/{$r['id']}/receive")->assertStatus(422);
        $this->actingAs($b['kurir2'])->postJson("/api/v1/delivery-refusals/{$r['id']}/hand-back")->assertForbidden();
        $this->actingAs($b['kurir'])->postJson("/api/v1/delivery-refusals/{$r['id']}/hand-back")->assertOk()->assertJsonPath('data.status', 'handed_back');
        $this->actingAs($b['kurir'])->postJson("/api/v1/delivery-refusals/{$r['id']}/hand-back")->assertOk(); // idempotent
        $this->actingAs($b['admin'])->postJson("/api/v1/delivery-refusals/{$r['id']}/receive")->assertForbidden();
        $this->actingAs($b['koordinator'])->postJson("/api/v1/delivery-refusals/{$r['id']}/receive")->assertOk()->assertJsonPath('data.status', 'received');
        $this->actingAs($b['koordinator'])->getJson('/api/v1/dispatch/refusals')->assertOk()->assertJsonFragment(['id' => $r['id']]);

        $this->actingAs($b['koordinator'])->postJson("/api/v1/delivery-refusals/{$r['id']}/disposition", ['action' => 'redelivery', 'note' => 'coba lagi'])->assertOk()->assertJsonPath('data.status', 'redelivery');
        $this->actingAs($b['koordinator'])->postJson("/api/v1/delivery-refusals/{$r['id']}/disposition", ['action' => 'redelivery'])->assertOk(); // replay

        $item->refresh();
        $this->assertSame('diproses', $item->status);
        $newShipment = Shipment::findOrFail($item->shipment_id);
        $this->assertNotSame($shipment->id, $newShipment->id);
        $this->assertNull($newShipment->courier_id);
        // Stock untouched by a redelivery decision: still accepted, still in Shipping, nothing double counted.
        $this->assertSame(10, $this->bucket($b['agent']->id, $product->id, 'shipping'));
        $this->assertSame(10, $request->items()->firstOrFail()->fresh()->fulfilled_qty);
        // The Koordinator can assign the redelivery again (accepted quantity still covers it).
        $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$newShipment->id}/courier", ['courier_id' => $b['courier']->id])->assertOk();
    }

    public function test_refused_goods_returned_to_gudang_restore_transit_reservation_and_demand(): void
    {
        [$b, $product, $order, $item, $shipment, $request] = $this->fixture(10);
        $this->dispatchedItem($b, $request, $shipment, $item);
        $this->assertSame(0, (int) ProductStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->value('quantity_reserved'));

        $r = $this->actingAs($b['kurir'])->postJson("/api/v1/shipments/{$shipment->id}/items/{$item->id}/refuse", ['reason' => 'rusak di jalan'])->assertCreated()->json('data');
        $this->actingAs($b['kurir'])->postJson("/api/v1/delivery-refusals/{$r['id']}/hand-back")->assertOk();
        $this->actingAs($b['koordinator'])->postJson("/api/v1/delivery-refusals/{$r['id']}/receive")->assertOk();
        $this->actingAs($b['koordinator'])->postJson("/api/v1/delivery-refusals/{$r['id']}/disposition", ['action' => 'return_to_warehouse'])->assertOk()->assertJsonPath('data.status', 'returned_to_warehouse');
        $this->actingAs($b['koordinator'])->postJson("/api/v1/delivery-refusals/{$r['id']}/disposition", ['action' => 'return_to_warehouse'])->assertOk(); // replay: no second reversal

        $this->assertSame(0, $this->bucket($b['agent']->id, $product->id, 'shipping'));
        $this->assertSame(30, $this->bucket($b['agent']->id, $product->id, 'transit'), '10 units back in Transit, once');
        $this->assertSame(10, (int) ProductStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->value('quantity_reserved'), 'owed units are reserved again');
        $demand = $request->items()->firstOrFail()->fresh();
        $this->assertSame([10, 0, 10], [$demand->requested_qty, $demand->fulfilled_qty, $demand->remaining_qty]);
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame('diproses', $item->fresh()->status);
        $this->assertSame(1, DeliveryRefusal::withoutGlobalScopes()->where('order_item_id', $item->id)->count());

        // The line needs a new fulfilment cycle: assignment is refused until it is accepted again.
        $this->actingAs($b['koordinator'])->patchJson('/api/v1/shipments/'.$item->fresh()->shipment_id.'/courier', ['courier_id' => $b['courier']->id])->assertStatus(422);
    }

    public function test_expedition_list_and_dispatch_row_expose_readiness_and_demand_state(): void
    {
        [$b, , $order, , $shipment, $request] = $this->fixture(10);

        $this->actingAs($b['koordinator'])->getJson('/api/v1/dispatch')->assertOk()
            ->assertJsonPath('data.0.fulfillment.outstanding_qty', 10)->assertJsonPath('data.0.fulfillment.accepted_qty', 0);

        $shipment->update(['shipping_provider_code' => 'rajaongkir', 'provider_meta' => ['courier' => 'jne', 'service' => 'REG']]);
        $rows = $this->actingAs($b['koordinator'])->getJson('/api/v1/dispatch/expeditions')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('jne', $rows[0]['carrier']);
        $this->assertFalse($rows[0]['ready']['paid_in_full']);
        $this->assertFalse($rows[0]['ready']['goods_complete']);
        $this->assertTrue($rows[0]['ready']['single_shipment']);
        $this->assertArrayNotHasKey('total_amount', $rows[0]);
        // Not in the Kurir Toko queue.
        $this->assertSame([], $this->actingAs($b['koordinator'])->getJson('/api/v1/dispatch')->json('data'));
    }

    public function test_payment_summary_exposes_the_regular_payment_cap_for_kurir_toko_only(): void
    {
        [$b, , $order, , $shipment] = $this->fixture(10);
        $this->actingAs($b['admin'])->getJson("/api/v1/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.payment_summary.regular_payments_limit', 3)->assertJsonPath('data.payment_summary.regular_payments_count', 0);
        $shipment->update(['shipping_provider_code' => 'rajaongkir']);
        $this->actingAs($b['admin'])->getJson("/api/v1/orders/{$order->id}")->assertOk()->assertJsonPath('data.payment_summary.regular_payments_limit', null);
    }

    public function test_partial_refusal_splits_delivered_and_refused_units_and_returns_only_the_refused_to_gudang(): void
    {
        [$b, $product, $order, $item, $shipment, $request] = $this->fixture(5);
        $this->dispatchedItem5($b, $request, $shipment, $item);

        // Customer keeps 3, refuses 2.
        $this->actingAs($b['kurir'])->postJson("/api/v1/shipments/{$shipment->id}/items/{$item->id}/refuse", ['reason' => 'x', 'quantity' => 6])->assertStatus(422);
        $r = $this->actingAs($b['kurir'])->postJson("/api/v1/shipments/{$shipment->id}/items/{$item->id}/refuse", ['reason' => 'pelanggan hanya ambil 3', 'quantity' => 2])->assertCreated()->json('data');
        $this->assertSame(2, $r['quantity']);
        $this->assertNotSame($item->id, $r['order_item_id']);

        $kept = $item->fresh();
        $refused = OrderItem::findOrFail($r['order_item_id']);
        $this->assertSame([3, 'dikirim'], [$kept->fulfilled_quantity, $kept->status]);
        $this->assertSame([2, 'dikirim', $shipment->id], [$refused->fulfilled_quantity, $refused->status, $refused->shipment_id]);
        $this->assertEquals(5000, (float) OrderItem::where('order_id', $order->id)->sum('subtotal_snapshot'), 'value conserved');
        $demand = $request->items()->orderBy('id')->get();
        $this->assertSame([5, 5, 0], [$demand->sum('requested_qty'), $demand->sum('fulfilled_qty'), $demand->sum('remaining_qty')], 'no outstanding demand created');

        // The 3 kept units are delivered normally; the refused 2 cannot be, while the refusal is open.
        $this->actingAs($b['kurir'])->patch("/api/v1/shipments/{$shipment->id}/items/{$kept->id}/status", ['status' => 'terkirim', 'proof' => \Illuminate\Http\UploadedFile::fake()->image('p.jpg')], ['Accept' => 'application/json'])->assertOk();
        $this->actingAs($b['kurir'])->patch("/api/v1/shipments/{$shipment->id}/items/{$refused->id}/status", ['status' => 'terkirim', 'proof' => \Illuminate\Http\UploadedFile::fake()->image('p.jpg')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame('dikirim', $order->fresh()->status);

        // Two-party reverse custody, then return the refused 2 (only) to Gudang.
        $this->actingAs($b['kurir'])->postJson("/api/v1/delivery-refusals/{$r['id']}/hand-back")->assertOk();
        $this->actingAs($b['koordinator'])->postJson("/api/v1/delivery-refusals/{$r['id']}/receive")->assertOk();
        $this->actingAs($b['koordinator'])->postJson("/api/v1/delivery-refusals/{$r['id']}/disposition", ['action' => 'return_to_warehouse'])->assertOk();

        $this->assertSame(3, $this->bucket($b['agent']->id, $product->id, 'shipping'), 'delivered 3 stay out, refused 2 left Shipping');
        $this->assertSame(27, $this->bucket($b['agent']->id, $product->id, 'transit'), '30 − 5 accepted + 2 returned');
        $this->assertSame(2, (int) ProductStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->value('quantity_reserved'), 'only the refused 2 are owed again');
        $d = $request->items()->where('order_item_id', $refused->id)->firstOrFail()->fresh();
        $this->assertSame([2, 0, 2], [$d->requested_qty, $d->fulfilled_qty, $d->remaining_qty]);
        $this->assertSame('diproses', $refused->fresh()->status);
        $this->assertSame('terkirim', $kept->fresh()->status);
    }

    private function dispatchedItem5(array $b, StockRequest $request, Shipment $shipment, OrderItem $item): void
    {
        $this->receive($b, $request, 5);
        $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$shipment->id}/courier", ['courier_id' => $b['courier']->id])->assertOk();
        $this->actingAs($b['kurir'])->patchJson("/api/v1/shipments/{$shipment->id}/items/{$item->id}/status", ['status' => 'dikirim'])->assertOk();
    }

    /** SKS isolation — the new refusal/batch/request/cancel features never reach a self_sub (Sub-owned) delivery. */
    public function test_sks_self_delivery_is_not_reachable_by_the_new_refusal_batch_and_request_features(): void
    {
        [$b, , $order, $item, $shipment] = $this->fixture(10);
        $sks = User::factory()->salesKurirSub()->create(['agent_id' => $b['agent']->id]);
        $order->update(['placed_by_user_id' => $sks->id]);
        \Illuminate\Support\Facades\DB::table('shipments')->where('id', $shipment->id)->update(['delivery_mode' => 'self_sub', 'self_delivered_by_user_id' => $sks->id, 'courier_id' => null, 'status' => 'in_transit']);
        $item->update(['status' => 'dikirim']);
        $refusalsBefore = DeliveryRefusal::withoutGlobalScopes()->count();

        // The owning SKS cannot open a refusal / reverse custody on its own delivery …
        $this->actingAs($sks)->postJson("/api/v1/shipments/{$shipment->id}/items/{$item->id}/refuse", ['reason' => 'tolak'])->assertStatus(422);
        $this->assertSame($refusalsBefore, DeliveryRefusal::withoutGlobalScopes()->count());
        // … and the Koordinator's Kurir Toko tools refuse it too (scope never widened to self_sub).
        $this->actingAs($b['koordinator'])->postJson("/api/v1/dispatch/orders/{$order->id}/request-fulfillment")->assertStatus(422);
        $this->actingAs($b['koordinator'])->postJson("/api/v1/dispatch/order-items/{$item->id}/split-batch", ['quantity' => 2])->assertStatus(422);
        $this->actingAs($b['admin'])->postJson("/api/v1/orders/{$order->id}/items/{$item->id}/cancel-undelivered", ['quantity' => 1, 'expected_quantity' => 10, 'reason' => 'x'])->assertStatus(422);
        $this->assertSame('dikirim', $item->fresh()->status);
    }
}
