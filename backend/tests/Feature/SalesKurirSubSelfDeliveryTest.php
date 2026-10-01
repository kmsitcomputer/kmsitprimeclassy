<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\AgentProfile;
use App\Models\Commission;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ShippingConfiguration;
use App\Models\SubStockReservation;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use App\Services\Order\CourierService;
use App\Services\Stock\SubStockService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * R-03 / decision A + G: Sales-Kurir-Sub self-delivery is a first-class path that never requires a
 * Courier profile and never touches courier_id. Normal Kurir keep the standard workflow, and a
 * Sales-Kurir-Sub can only self-deliver Sub-sourced shipments.
 */
class SalesKurirSubSelfDeliveryTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

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
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Toko', 'address' => 'Jl. X', 'latitude' => -6.2, 'longitude' => 106.8]);
        $sub = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id]);
        $otherSub = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id]);
        $kurir = User::factory()->kurir()->create(['agent_id' => $agen->id]);
        // A real Courier profile ONLY for the normal kurir — the Sales-Kurir-Sub deliberately has none.
        Courier::create(['type' => 'internal', 'user_id' => $kurir->id, 'agent_id' => $agen->id, 'name' => $kurir->name, 'is_active' => true]);
        $referred = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $superAdmin = User::factory()->superAdmin()->create(['agent_id' => $agen->id]);

        $location = $this->location($agen, $sub, 'L1');
        $otherLocation = $this->location($agen, $otherSub, 'L2');

        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true]);
        $product = Product::create(['sku' => 'SUB-'.Str::uuid(), 'name' => 'Kue Sub', 'slug' => 'kue-sub-'.uniqid(), 'has_variations' => false, 'base_price' => 50000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 20]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $location->id, 'quantity' => 10]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $otherLocation->id, 'quantity' => 10]);

        return compact('agen', 'sub', 'otherSub', 'kurir', 'referred', 'admin', 'superAdmin', 'location', 'otherLocation', 'product');
    }

    private function location(User $agen, User $owner, string $code): WarehouseSubLocation
    {
        $location = WarehouseSubLocation::create(['agent_id' => $agen->id, 'code' => $code, 'name' => $code, 'created_by' => $agen->id]);
        $location->forceFill(['owner_user_id' => $owner->id])->save();

        return $location;
    }

    private function payload(int $qty, array $extra = []): array
    {
        return [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $this->b['product']->id, 'quantity' => $qty]],
            'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ] + $extra;
    }

    private function placeSubOrder(User $actor, int $qty): Order
    {
        $id = $this->actingAs($actor)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', $this->payload($qty, ['stock_source' => 'sub']))
            ->assertCreated()->json('data.id');

        return Order::withoutGlobalScopes()->findOrFail($id);
    }

    public function test_sub_order_shipment_is_created_as_self_sub_with_owner_and_no_courier(): void
    {
        $this->assertNull($this->b['sub']->courierProfile, 'the Sales-Kurir-Sub deliberately has no Courier profile');

        $order = $this->placeSubOrder($this->b['sub'], 3);
        $shipment = $order->items()->firstOrFail()->shipment;

        $this->assertSame('self_sub', $shipment->delivery_mode);
        $this->assertSame($this->b['sub']->id, $shipment->self_delivered_by_user_id);
        $this->assertNull($shipment->courier_id);
    }

    public function test_owner_self_delivers_without_courier_profile_and_consumes_sub_stock_once(): void
    {
        $order = $this->placeSubOrder($this->b['sub'], 3);
        $shipment = $order->items()->firstOrFail()->shipment;

        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'dikirim', $this->b['sub']);

        $this->assertSame(7, app(SubStockService::class)->physical($this->b['location']->id, $this->b['product']->id, null));
        $this->assertSame('consumed', SubStockReservation::query()->firstOrFail()->status);
        $this->assertSame('in_transit', $shipment->fresh()->status);
        $this->assertNull($shipment->fresh()->courier_id, 'self-delivery never assigns a courier_id');

        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'terkirim', $this->b['sub'], UploadedFile::fake()->image('proof.jpg'));

        $this->assertSame('delivered', $shipment->fresh()->status);
        $this->assertNotNull($shipment->fresh()->proof_media_id);
        $this->assertSame(7, app(SubStockService::class)->physical($this->b['location']->id, $this->b['product']->id, null));
    }

    public function test_self_delivery_requires_proof_to_mark_terkirim(): void
    {
        $order = $this->placeSubOrder($this->b['sub'], 1);
        $shipment = $order->items()->firstOrFail()->shipment;

        $this->expectException(ApiException::class);
        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'terkirim', $this->b['sub']);
    }

    public function test_another_sales_kurir_sub_cannot_operate_the_self_delivery_shipment(): void
    {
        $order = $this->placeSubOrder($this->b['sub'], 1);
        $shipment = $order->items()->firstOrFail()->shipment;

        $this->expectException(ApiException::class);
        try {
            app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'dikirim', $this->b['otherSub']);
        } finally {
            $this->assertSame(10, app(SubStockService::class)->physical($this->b['location']->id, $this->b['product']->id, null));
            $this->assertSame('active', SubStockReservation::query()->firstOrFail()->status);
        }
    }

    public function test_normal_kurir_cannot_operate_a_self_sub_shipment(): void
    {
        $order = $this->placeSubOrder($this->b['sub'], 1);
        $shipment = $order->items()->firstOrFail()->shipment;

        $this->expectException(ApiException::class);
        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'dikirim', $this->b['kurir']);
    }

    public function test_office_cannot_assign_a_courier_to_a_self_sub_shipment(): void
    {
        $order = $this->placeSubOrder($this->b['sub'], 1);
        $shipment = $order->items()->firstOrFail()->shipment;
        $courier = Courier::where('user_id', $this->b['kurir']->id)->firstOrFail();

        $this->expectException(ApiException::class);
        app(CourierService::class)->assignCourier($shipment, $courier, $this->b['agen']);
    }

    public function test_sales_kurir_sub_cannot_operate_a_standard_agent_shipment(): void
    {
        // An Agent-sourced order (referred consumer self-checkout) creates a STANDARD shipment.
        $id = $this->actingAs($this->b['referred'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', $this->payload(1))
            ->assertCreated()->json('data.id');
        $shipment = OrderItem::where('order_id', $id)->firstOrFail()->shipment;

        $this->assertSame('standard', $shipment->delivery_mode);

        $this->expectException(ApiException::class);
        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'dikirim', $this->b['sub']);
    }

    public function test_sub_sourced_shipment_rejects_a_courier_id_at_the_database_level(): void
    {
        $order = $this->placeSubOrder($this->b['sub'], 1);
        $shipment = $order->items()->firstOrFail()->shipment;
        $courier = Courier::where('user_id', $this->b['kurir']->id)->firstOrFail();

        $this->expectException(\Illuminate\Database\QueryException::class);
        $shipment->forceFill(['courier_id' => $courier->id])->save();
    }

    /* ---------------- BLOCKER-1: generic order status must not bypass self_sub ---------------- */

    public function test_generic_order_status_cannot_advance_a_sub_item_to_dikirim_or_terkirim(): void
    {
        $order = $this->placeSubOrder($this->b['sub'], 2);
        $item = $order->items()->firstOrFail();
        $shipment = $item->shipment;

        // No office role may bulk-ship the Sub line to 'dikirim'.
        foreach (['admin', 'agen', 'superAdmin'] as $role) {
            $this->actingAs($this->b[$role])
                ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'dikirim'])
                ->assertStatus(422);
        }

        $this->assertSame('diproses', $item->fresh()->status);
        $this->assertSame('active', SubStockReservation::query()->firstOrFail()->status);
        $this->assertSame(10, app(SubStockService::class)->physical($this->b['location']->id, $this->b['product']->id, null));

        // The owner ships correctly through the self-delivery path.
        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'dikirim', $this->b['sub']);
        $this->assertSame('dikirim', $item->fresh()->status);

        // ...and no office role may then complete the delivery via the generic path (no proof bypass).
        foreach (['admin', 'agen', 'superAdmin'] as $role) {
            $this->actingAs($this->b[$role])
                ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'terkirim'])
                ->assertStatus(422);
        }

        $this->assertSame('dikirim', $item->fresh()->status);
        $this->assertNull($shipment->fresh()->delivered_at);
        $this->assertSame(0, Commission::query()->where('order_item_id', $item->id)->where('beneficiary_role', 'courier')->count());

        // The owner can still finish through the correct shipment endpoint with proof.
        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'terkirim', $this->b['sub'], UploadedFile::fake()->image('proof.jpg'));
        $this->assertSame('terkirim', $item->fresh()->status);
        $this->assertNotNull($shipment->fresh()->delivered_at);
    }

    /* ---------------- BLOCKER-2: Sales-Kurir-Sub courier queue is scoped ---------------- */

    public function test_sales_kurir_sub_courier_queue_only_shows_own_self_sub_shipments(): void
    {
        $own = $this->placeSubOrder($this->b['sub'], 1);
        $other = $this->placeSubOrder($this->b['otherSub'], 1);
        $agentOrderId = $this->actingAs($this->b['referred'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', $this->payload(1))->assertCreated()->json('data.id');

        $ids = fn (User $actor) => collect($this->actingAs($actor)->getJson('/api/v1/kurir/orders')->assertOk()->json('data'))->pluck('id');

        $ownIds = $ids($this->b['sub']);
        $this->assertTrue($ownIds->contains($own->id));
        $this->assertFalse($ownIds->contains($other->id), 'never another Sales-Kurir-Sub self_sub shipment');
        $this->assertFalse($ownIds->contains($agentOrderId), 'never an Agent-sourced standard shipment');

        // Still visible to the owner after shipping; never visible to a sibling Sub.
        app(CourierService::class)->updateShipmentStatus($own->items()->firstOrFail()->shipment->fresh(), 'dikirim', $this->b['sub']);
        $this->assertTrue($ids($this->b['sub'])->contains($own->id));
        $this->assertFalse($ids($this->b['otherSub'])->contains($own->id));
    }

    public function test_sales_kurir_sub_cannot_use_normal_kurir_return_routes(): void
    {
        $this->actingAs($this->b['sub'])->getJson('/api/v1/kurir/returns')->assertForbidden();
        $this->actingAs($this->b['sub'])->getJson('/api/v1/kurir/reports/delivered')->assertForbidden();
    }

    /* ---------------- MAJOR-6: shipment routing state exposed for UX gating ---------------- */

    public function test_order_item_resource_exposes_delivery_mode_for_ux_gating(): void
    {
        $order = $this->placeSubOrder($this->b['sub'], 1);
        $subItem = $this->actingAs($this->b['admin'])->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data.items.0');
        $this->assertSame('self_sub', $subItem['delivery_mode']);
        $this->assertSame($this->b['sub']->id, $subItem['self_delivered_by_user_id']);

        $agentOrderId = $this->actingAs($this->b['referred'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', $this->payload(1))->assertCreated()->json('data.id');
        $agentItem = $this->actingAs($this->b['admin'])->getJson("/api/v1/orders/{$agentOrderId}")->assertOk()->json('data.items.0');
        $this->assertSame('standard', $agentItem['delivery_mode']);
        $this->assertNull($agentItem['self_delivered_by_user_id']);
    }

    public function test_self_sub_receipt_print_permissions_mirror_backend_policy(): void
    {
        $order = $this->placeSubOrder($this->b['sub'], 1);
        $shipment = $order->items()->firstOrFail()->shipment;

        // ShipmentPolicy::printReceipt: owner, super_admin, same-Agent agen/admin may print.
        foreach (['sub', 'admin', 'agen', 'superAdmin'] as $who) {
            $this->actingAs($this->b[$who])->getJson("/api/v1/shipments/{$shipment->id}/receipt")->assertOk();
        }

        // A normal Kurir is never the self_sub deliverer.
        $this->actingAs($this->b['kurir'])->getJson("/api/v1/shipments/{$shipment->id}/receipt")->assertForbidden();
    }

    /* ---------------- UAT-R03-01: /kurir/orders status filter + role boundaries ---------------- */

    public function test_sub_orders_status_filter_is_scoped_to_own_self_sub_shipments(): void
    {
        $own = $this->placeSubOrder($this->b['sub'], 1);
        $other = $this->placeSubOrder($this->b['otherSub'], 1);
        $agentOrderId = $this->actingAs($this->b['referred'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', $this->payload(1))->assertCreated()->json('data.id');
        $unrelatedAgentId = $this->secondBranchDeliveredOrder();

        $ids = fn (User $actor, string $status) => collect(
            $this->actingAs($actor)->getJson("/api/v1/kurir/orders?status={$status}")->assertOk()->json('data')
        )->pluck('id');

        // diproses — own visible, everyone else's not.
        $diproses = $ids($this->b['sub'], 'diproses');
        $this->assertTrue($diproses->contains($own->id));
        $this->assertFalse($diproses->contains($other->id));
        $this->assertFalse($diproses->contains($agentOrderId));

        // dikirim — own visible (self_sub ownership), other Sub's not.
        $shipment = $own->items()->firstOrFail()->shipment;
        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'dikirim', $this->b['sub']);
        $dikirim = $ids($this->b['sub'], 'dikirim');
        $this->assertTrue($dikirim->contains($own->id));
        $this->assertFalse($ids($this->b['otherSub'], 'dikirim')->contains($own->id));

        // terkirim — own self_sub history visible; another Sub / Agent-source / unrelated Agent not.
        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'terkirim', $this->b['sub'], UploadedFile::fake()->image('proof.jpg'));
        $terkirim = $ids($this->b['sub'], 'terkirim');
        $this->assertTrue($terkirim->contains($own->id));
        $this->assertFalse($terkirim->contains($other->id));
        $this->assertFalse($terkirim->contains($agentOrderId));
        $this->assertFalse($terkirim->contains($unrelatedAgentId));
    }

    public function test_normal_kurir_still_reaches_return_and_delivered_routes(): void
    {
        $this->actingAs($this->b['kurir'])->getJson('/api/v1/kurir/returns')->assertOk();
        $this->actingAs($this->b['kurir'])->getJson('/api/v1/kurir/reports/delivered')->assertOk();
    }

    /** A delivered order on a DIFFERENT Agent branch — must never appear in this Sub's queue. */
    private function secondBranchDeliveredOrder(): int
    {
        $agen2 = User::factory()->agen()->create();
        $agen2->update(['agent_id' => $agen2->id]);
        AgentProfile::create(['user_id' => $agen2->id, 'store_name' => 'T2', 'address' => 'Jl. T2', 'latitude' => -6.2, 'longitude' => 106.8]);
        $konsumen2 = User::factory()->konsumen()->create(['agent_id' => $agen2->id]);
        $product2 = Product::create(['sku' => 'X-'.Str::uuid(), 'name' => 'Other Cake', 'slug' => 'other-'.uniqid(), 'has_variations' => false, 'base_price' => 10000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen2->id, 'product_id' => $product2->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);

        $id = $this->actingAs($konsumen2)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $product2->id, 'quantity' => 1]],
                'recipient_name' => 'Other Buyer', 'recipient_phone' => '0812', 'address_line' => 'Jl. Other',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated()->json('data.id');

        Order::withoutGlobalScopes()->whereKey($id)->update(['status' => 'terkirim']);
        OrderItem::query()->where('order_id', $id)->update(['status' => 'terkirim']);

        return $id;
    }
}
