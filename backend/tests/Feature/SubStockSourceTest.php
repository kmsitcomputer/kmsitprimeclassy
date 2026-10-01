<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\AgentProfile;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ShippingConfiguration;
use App\Models\StockMovement;
use App\Models\SubStockReservation;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use App\Services\Order\CourierService;
use App\Services\Order\OrderFulfillmentService;
use App\Services\Stock\SellableStockService;
use App\Services\Stock\SubStockService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * R-02 — Sub stock ownership, stock-source selection and the reserve -> consume / release lifecycle.
 * Agent stock is warehouse Transit (20, reserved via product_stocks.quantity_reserved); Sub stock is a warehouse_stocks(sub) row (10).
 */
class SubStockSourceTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        $this->b = $this->branch();
    }

    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Toko', 'address' => 'Jl. X', 'latitude' => -6.2, 'longitude' => 106.8]);
        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $sub = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'parent_id' => $korsal->id, 'referral_code' => 'SS-'.strtoupper(Str::random(6))]);
        $otherSub = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'parent_id' => $korsal->id, 'referral_code' => 'SS-'.strtoupper(Str::random(6))]);
        $sales = User::factory()->sales()->create(['agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'parent_id' => $korsal->id, 'referral_code' => 'SA-'.strtoupper(Str::random(6))]);
        $referred = User::factory()->konsumen()->create(['agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'sales_id' => $sub->id, 'parent_id' => $sub->id]);
        $salesConsumer = User::factory()->konsumen()->create(['agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'sales_id' => $sales->id, 'parent_id' => $sales->id]);
        foreach ([$sub, $otherSub] as $user) {
            Courier::create(['type' => 'internal', 'user_id' => $user->id, 'agent_id' => $agen->id, 'name' => $user->name, 'is_active' => true]);
        }
        $location = $this->location($agen, $sub, 'L1');
        $otherLocation = $this->location($agen, $otherSub, 'L2');
        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true]);
        $product = Product::create(['sku' => 'SUB-'.Str::uuid(), 'name' => 'Kue Sub', 'slug' => 'kue-sub-'.uniqid(), 'has_variations' => false, 'base_price' => 50000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 20]);
        foreach ([$location, $otherLocation] as $loc) {
            WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $loc->id, 'quantity' => 10]);
        }

        return compact('agen', 'korsal', 'sub', 'otherSub', 'sales', 'referred', 'salesConsumer', 'location', 'otherLocation', 'product');
    }

    private function location(User $agen, User $owner, string $code): WarehouseSubLocation
    {
        $location = WarehouseSubLocation::create(['agent_id' => $agen->id, 'code' => $code, 'name' => $code, 'created_by' => $agen->id]);
        $location->forceFill(['owner_user_id' => $owner->id])->save();

        return $location;
    }

    private function payload(int $qty = 3, ?int $konsumenId = null, array $extra = []): array
    {
        return [
            'payment_method_code' => 'cod',
            ...($konsumenId ? ['konsumen_id' => $konsumenId] : []),
            'items' => [['product_id' => $this->b['product']->id, 'quantity' => $qty]],
            'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ] + $extra;
    }

    private function order(User $actor, array $payload, ?string $key = null)
    {
        return $this->actingAs($actor)->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()])->postJson('/api/v1/orders', $payload);
    }

    private function subPhysical(?WarehouseSubLocation $loc = null): int
    {
        return app(SubStockService::class)->physical(($loc ?? $this->b['location'])->id, $this->b['product']->id, null);
    }

    private function agentReserved(): int
    {
        return (int) ProductStock::withoutGlobalScopes()->where('agent_id', $this->b['agen']->id)->value('quantity_reserved');
    }

    public function test_sub_reservation_is_created_without_decrementing_physical_and_sellable_is_physical_minus_reserved(): void
    {
        $id = $this->order($this->b['sub'], $this->payload(3, null, ['stock_source' => 'sub']))->assertCreated()->json('data.id');

        $item = OrderItem::query()->where('order_id', $id)->firstOrFail();
        $this->assertSame('sub', $item->stock_source);
        $this->assertSame($this->b['location']->id, $item->sub_location_id);
        $this->assertDatabaseHas('sub_stock_reservations', ['order_item_id' => $item->id, 'sub_location_id' => $this->b['location']->id, 'quantity' => 3, 'status' => 'active']);
        $this->assertSame(10, $this->subPhysical(), 'Sub physical is unchanged at order creation');
        $this->assertSame(['physical' => 10, 'reserved' => 3, 'sellable' => 7], app(SubStockService::class)->sellable($this->b['location']->id, $this->b['product']->id, null));
        $this->assertSame(0, $this->agentReserved(), 'Agent Reserved is not touched by a Sub reservation');
        $this->assertDatabaseCount('stock_requests', 0);
    }

    public function test_sales_kurir_sub_creating_an_own_referral_consumer_order_can_use_own_sub(): void
    {
        $this->order($this->b['sub'], $this->payload(2, $this->b['referred']->id, ['stock_source' => 'sub']))->assertCreated();

        $item = OrderItem::query()->firstOrFail();
        $this->assertSame('sub', $item->stock_source);
        $this->assertSame($this->b['location']->id, $item->sub_location_id);
    }

    public function test_referred_consumer_self_checkout_uses_agent_stock_and_cannot_request_sub(): void
    {
        $this->order($this->b['referred'], $this->payload(2, null, ['stock_source' => 'sub']))->assertForbidden();
        $this->assertSame(0, OrderItem::query()->count());
        $this->assertSame(0, SubStockReservation::query()->count());

        $this->order($this->b['referred'], $this->payload(2))->assertCreated();
        $item = OrderItem::query()->firstOrFail();
        $this->assertSame('agent', $item->stock_source);
        $this->assertNull($item->sub_location_id);
        $this->assertSame(2, $this->agentReserved());
        $this->assertSame(10, $this->subPhysical());
        $this->assertSame(0, SubStockReservation::query()->count());
    }

    public function test_default_agent_checkout_for_a_sales_kurir_sub_keeps_working_on_agent_stock(): void
    {
        $this->order($this->b['sub'], $this->payload(2))->assertCreated();

        $this->assertSame('agent', OrderItem::query()->firstOrFail()->stock_source);
        $this->assertSame(2, $this->agentReserved());
        $this->assertSame(0, SubStockReservation::query()->count());
    }

    public function test_other_actors_can_never_consume_another_users_sub_stock(): void
    {
        // normal Sales, Korsal, Agen: not Sales-Kurir-Sub
        foreach (['sales', 'korsal', 'agen'] as $role) {
            $this->order($this->b[$role], $this->payload(1, null, ['stock_source' => 'sub']))->assertForbidden();
        }
        // another Sales-Kurir-Sub: buying for the first Sub's referred consumer, or naming the first Sub's location
        $this->order($this->b['otherSub'], $this->payload(1, $this->b['referred']->id, ['stock_source' => 'sub']))->assertForbidden();
        $this->order($this->b['otherSub'], $this->payload(1, null, ['stock_source' => 'sub', 'sub_location_id' => $this->b['location']->id]))->assertForbidden();
        // forged id even for the owner
        $this->order($this->b['sub'], $this->payload(1, null, ['stock_source' => 'sub', 'sub_location_id' => $this->b['otherLocation']->id]))->assertForbidden();
        // a sales consumer is not in the Sub's referral network
        $this->order($this->b['sub'], $this->payload(1, $this->b['salesConsumer']->id, ['stock_source' => 'sub']))->assertStatus(403);
        // sub_location_id has no meaning for Agent stock
        $this->order($this->b['sub'], $this->payload(1, null, ['stock_source' => 'agent', 'sub_location_id' => $this->b['location']->id]))->assertUnprocessable();

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, SubStockReservation::query()->count());
        $this->assertSame(10, $this->subPhysical());
        $this->assertSame(10, $this->subPhysical($this->b['otherLocation']));
    }

    public function test_cross_agent_sales_kurir_sub_cannot_use_a_foreign_location(): void
    {
        $other = $this->branch();
        $this->order($other['sub'], $this->payload(1, null, ['stock_source' => 'sub', 'sub_location_id' => $this->b['location']->id]))->assertForbidden();
        $this->assertSame(10, $this->subPhysical());
    }

    public function test_sub_order_cannot_exceed_sellable_and_reservations_accumulate(): void
    {
        $this->order($this->b['sub'], $this->payload(8, null, ['stock_source' => 'sub']))->assertCreated();
        $this->order($this->b['sub'], $this->payload(3, null, ['stock_source' => 'sub']))->assertUnprocessable();
        $this->order($this->b['sub'], $this->payload(2, null, ['stock_source' => 'sub']))->assertCreated();

        $this->assertSame(2, Order::query()->count());
        $this->assertSame(10, app(SubStockService::class)->reserved($this->b['location']->id, $this->b['product']->id, null));
        $this->assertSame(10, $this->subPhysical());
    }

    public function test_retrying_the_same_idempotency_key_never_double_reserves(): void
    {
        $key = (string) Str::uuid();
        $first = $this->order($this->b['sub'], $this->payload(4, null, ['stock_source' => 'sub']), $key)->assertCreated()->json('data.id');
        $second = $this->order($this->b['sub'], $this->payload(4, null, ['stock_source' => 'sub']), $key)->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, SubStockReservation::query()->count());
        $this->assertSame(4, app(SubStockService::class)->reserved($this->b['location']->id, $this->b['product']->id, null));
        // service-level retry is a no-op too
        $item = OrderItem::query()->firstOrFail();
        app(SubStockService::class)->reserve($item, $this->b['location']->id, $this->b['agen']->id, 4, $this->b['sub']);
        $this->assertSame(1, SubStockReservation::query()->count());
    }

    private function shipAsOwner(Order $order, ?User $actor = null): void
    {
        $shipment = $order->items()->firstOrFail()->shipment;
        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'dikirim', $actor ?? $this->b['sub']);
    }

    public function test_shipment_consumes_sub_physical_exactly_once_and_writes_a_movement(): void
    {
        $id = $this->order($this->b['sub'], $this->payload(3, null, ['stock_source' => 'sub']))->assertCreated()->json('data.id');
        $order = Order::query()->findOrFail($id);

        $this->shipAsOwner($order);

        $this->assertSame(7, $this->subPhysical());
        $this->assertSame(0, app(SubStockService::class)->reserved($this->b['location']->id, $this->b['product']->id, null));
        $this->assertSame('consumed', SubStockReservation::query()->firstOrFail()->status);
        $movements = StockMovement::query()->where('stock_type', 'sub')->where('type', 'out')->get();
        $this->assertCount(1, $movements);
        $this->assertSame(-3, (int) $movements[0]->quantity);
        $this->assertSame($this->b['location']->id, (int) $movements[0]->sub_location_id);

        // duplicate consume (retry) cannot decrement again
        app(SubStockService::class)->consume(OrderItem::query()->firstOrFail(), $this->b['sub']);
        $this->assertSame(7, $this->subPhysical());
        $this->assertSame(1, StockMovement::query()->where('stock_type', 'sub')->where('type', 'out')->count());
        $this->assertSame(0, $this->agentReserved());
    }

    public function test_another_courier_cannot_ship_sub_goods(): void
    {
        $id = $this->order($this->b['sub'], $this->payload(3, null, ['stock_source' => 'sub']))->assertCreated()->json('data.id');
        $order = Order::query()->findOrFail($id);
        $shipment = $order->items()->firstOrFail()->shipment;

        $this->expectException(ApiException::class);
        try {
            app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'dikirim', $this->b['otherSub']);
        } finally {
            $this->assertSame(10, $this->subPhysical());
            $this->assertSame('active', SubStockReservation::query()->firstOrFail()->status);
        }
    }

    public function test_admin_cannot_ship_sub_goods_via_the_shipment_endpoint(): void
    {
        $id = $this->order($this->b['sub'], $this->payload(3, null, ['stock_source' => 'sub']))->assertCreated()->json('data.id');
        $order = Order::query()->findOrFail($id);
        $shipment = $order->items()->firstOrFail()->shipment;

        $this->expectException(ApiException::class);
        try {
            app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'dikirim', $this->b['agen']);
        } finally {
            $this->assertSame(10, $this->subPhysical());
            $this->assertSame('active', SubStockReservation::query()->firstOrFail()->status);
        }
    }

    public function test_admin_cannot_ship_sub_goods_via_the_generic_order_status_endpoint(): void
    {
        $id = $this->order($this->b['sub'], $this->payload(3, null, ['stock_source' => 'sub']))->assertCreated()->json('data.id');
        $order = Order::query()->findOrFail($id);
        $order->update(['status' => 'diproses']);
        $order->items()->update(['status' => 'diproses']);

        $this->expectException(ApiException::class);
        try {
            app(\App\Services\Order\OrderService::class)->updateStatus($order->fresh(), 'dikirim', $this->b['agen']);
        } finally {
            $this->assertSame(10, $this->subPhysical());
            $this->assertSame('active', SubStockReservation::query()->firstOrFail()->status);
            $this->assertSame('diproses', OrderItem::query()->firstOrFail()->status);
        }
    }

    public function test_cancellation_releases_the_reservation_and_leaves_physical_unchanged(): void
    {
        $id = $this->order($this->b['sub'], $this->payload(3, null, ['stock_source' => 'sub']))->assertCreated()->json('data.id');

        $this->actingAs($this->b['agen'])->postJson("/api/v1/orders/{$id}/cancel", ['reason' => 'salah pesan'])->assertOk();

        $reservation = SubStockReservation::query()->firstOrFail();
        $this->assertSame('released', $reservation->status);
        $this->assertSame(10, $this->subPhysical());
        $this->assertSame(10, app(SubStockService::class)->sellable($this->b['location']->id, $this->b['product']->id, null)['sellable']);
        $this->assertSame(0, StockMovement::query()->where('stock_type', 'sub')->count());
        $this->assertSame(0, $this->agentReserved());
        // idempotent
        app(SubStockService::class)->release(OrderItem::query()->firstOrFail(), $this->b['sub'], 'again');
        $this->assertSame('released', $reservation->fresh()->status);
    }

    public function test_agent_sellable_formula_never_subtracts_sub_stock_again(): void
    {
        $agent = $this->b['agen'];
        $product = $this->b['product'];
        $this->order($this->b['sub'], $this->payload(4, null, ['stock_source' => 'sub']))->assertCreated();
        // Warehouse-authoritative Agent: Transit 40 + Plan 5 (plan enabled), Agent Reserved 6, Sub 30 (already moved out of Transit).
        ProductStock::withoutGlobalScopes()->where('agent_id', $agent->id)->update(['quantity_on_hand' => 0, 'quantity_reserved' => 6]);
        WarehouseStock::withoutGlobalScopes()->where('agent_id', $agent->id)->where('stock_type', 'transit')->update(['quantity' => 40]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'factory_plan', 'quantity' => 5]);
        WarehouseSetting::create(['agent_id' => $agent->id, 'factory_plan_enabled' => true]);
        WarehouseStock::withoutGlobalScopes()->where('sub_location_id', $this->b['location']->id)->update(['quantity' => 30]);

        $sellable = app(SellableStockService::class)->forProduct($agent->id, $product->id);

        $this->assertSame(40 + 5 - 6, $sellable['available'], 'Agent Sellable = Transit + Plan - Agent Reserved (Sub is NOT subtracted again)');
        $this->assertNotSame(40 + 5 - 6 - 30, $sellable['available']);
    }

    public function test_stock_source_consistency_is_enforced_by_the_database(): void
    {
        $id = $this->order($this->b['referred'], $this->payload(1))->assertCreated()->json('data.id');

        $this->expectException(QueryException::class);
        OrderItem::query()->where('order_id', $id)->update(['stock_source' => 'sub', 'sub_location_id' => null]);
    }

    public function test_sub_item_quantity_reduction_releases_reservation_and_leaves_physical_unchanged(): void
    {
        $id = $this->order($this->b['sub'], $this->payload(3, null, ['stock_source' => 'sub']))->assertCreated()->json('data.id');
        $item = OrderItem::query()->where('order_id', $id)->firstOrFail();

        app(OrderFulfillmentService::class)->adjustItemQuantity($item, 2, $this->b['agen'], 'test');

        $this->assertSame(2, $item->fresh()->fulfilled_quantity);
        $this->assertSame(1, $item->fresh()->cancelled_quantity);
        $this->assertSame(10, $this->subPhysical(), 'Sub physical is unchanged by a pre-shipment reduction');
        $this->assertSame(2, app(SubStockService::class)->reserved($this->b['location']->id, $this->b['product']->id, null));
    }

    public function test_sub_physical_cannot_be_cut_below_active_reservations(): void
    {
        $this->order($this->b['sub'], $this->payload(8, null, ['stock_source' => 'sub']))->assertCreated();

        $this->expectException(ApiException::class);
        app(SubStockService::class)->assertPhysicalDecreaseAllowed($this->b['location']->id, $this->b['product']->id, null, 10, 5);
    }
}
