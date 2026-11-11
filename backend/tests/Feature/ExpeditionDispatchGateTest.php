<?php

namespace Tests\Feature;

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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REM-003 — RajaOngkir (expedition): full payment incl. shipping, complete goods, one shipment,
 * carrier handover (tracking + evidence) recorded by the Koordinator before 'dikirim'.
 */
class ExpeditionDispatchGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function fixture(int $shipmentCount = 1, bool $twoItems = false): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $b = [
            'agent' => $agent,
            'admin' => User::factory()->admin()->create(['agent_id' => $agent->id]),
            'gudang' => User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]),
            'koordinator' => User::factory()->koordinatorKurir()->create(['agent_id' => $agent->id]),
            'konsumen' => User::factory()->konsumen()->create(['agent_id' => $agent->id]),
        ];
        $product = Product::create(['sku' => 'EX-'.uniqid(), 'name' => 'Expedition Cake', 'slug' => 'ex-'.uniqid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
        $order = Order::create(['order_no' => 'EX-'.Str::upper(Str::random(10)), 'konsumen_id' => $b['konsumen']->id, 'agent_id' => $agent->id, 'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'), 'status' => 'diterima', 'payment_status' => 'unpaid', 'subtotal_amount' => 10000, 'total_amount' => 10000, 'recipient_name_snapshot' => 'T', 'recipient_phone_snapshot' => '0811', 'address_snapshot' => 'T']);
        $shipments = [];
        for ($i = 0; $i < $shipmentCount; $i++) {
            $shipment = Shipment::create(['order_id' => $order->id, 'status' => 'pending', 'delivery_mode' => 'standard', 'shipping_provider_code' => 'rajaongkir', 'provider_meta' => ['courier' => 'jne', 'service' => 'REG']]);
            OrderItem::create(['order_id' => $order->id, 'shipment_id' => $shipment->id, 'product_id' => $product->id, 'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku, 'unit_price_snapshot' => 1000, 'subtotal_snapshot' => 10000 / $shipmentCount, 'original_quantity' => 10 / $shipmentCount, 'fulfilled_quantity' => 10 / $shipmentCount, 'status' => 'diterima']);
            $shipments[] = $shipment;
        }
        if ($twoItems) { // second line on the SAME shipment (F05)
            OrderItem::create(['order_id' => $order->id, 'shipment_id' => $shipments[0]->id, 'product_id' => $product->id, 'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku, 'unit_price_snapshot' => 1000, 'subtotal_snapshot' => 5000, 'original_quantity' => 5, 'fulfilled_quantity' => 5, 'status' => 'diterima']);
        }
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => $twoItems ? 15 : 10]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 20]);
        WarehouseSetting::create(['agent_id' => $agent->id, 'factory_plan_enabled' => false]);
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();

        return [$b, $order->fresh(), $shipments, StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->firstOrFail()];
    }

    private function receiveEverything(array $b, StockRequest $request): void
    {
        foreach ($request->items()->get() as $item) {
            $proposal = $this->actingAs($b['gudang'])->post("/api/v1/warehouse/stock-requests/{$request->id}/proposals", $this->withHandoverProof(['items' => [['item_id' => $item->id, 'quantity' => $item->remaining_qty]]]), ['Accept' => 'application/json'])->assertCreated()->json('data');
            $this->actingAs($b['koordinator'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/receive", ['items' => [['item_id' => $proposal['items'][0]['id'], 'accepted' => $item->remaining_qty, 'short' => 0, 'damaged' => 0]]])->assertOk();
        }
    }

    private function handover(User $actor, Shipment $shipment, string $carrier = 'jne', string $number = 'JNE1234567890')
    {
        return $this->actingAs($actor)->patch("/api/v1/shipments/{$shipment->id}/expedition-handover", [
            'carrier' => $carrier, 'tracking_number' => $number, 'evidence' => UploadedFile::fake()->image('serah-terima.jpg'),
        ], ['Accept' => 'application/json']);
    }

    public function test_unpaid_expedition_cannot_record_handover_or_dispatch(): void
    {
        [$b, $order, $shipments, $request] = $this->fixture();
        $this->receiveEverything($b, $request);

        $this->handover($b['koordinator'], $shipments[0])->assertStatus(422);
        $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$shipments[0]->id}/status", ['status' => 'dikirim'])->assertStatus(422);
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'dikirim'])->assertStatus(422);
        $this->assertSame('diproses', $order->fresh()->status);
        $this->assertNull($shipments[0]->fresh()->tracking_number);
    }

    public function test_paid_but_goods_incomplete_is_held(): void
    {
        [$b, $order, $shipments] = $this->fixture();
        $order->update(['payment_status' => 'paid']);

        $this->handover($b['koordinator'], $shipments[0])->assertStatus(422);
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'dikirim'])->assertStatus(422);
        $this->assertSame('diproses', $order->fresh()->status);
    }

    public function test_full_flow_requires_matching_carrier_then_handover_then_dispatch(): void
    {
        [$b, $order, $shipments, $request] = $this->fixture();
        $order->update(['payment_status' => 'paid']);
        $this->receiveEverything($b, $request);

        // Not the Koordinator → forbidden; wrong carrier → refused; nothing recorded.
        $this->handover($b['admin'], $shipments[0])->assertForbidden();
        $this->handover($b['koordinator'], $shipments[0], 'sicepat')->assertStatus(422);
        $this->assertNull($shipments[0]->fresh()->tracking_number);

        // Dispatch before the handover is recorded is refused for everyone.
        $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$shipments[0]->id}/status", ['status' => 'dikirim'])->assertStatus(422);
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'dikirim'])->assertStatus(422);

        $this->handover($b['koordinator'], $shipments[0])->assertOk();
        $fresh = $shipments[0]->fresh();
        $this->assertSame('JNE1234567890', $fresh->tracking_number);
        $this->assertNotNull($fresh->provider_meta['handover']['media_id']);
        $this->assertSame('jne', $fresh->provider_meta['courier'], 'checkout quote meta untouched');

        // Replay is idempotent; a different number is a conflict.
        $this->handover($b['koordinator'], $shipments[0])->assertOk();
        $this->handover($b['koordinator'], $shipments[0], 'jne', 'JNE0000000001')->assertStatus(409);
        $this->assertSame(1, \App\Models\Media::where('collection', 'shipment_proof')->count());

        $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$shipments[0]->id}/status", ['status' => 'dikirim'])->assertOk();
        $this->assertSame('dikirim', $order->fresh()->status);
    }

    public function test_partial_expedition_shipment_is_refused(): void
    {
        [$b, $order, $shipments, $request] = $this->fixture(2);
        $order->update(['payment_status' => 'paid']);
        $this->receiveEverything($b, $request);

        $this->handover($b['koordinator'], $shipments[0])->assertStatus(422);
        $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$shipments[0]->id}/status", ['status' => 'dikirim'])->assertStatus(422);
    }

    public function test_expedition_shipment_cannot_be_assigned_to_a_store_courier_and_other_branch_cannot_hand_over(): void
    {
        [$b, $order, $shipments, $request] = $this->fixture();
        $order->update(['payment_status' => 'paid']);
        $this->receiveEverything($b, $request);

        $other = User::factory()->agen()->create();
        $other->update(['agent_id' => $other->id]);
        $foreign = User::factory()->koordinatorKurir()->create(['agent_id' => $other->id]);
        $this->handover($foreign, $shipments[0])->assertForbidden();
    }

    /** F05 — an expedition shipment leaves as ONE unit; the per-item endpoint cannot ship a single line. */
    public function test_f05_per_item_status_cannot_partially_dispatch_an_expedition_shipment(): void
    {
        [$b, $order, $shipments, $request] = $this->fixture(1, true);
        $order->update(['payment_status' => 'paid']);
        $this->receiveEverything($b, $request);
        $this->handover($b['koordinator'], $shipments[0])->assertOk();
        $items = OrderItem::where('shipment_id', $shipments[0]->id)->orderBy('id')->get();
        $this->assertCount(2, $items);

        $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$shipments[0]->id}/items/{$items[0]->id}/status", ['status' => 'dikirim'])->assertStatus(422);
        $this->assertSame(['diproses', 'diproses'], $items->map(fn ($i) => $i->fresh()->status)->all(), 'nothing left');
        $this->assertSame('diproses', $order->fresh()->status);

        $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$shipments[0]->id}/status", ['status' => 'dikirim'])->assertOk();
        $this->assertSame(['dikirim', 'dikirim'], $items->map(fn ($i) => $i->fresh()->status)->all());
        $this->assertSame('dikirim', $order->fresh()->status);
    }
}
