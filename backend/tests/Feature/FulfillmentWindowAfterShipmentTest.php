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
 * FULFILLMENT WINDOW (BUSINESS-RULES §21): quantity adjustment AND reschedule are only allowed
 * before the order starts shipping.
 *
 * The per-ITEM courier work unit (Human UAT) means an item's status no longer mirrors its order's:
 * one item can be delivered while a sibling stays in `diproses`, and the ORDER is already `dikirim`.
 * A reschedule guarded on the ITEM status alone therefore still allowed an Admin to re-date — and
 * split onto a brand-new shipment — a sibling item of an order physically in transit, while
 * `adjustItemQuantity` on the same item correctly refused. Both mutations belong to the same window.
 */
class FulfillmentWindowAfterShipmentTest extends TestCase
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
            'user_id' => $agen->id, 'store_name' => 'Toko Jendela', 'address' => 'Jl. Jendela',
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
     * @return array{order:Order, shipment:Shipment, items:array<int, OrderItem>}
     */
    private function orderWithShippedSibling(): array
    {
        $products = [];
        foreach (['A', 'B'] as $tag) {
            $p = Product::create([
                'sku' => 'FW-'.$tag.'-'.Str::uuid(), 'name' => 'Produk '.$tag,
                'slug' => 'produk-fw-'.strtolower($tag).'-'.uniqid(),
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

        $order = Order::withoutGlobalScopes()->latest('id')->firstOrFail();
        Shipment::where('order_id', $order->id)->update(['shipping_provider_code' => 'openroute']);
        $shipment = Shipment::where('order_id', $order->id)->firstOrFail();
        $items = OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get()->all();

        app(\App\Services\Order\CourierService::class)->assignCourier($shipment->fresh(), $this->b['courier'], $this->b['admin']);

        // Deliver item #1 only: the ORDER advances to 'dikirim' while item #2 stays in 'diproses'.
        foreach (['dikirim', 'terkirim'] as $status) {
            $this->actingAs($this->b['kurir'])->call(
                'PATCH',
                "/api/v1/shipments/{$shipment->id}/items/{$items[0]->id}/status",
                ['status' => $status],
                [],
                $status === 'terkirim' ? ['proof' => UploadedFile::fake()->image('a.jpg')] : [],
            )->assertOk();
        }

        $this->assertSame('dikirim', $order->fresh()->status, 'precondition: the order already left diproses');
        $this->assertSame('diproses', $items[1]->fresh()->status, 'precondition: the sibling item is still unshipped');

        return ['order' => $order->fresh(), 'shipment' => $shipment->fresh(), 'items' => $items];
    }

    public function test_reschedule_is_refused_once_the_order_has_started_shipping(): void
    {
        ['order' => $order, 'shipment' => $shipment, 'items' => $items] = $this->orderWithShippedSibling();

        $shipmentsBefore = Shipment::where('order_id', $order->id)->pluck('id')->sort()->values()->all();

        $this->actingAs($this->b['admin'])
            ->patchJson("/api/v1/orders/{$order->id}/items/{$items[1]->id}/reschedule", [
                'requested_delivery_date' => '2026-11-01', 'reason' => 'ubah tanggal setelah kirim',
            ])
            ->assertStatus(422);

        $this->assertNull($items[1]->fresh()->requested_delivery_date, 'the date never moved');
        $this->assertSame(
            $shipmentsBefore,
            Shipment::where('order_id', $order->id)->pluck('id')->sort()->values()->all(),
            'no split shipment was created for a reschedule outside the fulfillment window',
        );
        $this->assertSame($shipment->id, $items[1]->fresh()->shipment_id);
    }

    public function test_reschedule_and_quantity_adjustment_agree_on_the_same_window(): void
    {
        ['order' => $order, 'items' => $items] = $this->orderWithShippedSibling();

        // Both are fulfillment mutations on the SAME item of the SAME already-shipping order and
        // must answer identically.
        $quantity = $this->actingAs($this->b['admin'])
            ->patchJson("/api/v1/orders/{$order->id}/items/{$items[1]->id}/fulfillment", [
                'fulfilled_quantity' => 0, 'reason' => 'kurangi',
            ]);
        $reschedule = $this->actingAs($this->b['admin'])
            ->patchJson("/api/v1/orders/{$order->id}/items/{$items[1]->id}/reschedule", [
                'requested_delivery_date' => '2026-11-01', 'reason' => 'ubah tanggal',
            ]);

        $this->assertSame(422, $quantity->status(), 'quantity adjustment is outside the window');
        $this->assertSame($quantity->status(), $reschedule->status(), 'reschedule answers identically');
    }

    public function test_a_direct_service_call_cannot_bypass_the_window(): void
    {
        ['items' => $items] = $this->orderWithShippedSibling();

        // Route authorization is not the boundary — the service is.
        $this->expectException(\App\Exceptions\ApiException::class);
        app(\App\Services\Order\OrderFulfillmentService::class)->rescheduleItemDeliveryDate(
            $items[1]->fresh(), '2026-11-01', $this->b['admin'], 'direct service call'
        );
    }

    public function test_reschedule_still_works_while_the_order_is_diproses(): void
    {
        // The guard must not close the legitimate window.
        $product = Product::create([
            'sku' => 'FW-OK-'.Str::uuid(), 'name' => 'Produk OK', 'slug' => 'produk-fw-ok-'.uniqid(),
            'has_variations' => false, 'base_price' => 25000, 'weight_grams' => 400, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $this->b['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 200, 'quantity_reserved' => 0]);

        $this->actingAs($this->b['konsumen'])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
                'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810,
            ])->assertCreated();

        $order = Order::withoutGlobalScopes()->latest('id')->firstOrFail();
        Shipment::where('order_id', $order->id)->update(['shipping_provider_code' => 'openroute']);
        $item = OrderItem::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame('diproses', $order->status);

        $this->actingAs($this->b['admin'])
            ->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/reschedule", [
                'requested_delivery_date' => '2026-11-05', 'reason' => 'jadwal pelanggan',
            ])
            ->assertOk();

        $this->assertSame('2026-11-05', $item->fresh()->requested_delivery_date->toDateString());
        $this->assertSame('diproses', $order->fresh()->status, 'a reschedule never moves the order status');
    }
}