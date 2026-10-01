<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use App\Models\ShippingConfiguration;
use App\Models\StockMovement;
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
 * R-03 / decision F: a Sub-sourced customer return restocks the ORIGINAL Sub Location / Sub domain,
 * never Agent Transit. Damaged units never become sellable Sub stock; an unusable location is an
 * explicit rejection, never a silent redirect.
 */
class SubReturnDomainTest extends TestCase
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
        $gudang = User::factory()->gudang()->create(['agent_id' => $agen->id]);
        $sub = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id, 'sales_id' => $sub->id]);

        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true]);
        $product = Product::create(['sku' => 'SUB-'.Str::uuid(), 'name' => 'Kue Sub', 'slug' => 'kue-sub-'.uniqid(), 'has_variations' => false, 'base_price' => 50000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 20]);
        $location = WarehouseSubLocation::create(['agent_id' => $agen->id, 'code' => 'L1', 'name' => 'L1', 'created_by' => $agen->id]);
        $location->forceFill(['owner_user_id' => $sub->id])->save();
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $location->id, 'quantity' => 10]);

        $this->b = compact('agen', 'admin', 'gudang', 'sub', 'konsumen', 'location', 'product');
    }

    private function subPhysical(): int
    {
        return (int) WarehouseStock::withoutGlobalScopes()->where('stock_type', 'sub')->where('sub_location_id', $this->b['location']->id)->where('product_id', $this->b['product']->id)->value('quantity');
    }

    private function transit(): int
    {
        return (int) WarehouseStock::withoutGlobalScopes()->where('stock_type', 'transit')->where('agent_id', $this->b['agen']->id)->where('product_id', $this->b['product']->id)->value('quantity');
    }

    private function deliveredSubItem(int $qty): OrderItem
    {
        $id = $this->actingAs($this->b['sub'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod', 'stock_source' => 'sub', 'konsumen_id' => $this->b['konsumen']->id,
                'items' => [['product_id' => $this->b['product']->id, 'quantity' => $qty]],
                'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated()->json('data.id');

        $item = OrderItem::where('order_id', $id)->firstOrFail();
        $shipment = $item->shipment;
        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'dikirim', $this->b['sub']);
        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'terkirim', $this->b['sub'], UploadedFile::fake()->image('proof.jpg'));

        return $item->fresh();
    }

    private function requestReturn(OrderItem $item, int $qty): ReturnItem
    {
        $order = Order::withoutGlobalScopes()->findOrFail($item->order_id);
        $this->actingAs($this->b['konsumen'])
            ->postJson("/api/v1/orders/{$order->id}/returns", [
                'reason' => 'rusak',
                'items' => [['order_item_id' => $item->id, 'quantity' => $qty, 'restock' => true]],
                'evidence' => UploadedFile::fake()->image('evidence.jpg'),
            ])->assertCreated();

        $return = ReturnRequest::query()->latest('id')->firstOrFail();
        $this->actingAs($this->b['admin'])
            ->patchJson("/api/v1/admin/returns/{$return->id}/review", ['approved' => true])
            ->assertOk();

        return ReturnItem::query()->latest('id')->firstOrFail();
    }

    public function test_good_return_restocks_the_original_sub_location_not_agent_transit(): void
    {
        $item = $this->deliveredSubItem(2);
        $this->assertSame(8, $this->subPhysical());
        $transitBefore = $this->transit();

        $returnItem = $this->requestReturn($item, 2);

        $this->actingAs($this->b['gudang'])
            ->postJson("/api/v1/warehouse/returns/{$returnItem->id}/inspect", ['received_quantity' => 2, 'good_quantity' => 2, 'damaged_quantity' => 0])
            ->assertOk();

        $this->actingAs($this->b['admin'])
            ->postJson("/api/v1/warehouse/returns/{$returnItem->id}/finalize")
            ->assertOk();

        $this->assertSame(10, $this->subPhysical(), 'good units return to the original Sub Location');
        $this->assertSame($transitBefore, $this->transit(), 'Agent Transit is never touched by a Sub return');

        $movement = StockMovement::withoutGlobalScopes()->where('stock_type', 'sub')->where('type', 'return_restock')->firstOrFail();
        $this->assertSame(2, (int) $movement->quantity);
        $this->assertSame($this->b['location']->id, (int) $movement->sub_location_id);
        $this->assertSame('App\\Models\\ReturnItem', $movement->reference_type);
        $this->assertSame($returnItem->id, (int) $movement->reference_id);
    }

    public function test_damaged_units_are_not_restocked_as_sellable_sub_stock(): void
    {
        $item = $this->deliveredSubItem(2);
        $returnItem = $this->requestReturn($item, 2);

        $this->actingAs($this->b['gudang'])
            ->postJson("/api/v1/warehouse/returns/{$returnItem->id}/inspect", ['received_quantity' => 2, 'good_quantity' => 1, 'damaged_quantity' => 1])
            ->assertOk();
        $this->actingAs($this->b['admin'])
            ->postJson("/api/v1/warehouse/returns/{$returnItem->id}/finalize")
            ->assertOk();

        // Only the good unit: 8 -> 9.
        $this->assertSame(9, $this->subPhysical());
    }

    public function test_return_is_rejected_when_the_original_sub_location_is_inactive(): void
    {
        $item = $this->deliveredSubItem(2);
        $returnItem = $this->requestReturn($item, 2);

        $this->b['location']->forceFill(['is_active' => false])->save();

        $this->actingAs($this->b['gudang'])
            ->postJson("/api/v1/warehouse/returns/{$returnItem->id}/inspect", ['received_quantity' => 2, 'good_quantity' => 2, 'damaged_quantity' => 0])
            ->assertOk();
        $this->actingAs($this->b['admin'])
            ->postJson("/api/v1/warehouse/returns/{$returnItem->id}/finalize")
            ->assertUnprocessable();

        $this->assertSame(8, $this->subPhysical(), 'inventory is never silently redirected');
        $this->assertSame(20, $this->transit());
    }

    /* ---------------- MAJOR-5: return capacity uses the active quantity ---------------- */

    public function test_return_capacity_reflects_active_quantity_after_a_pre_shipment_cancellation(): void
    {
        $id = $this->actingAs($this->b['sub'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod', 'stock_source' => 'sub', 'konsumen_id' => $this->b['konsumen']->id,
                'items' => [['product_id' => $this->b['product']->id, 'quantity' => 3]],
                'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated()->json('data.id');

        $item = OrderItem::where('order_id', $id)->firstOrFail();
        app(OrderFulfillmentService::class)->adjustItemQuantity($item, 2, $this->b['admin'], 'kurang');
        $item->refresh();
        $this->assertSame(2, $item->fulfilled_quantity);
        $this->assertSame(1, $item->cancelled_quantity);

        $shipment = $item->shipment;
        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'dikirim', $this->b['sub']);
        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'terkirim', $this->b['sub'], UploadedFile::fake()->image('proof.jpg'));

        // Active delivered quantity is 2 — BOTH units may be returned (the old formula allowed only 1).
        $this->actingAs($this->b['konsumen'])
            ->postJson("/api/v1/orders/{$id}/returns", [
                'reason' => 'rusak',
                'items' => [['order_item_id' => $item->id, 'quantity' => 2, 'restock' => true]],
                'evidence' => UploadedFile::fake()->image('evidence.jpg'),
            ])->assertCreated();
    }
}
