<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * LOCKED courier work-unit model (Human UAT): courier progress is PER OrderItem.
 *
 * A canonical Shipment may hold several items, but each item progresses independently:
 * acting on Item A must never mutate Item B merely because they share the order, the
 * shipment, the delivery date or the courier. Shipment stays the assignment/delivery
 * container (executor authorization, aggregates) and the order stays the aggregate workflow.
 */
class CourierItemProgressionTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        Storage::fake('public');
    }

    /** @return array{agen:User, admin:User, kurir:User, courier:Courier, konsumen:User} */
    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko Item', 'address' => 'Jl. Item',
            'latitude' => -6.2, 'longitude' => 106.8166,
        ]);
        $kurir = User::factory()->kurir()->create(['agent_id' => $agen->id]);
        $courier = Courier::create([
            'type' => 'internal', 'user_id' => $kurir->id, 'agent_id' => $agen->id,
            'name' => $kurir->name, 'is_active' => true,
        ]);

        return [
            'agen' => $agen,
            'admin' => User::factory()->admin()->create(['agent_id' => $agen->id]),
            'kurir' => $kurir,
            'courier' => $courier,
            'konsumen' => User::factory()->konsumen()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]),
        ];
    }

    private function product(User $agen, string $tag): Product
    {
        $p = Product::create([
            'sku' => 'CI-'.strtoupper($tag).'-'.Str::uuid(), 'name' => 'Produk '.$tag,
            'slug' => 'produk-ci-'.strtolower($tag).'-'.uniqid(),
            'has_variations' => false, 'base_price' => 25000, 'weight_grams' => 400, 'status' => 'active',
        ]);
        ProductStock::create([
            'agent_id' => $agen->id, 'product_id' => $p->id, 'quantity_on_hand' => 200, 'quantity_reserved' => 0,
        ]);

        return $p;
    }

    /**
     * One COD order with three canonical date units (10 Oct: #1,#2 · 15 Oct: #3,#4 · 17 Oct: #5,#6)
     * and the 17-Oct shipment assigned to the branch courier.
     *
     * @return array{order:Order, items:array<string, OrderItem>, shipments:array<string, Shipment>}
     */
    private function threeShipmentOrder(array $b): array
    {
        $products = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $tag) {
            $products[$tag] = $this->product($b['agen'], $tag);
        }

        $response = $this->actingAs($b['konsumen'])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => array_map(fn (Product $p) => ['product_id' => $p->id, 'quantity' => 1], array_values($products)),
                'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810,
            ]);
        $response->assertCreated();

        $order = Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
        Shipment::where('order_id', $order->id)->update(['shipping_provider_code' => 'openroute']);

        $service = app(\App\Services\Order\OrderFulfillmentService::class);
        $byTag = fn () => OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get()->keyBy(
            fn (OrderItem $i) => array_search($i->product_id, array_map(fn (Product $p) => $p->id, $products), true)
        );

        // Spread C,D onto 15 Oct and E,F onto 17 Oct through the canonical reschedule path.
        foreach (['C' => '2026-10-15', 'D' => '2026-10-15', 'E' => '2026-10-17', 'F' => '2026-10-17'] as $tag => $date) {
            $service->rescheduleItemDeliveryDate($byTag()[$tag], $date, $b['admin'], 'Test spread');
        }

        $items = $byTag()->all();
        $shipC = Shipment::whereKey($items['E']->fresh()->shipment_id)->firstOrFail();
        $this->assertSame($items['F']->fresh()->shipment_id, $shipC->id, 'precondition: E and F share the 17-Oct unit');

        // Assign the 17-Oct unit to the branch courier through the canonical assignment path.
        app(\App\Services\Order\CourierService::class)->assignCourier($shipC, $b['courier'], $b['admin']);

        return [
            'order' => $order->fresh(),
            'items' => OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get()
                ->keyBy(fn (OrderItem $i) => array_search($i->product_id, array_map(fn (Product $p) => $p->id, $products), true))->all(),
            'shipmentC' => $shipC->fresh(),
        ];
    }

    private function itemAction(User $actor, Shipment $shipment, OrderItem $item, string $status, ?UploadedFile $proof = null)
    {
        $payload = ['status' => $status];
        $files = $proof ? ['proof' => $proof] : [];

        return $this->actingAs($actor)->call(
            'PATCH',
            "/api/v1/shipments/{$shipment->id}/items/{$item->id}/status",
            $payload,
            [],
            $files,
        );
    }

    private function statuses(int $orderId): array
    {
        return OrderItem::query()->where('order_id', $orderId)->orderBy('id')->pluck('status', 'id')->all();
    }

    // ---------- Independent per-item progression on one shipment ----------

    public function test_acting_on_one_item_leaves_its_shipment_sibling_untouched(): void
    {
        $b = $this->branch();
        ['order' => $order, 'items' => $items] = $this->threeShipmentOrder($b);
        $before = $this->statuses($order->id);
        $this->assertSame(array_fill_keys(array_keys($before), 'diproses'), $before);

        // Step 1: act on Item #5 (E) only.
        $this->itemAction($b['kurir'], Shipment::whereKey($items['E']->shipment_id)->firstOrFail(), $items['E'], 'dikirim')->assertOk();

        $after = $this->statuses($order->id);
        $this->assertSame('dikirim', $after[$items['E']->id], 'the acted item progressed');
        $this->assertSame('diproses', $after[$items['F']->id], 'its shipment sibling is untouched');
        foreach (['A', 'B', 'C', 'D'] as $tag) {
            $this->assertSame('diproses', $after[$items[$tag]->id], "item {$tag} on another shipment is untouched");
        }

        // Step 2: act on Item #6 (F). #5 retains its state; nothing else moves.
        $this->itemAction($b['kurir'], Shipment::whereKey($items['F']->shipment_id)->firstOrFail(), $items['F'], 'dikirim')->assertOk();

        $final = $this->statuses($order->id);
        $this->assertSame('dikirim', $final[$items['E']->id], '#5 retains its prior state');
        $this->assertSame('dikirim', $final[$items['F']->id], '#6 progressed');
        foreach (['A', 'B', 'C', 'D'] as $tag) {
            $this->assertSame('diproses', $final[$items[$tag]->id]);
        }

        // The shipment aggregate followed the existing lifecycle, and the order did NOT
        // prematurely complete: other shipments are still unfinished.
        $this->assertSame('in_transit', Shipment::whereKey($items['E']->shipment_id)->value('status'));
        $this->assertSame('dikirim', $order->fresh()->status);
    }

    // ---------- Full per-item delivery converges without double commission ----------

    public function test_per_item_delivery_converges_and_pays_each_item_exactly_once(): void
    {
        $b = $this->branch();
        ['order' => $order, 'items' => $items] = $this->threeShipmentOrder($b);

        // Snapshot a courier fee onto both items so the commission write has something to record.
        foreach (['E', 'F'] as $tag) {
            $items[$tag]->update(['courier_fee_amount' => 2000]);
        }

        foreach (['E', 'F'] as $tag) {
            $this->itemAction($b['kurir'], Shipment::whereKey($items[$tag]->shipment_id)->firstOrFail(), $items[$tag], 'dikirim')->assertOk();
        }
        foreach (['E', 'F'] as $tag) {
            $this->itemAction(
                $b['kurir'],
                Shipment::whereKey($items[$tag]->shipment_id)->firstOrFail(),
                $items[$tag]->fresh(),
                'terkirim',
                UploadedFile::fake()->image('bukti.jpg'),
            )->assertOk();
        }

        $this->assertSame('terkirim', $items['E']->fresh()->status);
        $this->assertSame('terkirim', $items['F']->fresh()->status);
        $this->assertSame(1, \App\Models\Commission::where('order_item_id', $items['E']->id)->where('beneficiary_role', 'courier')->count());
        $this->assertSame(1, \App\Models\Commission::where('order_item_id', $items['F']->id)->where('beneficiary_role', 'courier')->count());

        // The order is still not complete: the 10-Oct and 15-Oct units are unfinished.
        $this->assertSame('dikirim', $order->fresh()->status);
    }

    // ---------- Authorization: executor-only, forged ids rejected, repeats safe ----------

    public function test_item_action_authorization_and_invalid_transitions(): void
    {
        $b = $this->branch();
        ['order' => $order, 'items' => $items] = $this->threeShipmentOrder($b);
        $shipmentC = Shipment::whereKey($items['E']->shipment_id)->firstOrFail();

        // Another courier — a real one with their own profile — cannot mutate this courier's item.
        $stranger = User::factory()->kurir()->create(['agent_id' => $b['agen']->id]);
        Courier::create([
            'type' => 'internal', 'user_id' => $stranger->id, 'agent_id' => $b['agen']->id,
            'name' => $stranger->name, 'is_active' => true,
        ]);
        $this->itemAction($stranger, $shipmentC, $items['E'], 'dikirim')->assertForbidden();
        $this->assertSame('diproses', $items['E']->fresh()->status);

        // A forged item id from another order is rejected (404), moving nothing.
        $other = $this->branch('B');
        $foreignProduct = $this->product($other['agen'], 'Z');
        $foreignOrder = Order::create([
            'order_no' => 'CI-FOREIGN-'.uniqid(), 'konsumen_id' => $other['konsumen']->id,
            'agent_id' => $other['agen']->id, 'payment_method_id' => \App\Models\PaymentMethod::where('code', 'cod')->value('id'),
            'status' => 'diproses', 'payment_status' => 'unpaid', 'subtotal_amount' => 1, 'total_amount' => 1,
            'recipient_name_snapshot' => 'X', 'recipient_phone_snapshot' => '08', 'address_snapshot' => 'X',
        ]);
        $foreignItem = OrderItem::create([
            'order_id' => $foreignOrder->id, 'product_id' => $foreignProduct->id,
            'product_name_snapshot' => 'Z', 'sku_snapshot' => 'Z', 'unit_price_snapshot' => 1,
            'subtotal_snapshot' => 1, 'original_quantity' => 1, 'fulfilled_quantity' => 1, 'status' => 'diproses',
        ]);
        $this->itemAction($b['kurir'], $shipmentC, $foreignItem, 'dikirim')->assertNotFound();
        $this->assertSame('diproses', $foreignItem->fresh()->status);
        $this->assertSame('diproses', $items['E']->fresh()->status);

        // A mismatched (shipment, item) pair is rejected too.
        $shipmentA = Shipment::whereKey($items['A']->shipment_id)->firstOrFail();
        $this->itemAction($b['kurir'], $shipmentA, $items['E'], 'dikirim')->assertNotFound();

        // Invalid and repeated transitions are refused safely (422), changing nothing.
        $this->itemAction($b['kurir'], $shipmentC, $items['E'], 'dikirim')->assertOk();
        $this->itemAction($b['kurir'], $shipmentC, $items['E']->fresh(), 'dikirim')->assertStatus(422);
        $this->assertSame('dikirim', $items['E']->fresh()->status);
    }
}