<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Commission;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductFee;
use App\Models\ProductStock;
use App\Models\ShippingConfiguration;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use App\Services\Order\CourierService;
use App\Services\Order\OrderFulfillmentService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * R-03 / MAJOR-8: the courier fee is earned ON DELIVERY, PER ITEM, so it must follow the active
 * quantity — scaled on reduce/increase and allocated proportionally on a partial split — always
 * from the historical snapshot, never today's catalog config. Agent/Sales commissions are untouched.
 */
class CourierFeeSplitTest extends TestCase
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

        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Toko', 'address' => 'Jl. X', 'latitude' => -6.2, 'longitude' => 106.8]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        $sub = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id]);
        $kurirA = User::factory()->kurir()->create(['agent_id' => $agen->id]);
        $kurirB = User::factory()->kurir()->create(['agent_id' => $agen->id]);
        $courierA = Courier::create(['type' => 'internal', 'user_id' => $kurirA->id, 'agent_id' => $agen->id, 'name' => 'Kurir A', 'is_active' => true]);
        $courierB = Courier::create(['type' => 'internal', 'user_id' => $kurirB->id, 'agent_id' => $agen->id, 'name' => 'Kurir B', 'is_active' => true]);

        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true]);
        $product = Product::create(['sku' => 'CF-'.Str::uuid(), 'name' => 'Courier Fee Cake', 'slug' => 'cf-'.uniqid(), 'has_variations' => false, 'base_price' => 10000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 20, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 30]);
        ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'courier', 'amount' => 10, 'is_active' => true]);

        $location = WarehouseSubLocation::create(['agent_id' => $agen->id, 'code' => 'CF1', 'name' => 'CF1', 'created_by' => $agen->id]);
        $location->forceFill(['owner_user_id' => $sub->id])->save();
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $location->id, 'quantity' => 20]);

        $this->b = compact('agen', 'admin', 'konsumen', 'sub', 'kurirA', 'kurirB', 'courierA', 'courierB', 'location', 'product');
    }

    private function placeAgentItem(int $qty): OrderItem
    {
        $id = $this->actingAs($this->b['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $this->b['product']->id, 'quantity' => $qty]],
                'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated()->json('data.id');

        return OrderItem::where('order_id', $id)->firstOrFail();
    }

    private function placeSubItem(int $qty): OrderItem
    {
        $id = $this->actingAs($this->b['sub'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod', 'stock_source' => 'sub',
                'items' => [['product_id' => $this->b['product']->id, 'quantity' => $qty]],
                'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated()->json('data.id');

        return OrderItem::where('order_id', $id)->firstOrFail();
    }

    private function deliver(OrderItem $item, User $actor): void
    {
        $shipment = $item->shipment()->first();
        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'dikirim', $actor);
        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'terkirim', $actor, UploadedFile::fake()->image('proof.jpg'));
    }

    /**
     * Marks this order's shipments as a KURIR ONLINE delivery (canonical
     * `shipments.shipping_provider_code = 'openroute'`) — the LOCKED Human 2026-10-07
     * precondition for changing a requested delivery date. Written on the canonical field
     * directly: this file seeds no shipping-provider infrastructure.
     */
    private function markCourierOnline(int $orderId): void
    {
        \App\Models\Shipment::query()->where('order_id', $orderId)->update(['shipping_provider_code' => 'openroute']);
    }

    public function test_courier_fee_scales_with_active_quantity_on_reduce_and_increase(): void
    {
        $item = $this->placeAgentItem(3);
        $this->assertEquals(30, (float) $item->courier_fee_amount);

        app(OrderFulfillmentService::class)->adjustItemQuantity($item, 2, $this->b['admin'], 'kurang');
        $this->assertEquals(20, (float) $item->fresh()->courier_fee_amount);

        app(OrderFulfillmentService::class)->adjustItemQuantity($item, 3, $this->b['admin'], 'restore');
        $this->assertEquals(30, (float) $item->fresh()->courier_fee_amount);

        app(OrderFulfillmentService::class)->adjustItemQuantity($item, 4, $this->b['admin'], 'extra');
        $this->assertEquals(40, (float) $item->fresh()->courier_fee_amount);
    }

    public function test_partial_split_allocates_courier_fee_proportionally_and_exactly(): void
    {
        $item = $this->placeAgentItem(3);
        $this->markCourierOnline($item->order_id);
        $child = app(OrderFulfillmentService::class)->rescheduleItemDeliveryDate($item, '2026-12-20', $this->b['admin'], 'split', 2);

        $this->assertEquals(10, (float) $item->fresh()->courier_fee_amount, 'parent keeps the retained share');
        $this->assertEquals(20, (float) $child->fresh()->courier_fee_amount, 'child carries the moved share');
        $this->assertEquals(30, (float) $item->fresh()->courier_fee_amount + (float) $child->fresh()->courier_fee_amount, 'total conserved exactly');
    }

    public function test_two_couriers_each_earn_only_their_own_items_courier_fee(): void
    {
        $item = $this->placeAgentItem(3);
        $this->markCourierOnline($item->order_id);
        $child = app(OrderFulfillmentService::class)->rescheduleItemDeliveryDate($item, '2026-12-20', $this->b['admin'], 'split', 2);

        app(CourierService::class)->assignCourier($item->shipment()->first(), $this->b['courierA'], $this->b['admin']);
        app(CourierService::class)->assignCourier($child->shipment()->first(), $this->b['courierB'], $this->b['admin']);

        $this->deliver($item, $this->b['kurirA']);
        $this->deliver($child, $this->b['kurirB']);

        $parentFee = Commission::query()->where('order_item_id', $item->id)->where('beneficiary_role', 'courier')->get();
        $childFee = Commission::query()->where('order_item_id', $child->id)->where('beneficiary_role', 'courier')->get();

        $this->assertCount(1, $parentFee);
        $this->assertCount(1, $childFee);
        $this->assertSame($this->b['kurirA']->id, $parentFee->first()->beneficiary_user_id);
        $this->assertSame($this->b['kurirB']->id, $childFee->first()->beneficiary_user_id);
        $this->assertEquals(10, (float) $parentFee->first()->amount);
        $this->assertEquals(20, (float) $childFee->first()->amount);
        $this->assertEquals(30, (float) Commission::query()->whereIn('order_item_id', [$item->id, $child->id])->where('beneficiary_role', 'courier')->sum('amount'));

        // Delivery replay must never duplicate a courier commission.
        app(CourierService::class)->recordCommissionsOnDelivery(Order::withoutGlobalScopes()->findOrFail($item->order_id)->fresh());
        app(CourierService::class)->recordCommissionsOnDelivery(Order::withoutGlobalScopes()->findOrFail($item->order_id)->fresh());
        $this->assertSame(1, Commission::query()->where('order_item_id', $item->id)->where('beneficiary_role', 'courier')->count());
        $this->assertSame(1, Commission::query()->where('order_item_id', $child->id)->where('beneficiary_role', 'courier')->count());
    }

    public function test_self_sub_split_credits_the_owner_for_each_delivered_split_item(): void
    {
        $item = $this->placeSubItem(3);
        $this->markCourierOnline($item->order_id);
        $child = app(OrderFulfillmentService::class)->rescheduleItemDeliveryDate($item, '2026-12-20', $this->b['admin'], 'split', 2);

        $this->assertEquals(10, (float) $item->fresh()->courier_fee_amount);
        $this->assertEquals(20, (float) $child->fresh()->courier_fee_amount);

        $this->deliver($item, $this->b['sub']);
        $this->deliver($child, $this->b['sub']);

        $parentFee = Commission::query()->where('order_item_id', $item->id)->where('beneficiary_role', 'courier')->get();
        $childFee = Commission::query()->where('order_item_id', $child->id)->where('beneficiary_role', 'courier')->get();

        $this->assertCount(1, $parentFee);
        $this->assertCount(1, $childFee);
        $this->assertSame($this->b['sub']->id, $parentFee->first()->beneficiary_user_id);
        $this->assertSame($this->b['sub']->id, $childFee->first()->beneficiary_user_id);
        $this->assertEquals(10, (float) $parentFee->first()->amount);
        $this->assertEquals(20, (float) $childFee->first()->amount);
    }
}
