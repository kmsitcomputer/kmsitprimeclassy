<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Courier;
use App\Models\DeliveryVerification;
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
 * The Shipment lifecycle is a DERIVED aggregate over the items riding it — including under the
 * per-ITEM courier work unit (Human UAT), where one item moves at a time while its shipment
 * siblings stay put.
 *
 * A shipment must never claim "delivered" (or carry `delivered_at`) because ONE of its items
 * arrived: that both misreports the physical delivery and unlocks the append-only delivery
 * verification ("received") for goods that never came. And it must never walk BACKWARDS once
 * delivered.
 */
class ShipmentAggregateLifecycleTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    /** @var array{agen:User, admin:User, kurir:User, courier:Courier, konsumen:User} */
    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        Storage::fake('public');
        $this->b = $this->branch();
    }

    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko Unit', 'address' => 'Jl. Unit',
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

    /**
     * One COD order whose two same-date products share ONE canonical shipment, assigned to the
     * branch courier — exactly the shape the Human UAT reported ("one [Ambil] moved all items").
     *
     * @return array{order:Order, shipment:Shipment, items:array<int, OrderItem>}
     */
    private function oneShipmentTwoItems(): array
    {
        $products = [];
        foreach (['A', 'B'] as $tag) {
            $p = Product::create([
                'sku' => 'SA-'.$tag.'-'.Str::uuid(), 'name' => 'Produk '.$tag,
                'slug' => 'produk-sa-'.strtolower($tag).'-'.uniqid(),
                'has_variations' => false, 'base_price' => 25000, 'weight_grams' => 400, 'status' => 'active',
            ]);
            ProductStock::create(['agent_id' => $this->b['agen']->id, 'product_id' => $p->id, 'quantity_on_hand' => 200, 'quantity_reserved' => 0]);
            $products[] = $p;
        }

        $this->actingAs($this->b['konsumen'])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => array_map(fn (Product $p) => ['product_id' => $p->id, 'quantity' => 1], $products),
                'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810,
            ])->assertCreated();

        $order = Order::withoutGlobalScopes()->findOrFail(
            $this->actingAs($this->b['konsumen'])->getJson('/api/v1/orders')->json('data.0.id')
        );

        // Mark Kurir-Online so the canonical path is exercised; the lifecycle itself is provider-agnostic.
        Shipment::where('order_id', $order->id)->update(['shipping_provider_code' => 'openroute']);
        $shipment = Shipment::where('order_id', $order->id)->firstOrFail();

        $items = OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get()->all();
        $this->assertCount(1, Shipment::where('order_id', $order->id)->get(), 'same date => ONE canonical shipment');
        $this->assertCount(2, $items);

        app(\App\Services\Order\CourierService::class)->assignCourier($shipment->fresh(), $this->b['courier'], $this->b['admin']);

        return ['order' => $order->fresh(), 'shipment' => $shipment->fresh(), 'items' => $items];
    }

    private function itemAction(Shipment $shipment, OrderItem $item, string $status, ?UploadedFile $proof = null)
    {
        return $this->actingAs($this->b['kurir'])->call(
            'PATCH',
            "/api/v1/shipments/{$shipment->id}/items/{$item->id}/status",
            ['status' => $status],
            [],
            $proof ? ['proof' => $proof] : [],
        );
    }

    public function test_delivering_one_item_never_completes_the_shipment_or_its_sibling_item(): void
    {
        ['order' => $order, 'shipment' => $shipment, 'items' => $items] = $this->oneShipmentTwoItems();

        $this->itemAction($shipment, $items[0], 'dikirim')->assertOk();
        $this->itemAction($shipment, $items[0]->fresh(), 'terkirim', UploadedFile::fake()->image('a.jpg'))->assertOk();

        $fresh = Shipment::whereKey($shipment->id)->firstOrFail();
        $this->assertSame('terkirim', $items[0]->fresh()->status, 'the acted item arrived');
        $this->assertSame('diproses', $items[1]->fresh()->status, 'its sibling did not move');

        // THE REGRESSION: one item's arrival must not mark the whole canonical shipment delivered.
        $this->assertSame('in_transit', $fresh->status, 'a partially delivered shipment is in transit, not delivered');
        $this->assertNotNull($fresh->shipped_at, 'it did ship');
        $this->assertNull($fresh->delivered_at, 'but it is NOT delivered — a sibling item never left');

        // And the order is not delivered either.
        $this->assertSame('dikirim', $order->fresh()->status);
    }

    public function test_delivery_verification_is_refused_while_a_sibling_item_is_undelivered(): void
    {
        ['shipment' => $shipment, 'items' => $items] = $this->oneShipmentTwoItems();

        $this->itemAction($shipment, $items[0], 'dikirim')->assertOk();
        $this->itemAction($shipment, $items[0]->fresh(), 'terkirim', UploadedFile::fake()->image('a.jpg'))->assertOk();

        // Append-only "received" verification asserts the goods arrived. Item #2 is still in the
        // warehouse, so there is nothing to verify — accepting it would permanently assert receipt.
        $this->actingAs($this->b['admin'])
            ->postJson("/api/v1/shipments/{$shipment->id}/delivery-verifications", [
                'outcome' => 'received', 'note' => 'semuanya diterima',
            ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422);

        $this->assertSame(0, DeliveryVerification::query()->where('shipment_id', $shipment->id)->count());

        // Once every item has arrived the same request is accepted and completes the unit.
        $this->itemAction($shipment, $items[1], 'dikirim')->assertOk();
        $this->itemAction($shipment, $items[1]->fresh(), 'terkirim', UploadedFile::fake()->image('b.jpg'))->assertOk();

        $fresh = Shipment::whereKey($shipment->id)->firstOrFail();
        $this->assertSame('delivered', $fresh->status, 'every item arrived => the unit is delivered');
        $this->assertNotNull($fresh->delivered_at);

        $this->actingAs($this->b['admin'])
            ->postJson("/api/v1/shipments/{$shipment->id}/delivery-verifications", [
                'outcome' => 'received', 'note' => 'semuanya diterima',
            ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(201);

        $this->assertSame(1, DeliveryVerification::query()->where('shipment_id', $shipment->id)->count());
    }

    public function test_picking_up_a_sibling_never_regresses_a_delivered_shipment(): void
    {
        ['shipment' => $shipment, 'items' => $items] = $this->oneShipmentTwoItems();

        // Deliver BOTH items, then re-drive the first one — the aggregate must not walk backwards
        // and must never leave the unit simultaneously "in transit" and "delivered".
        foreach ($items as $item) {
            $this->itemAction($shipment, $item, 'dikirim')->assertOk();
            $this->itemAction($shipment, $item->fresh(), 'terkirim', UploadedFile::fake()->image('p.jpg'))->assertOk();
        }

        $deliveredAt = Shipment::whereKey($shipment->id)->value('delivered_at');
        $this->assertSame('delivered', Shipment::whereKey($shipment->id)->value('status'));
        $this->assertNotNull($deliveredAt);

        // A replayed pickup on an already-arrived item is refused (422) and changes nothing.
        $this->itemAction($shipment, $items[0]->fresh(), 'dikirim')->assertStatus(422);

        $this->assertSame('delivered', Shipment::whereKey($shipment->id)->value('status'));
        $this->assertEquals(
            $deliveredAt,
            Shipment::whereKey($shipment->id)->value('delivered_at'),
            'delivered_at is write-once and is never cleared',
        );
    }

    public function test_office_bulk_override_also_completes_only_fully_arrived_shipments(): void
    {
        ['order' => $order, 'shipment' => $shipment, 'items' => $items] = $this->oneShipmentTwoItems();

        // The office order-wide override ships everything it can, then tries to deliver. Only the
        // item already 'dikirim' may advance (an item in 'diproses' cannot skip the step), so the
        // shipment must NOT be reported as delivered while the other item is still in the warehouse.
        $this->actingAs($this->b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'dikirim'])->assertOk();
        $this->assertSame('dikirim', $items[0]->fresh()->status);
        $this->assertSame('dikirim', $items[1]->fresh()->status);

        $this->actingAs($this->b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'terkirim'])->assertOk();

        $fresh = Shipment::whereKey($shipment->id)->firstOrFail();
        $this->assertSame('delivered', $fresh->status, 'the bulk override moved every item, so the unit is delivered');
        $this->assertNotNull($fresh->delivered_at);
        $this->assertSame('terkirim', $order->fresh()->status);
    }
}