<?php

namespace Tests\Feature;

use App\Models\Courier;
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
 * REM-003 — Kurir Toko assignment: Koordinator Kurir only, persisted-provider guard, and the
 * accepted-receipt gate with multi-batch accounting.
 */
class CourierAssignmentReceiptGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    /** Two batches (6 + 4) of the same product on one order, one shipment each. */
    private function fixture(string $provider = 'openroute'): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $b = [
            'agent' => $agent,
            'admin' => User::factory()->admin()->create(['agent_id' => $agent->id]),
            'gudang' => User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]),
            'koordinator' => User::factory()->koordinatorKurir()->create(['agent_id' => $agent->id]),
            'konsumen' => User::factory()->konsumen()->create(['agent_id' => $agent->id]),
            'super' => User::factory()->superAdmin()->create(),
        ];
        $kurirUser = User::factory()->kurir()->create(['agent_id' => $agent->id]);
        $b['courier'] = Courier::create(['type' => 'internal', 'user_id' => $kurirUser->id, 'agent_id' => $agent->id, 'name' => $kurirUser->name, 'is_active' => true]);

        $product = Product::create(['sku' => 'GT-'.uniqid(), 'name' => 'Gate Cake', 'slug' => 'gt-'.uniqid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
        $order = Order::create(['order_no' => 'GT-'.Str::upper(Str::random(10)), 'konsumen_id' => $b['konsumen']->id, 'agent_id' => $agent->id, 'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'), 'status' => 'diterima', 'payment_status' => 'unpaid', 'subtotal_amount' => 10000, 'total_amount' => 10000, 'recipient_name_snapshot' => 'T', 'recipient_phone_snapshot' => '0811', 'address_snapshot' => 'T']);
        $shipments = [];
        foreach ([6, 4] as $qty) {
            $shipment = Shipment::create(['order_id' => $order->id, 'status' => 'pending', 'delivery_mode' => 'standard', 'shipping_provider_code' => $provider]);
            OrderItem::create(['order_id' => $order->id, 'shipment_id' => $shipment->id, 'product_id' => $product->id, 'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku, 'unit_price_snapshot' => 1000, 'subtotal_snapshot' => $qty * 1000, 'original_quantity' => $qty, 'fulfilled_quantity' => $qty, 'status' => 'diterima']);
            $shipments[] = $shipment;
        }
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 10]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 20]);
        WarehouseSetting::create(['agent_id' => $agent->id, 'factory_plan_enabled' => false]);
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();

        return [$b, $order->fresh(), $shipments, StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->firstOrFail()];
    }

    private function assign(User $actor, Shipment $shipment, Courier $courier)
    {
        return $this->actingAs($actor)->patchJson("/api/v1/shipments/{$shipment->id}/courier", ['courier_id' => $courier->id]);
    }

    private function prepareAndReceive(array $b, StockRequest $request, int $requestItemIndex, int $qty): void
    {
        $item = $request->items()->orderBy('id')->get()[$requestItemIndex];
        $proposal = $this->actingAs($b['gudang'])->post("/api/v1/warehouse/stock-requests/{$request->id}/proposals", $this->withHandoverProof(['items' => [['item_id' => $item->id, 'quantity' => $qty]]]), ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->actingAs($b['koordinator'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/receive", ['items' => [['item_id' => $proposal['items'][0]['id'], 'accepted' => $qty, 'short' => 0, 'damaged' => 0]]])->assertOk();
    }

    public function test_only_koordinator_kurir_may_assign_even_with_accepted_goods(): void
    {
        [$b, , $shipments, $request] = $this->fixture();
        $this->prepareAndReceive($b, $request, 0, 6);

        foreach (['admin', 'agent', 'super', 'gudang'] as $role) {
            $this->assign($b[$role], $shipments[0], $b['courier'])->assertForbidden();
            $this->actingAs($b[$role])->getJson("/api/v1/shipments/{$shipments[0]->id}/eligible-couriers")->assertForbidden();
        }
        $this->actingAs($b['koordinator'])->getJson("/api/v1/shipments/{$shipments[0]->id}/eligible-couriers")->assertOk();
        $this->assertNull($shipments[0]->fresh()->courier_id);

        $this->assign($b['koordinator'], $shipments[0], $b['courier'])->assertOk();
        $this->assertSame($b['courier']->id, $shipments[0]->fresh()->courier_id);
    }

    public function test_assignment_without_accepted_receipt_is_refused_and_batches_follow_accepted_quantity(): void
    {
        [$b, , $shipments, $request] = $this->fixture();

        // Nothing accepted yet → both refused.
        $this->assign($b['koordinator'], $shipments[0], $b['courier'])->assertStatus(422);
        $this->assertNull($shipments[0]->fresh()->courier_id);

        // Batch 1 (6) accepted → shipment A OK; shipment B (4) still not covered.
        $this->prepareAndReceive($b, $request, 0, 6);
        $this->assign($b['koordinator'], $shipments[0], $b['courier'])->assertOk();
        $this->assign($b['koordinator'], $shipments[1], $b['courier'])->assertStatus(422);

        // The order still shows in Gudang's queue for the outstanding remainder after batch 1 is assigned.
        $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/orders/diproses')->assertOk()
            ->assertJsonFragment(['id' => $shipments[0]->order_id]);

        // Batch 2 accepted → second assignment now valid (multi-shipment, same order).
        $this->prepareAndReceive($b, $request, 1, 4);
        $this->assign($b['koordinator'], $shipments[1], $b['courier'])->assertOk();
    }

    public function test_short_receipt_blocks_assignment_of_the_missing_quantity(): void
    {
        [$b, , $shipments, $request] = $this->fixture();
        $item = $request->items()->orderBy('id')->first();
        $proposal = $this->actingAs($b['gudang'])->post("/api/v1/warehouse/stock-requests/{$request->id}/proposals", $this->withHandoverProof(['items' => [['item_id' => $item->id, 'quantity' => 6]]]), ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->actingAs($b['koordinator'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/receive", ['items' => [['item_id' => $proposal['items'][0]['id'], 'accepted' => 4, 'short' => 1, 'damaged' => 1]]])->assertOk();

        $this->assign($b['koordinator'], $shipments[0], $b['courier'])->assertStatus(422);
        $this->assertNull($shipments[0]->fresh()->courier_id);
    }

    public function test_expedition_and_pickup_shipments_are_never_assignable_nor_dispatchable(): void
    {
        foreach (['rajaongkir', 'pickup'] as $provider) {
            [$b, , $shipments, $request] = $this->fixture($provider);
            $this->prepareAndReceive($b, $request, 0, 6);

            $this->assign($b['koordinator'], $shipments[0], $b['courier'])->assertStatus(422);
            $this->assertNull($shipments[0]->fresh()->courier_id);

            $rows = $this->actingAs($b['koordinator'])->getJson('/api/v1/dispatch')->assertOk()->json('data');
            $this->assertSame([], $rows, "$provider shipments must not appear in the Kurir Toko queue");
        }
    }
}
