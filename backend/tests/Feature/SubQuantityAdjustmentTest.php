<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ShippingConfiguration;
use App\Models\SubStockReservation;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use App\Services\Order\OrderFulfillmentService;
use App\Services\Stock\SubStockService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * R-03 / decision E: safe pre-shipment Sub reservation reconciliation. Physical Sub stock never
 * changes on adjust/split and Agent inventory is never touched.
 */
class SubQuantityAdjustmentTest extends TestCase
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
        $sub = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id]);
        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true]);
        $product = Product::create(['sku' => 'SUB-'.Str::uuid(), 'name' => 'Kue Sub', 'slug' => 'kue-sub-'.uniqid(), 'has_variations' => false, 'base_price' => 50000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 20]);
        $location = WarehouseSubLocation::create(['agent_id' => $agen->id, 'code' => 'L1', 'name' => 'L1', 'created_by' => $agen->id]);
        $location->forceFill(['owner_user_id' => $sub->id])->save();
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $location->id, 'quantity' => 10]);

        return compact('agen', 'sub', 'location', 'product');
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

    private function physical(): int
    {
        return app(SubStockService::class)->physical($this->b['location']->id, $this->b['product']->id, null);
    }

    private function reserved(?OrderItem $item = null): int
    {
        if ($item) {
            return (int) SubStockReservation::query()->where('order_item_id', $item->id)->value('quantity');
        }

        return (int) SubStockReservation::query()->where('status', 'active')->sum('quantity');
    }

    public function test_reduction_shrinks_reservation_and_leaves_physical_and_agent_untouched(): void
    {
        $item = $this->placeSubItem(3);

        app(OrderFulfillmentService::class)->adjustItemQuantity($item, 2, $this->b['agen'], 'stok kurang');

        $this->assertSame(2, $item->fresh()->fulfilled_quantity);
        $this->assertSame(1, $item->fresh()->cancelled_quantity);
        $this->assertSame(10, $this->physical());
        $this->assertSame(2, $this->reserved($item));
        $this->assertSame(0, (int) ProductStock::withoutGlobalScopes()->where('agent_id', $this->b['agen']->id)->value('quantity_reserved'));
        $this->assertSame(2, (int) SubStockReservation::query()->where('order_item_id', $item->id)->value('quantity'));
    }

    public function test_increase_grows_reservation_after_checking_sub_sellable(): void
    {
        $item = $this->placeSubItem(3);

        app(OrderFulfillmentService::class)->adjustItemQuantity($item, 5, $this->b['agen'], 'tambah');

        $this->assertSame(5, $item->fresh()->fulfilled_quantity);
        $this->assertSame(10, $this->physical());
        $this->assertSame(5, $this->reserved($item));
        $this->assertSame(5, app(SubStockService::class)->sellable($this->b['location']->id, $this->b['product']->id, null)['sellable']);
    }

    public function test_increase_beyond_sub_sellable_is_rejected(): void
    {
        $item = $this->placeSubItem(3);

        $this->expectException(ApiException::class);
        try {
            app(OrderFulfillmentService::class)->adjustItemQuantity($item, 20, $this->b['agen'], 'terlalu banyak');
        } finally {
            $this->assertSame(10, $this->physical());
            $this->assertSame(3, $this->reserved($item));
        }
    }

    public function test_partial_split_conserves_sub_reservation_and_preserves_self_delivery(): void
    {
        $item = $this->placeSubItem(3);

        $child = app(OrderFulfillmentService::class)->rescheduleItemDeliveryDate($item, '2026-12-20', $this->b['agen'], 'split', 2);

        $item->refresh();
        $this->assertSame(1, $item->fulfilled_quantity);
        $this->assertSame(2, $child->fulfilled_quantity);
        $this->assertSame(1, $item->original_quantity);
        $this->assertSame($item->id, $child->split_from_order_item_id);
        $this->assertSame('sub', $child->stock_source);
        $this->assertSame($this->b['location']->id, $child->sub_location_id);

        // Reservation conservation: 1 (parent) + 2 (child) = 3 (pre-split).
        $this->assertSame(1, $this->reserved($item));
        $this->assertSame(2, $this->reserved($child));
        $this->assertSame(3, $this->reserved());
        $this->assertSame(10, $this->physical());

        // Child keeps the first-class self-delivery path.
        $shipment = $child->shipment()->first();
        $this->assertSame('self_sub', $shipment->delivery_mode);
        $this->assertSame($this->b['sub']->id, $shipment->self_delivered_by_user_id);
        $this->assertNull($shipment->courier_id);
    }

    public function test_full_reschedule_without_split_does_not_change_the_reservation(): void
    {
        $item = $this->placeSubItem(3);

        app(OrderFulfillmentService::class)->rescheduleItemDeliveryDate($item, '2026-12-20', $this->b['agen'], 'move only');

        $this->assertSame(3, $item->fresh()->fulfilled_quantity);
        $this->assertSame(3, $this->reserved($item));
        $this->assertSame(10, $this->physical());
        $this->assertSame(1, OrderItem::where('order_id', $item->order_id)->count());
    }

    /* ---------------- MAJOR-5: counter reconciliation ---------------- */

    public function test_increase_restores_cancelled_units_before_counting_additional(): void
    {
        $item = $this->placeSubItem(3);

        app(OrderFulfillmentService::class)->adjustItemQuantity($item, 2, $this->b['agen'], 'kurang');
        $item->refresh();
        $this->assertSame(2, $item->fulfilled_quantity);
        $this->assertSame(1, $item->cancelled_quantity);

        // 2 -> 3 is a RESTORE of the cancelled unit: no additional, cancelled back to 0.
        app(OrderFulfillmentService::class)->adjustItemQuantity($item, 3, $this->b['agen'], 'restore');
        $item->refresh();
        $this->assertSame(3, $item->fulfilled_quantity);
        $this->assertSame(0, $item->cancelled_quantity);
        $this->assertSame(0, $item->additional_quantity);
        $this->assertSame(3, $this->reserved($item));
        $this->assertSame(10, $this->physical());

        // 3 -> 4 is a genuinely additional unit.
        app(OrderFulfillmentService::class)->adjustItemQuantity($item, 4, $this->b['agen'], 'extra');
        $item->refresh();
        $this->assertSame(4, $item->fulfilled_quantity);
        $this->assertSame(0, $item->cancelled_quantity);
        $this->assertSame(1, $item->additional_quantity);
        $this->assertSame(4, $this->reserved($item));
        $this->assertSame(10, $this->physical());
    }

    public function test_increase_on_a_fully_cancelled_item_is_rejected_and_does_not_reactivate_the_reservation(): void
    {
        $item = $this->placeSubItem(3);

        app(OrderFulfillmentService::class)->adjustItemQuantity($item, 0, $this->b['agen'], 'cancel all');
        $item->refresh();
        $this->assertSame(0, $item->fulfilled_quantity);
        $this->assertSame(3, $item->cancelled_quantity);
        $this->assertSame('dibatalkan', $item->status);
        $this->assertSame('released', SubStockReservation::query()->where('order_item_id', $item->id)->value('status'));

        try {
            app(OrderFulfillmentService::class)->adjustItemQuantity($item, 1, $this->b['agen'], 'reopen');
            $this->fail('Expected the increase on a fully cancelled item to be rejected.');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status());
        }

        $item->refresh();
        $this->assertSame('dibatalkan', $item->status);
        $this->assertSame(0, $item->fulfilled_quantity);
        $this->assertSame(3, $item->cancelled_quantity);
        $this->assertSame('released', SubStockReservation::query()->where('order_item_id', $item->id)->value('status'));
        $this->assertSame(10, $this->physical());
    }
}
