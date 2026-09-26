<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Models\User;
use App\Models\WarehouseStock;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryCancellationReturnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function fixture(int $fulfilled = 0): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $admin = User::factory()->admin()->create(['agent_id' => $agent->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        $product = Product::create(['sku' => 'G-'.uniqid(), 'name' => 'G Cake', 'slug' => 'g-'.uniqid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
        $order = Order::create(['order_no' => 'G-ORDER-'.uniqid(), 'konsumen_id' => $konsumen->id, 'agent_id' => $agent->id, 'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'), 'status' => 'diproses', 'payment_status' => 'unpaid', 'subtotal_amount' => 10000, 'total_amount' => 10000, 'recipient_name_snapshot' => 'G', 'recipient_phone_snapshot' => '0811', 'address_snapshot' => 'G']);
        $item = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku, 'unit_price_snapshot' => 1000, 'subtotal_snapshot' => 10000, 'original_quantity' => 10, 'fulfilled_quantity' => $fulfilled, 'status' => 'diproses']);
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 10 - $fulfilled]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 100 - $fulfilled]);
        if ($fulfilled) {
            WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'shipping', 'quantity' => $fulfilled]);
        }
        $request = StockRequest::create(['agent_id' => $agent->id, 'order_id' => $order->id, 'request_number' => 'SR-G-'.uniqid(), 'status' => $fulfilled === 10 ? 'fulfilled' : ($fulfilled ? 'partial' : 'pending')]);
        StockRequestItem::create(['stock_request_id' => $request->id, 'order_item_id' => $item->id, 'product_id' => $product->id, 'sku_snapshot' => $product->sku, 'requested_qty' => 10, 'fulfilled_qty' => $fulfilled, 'remaining_qty' => 10 - $fulfilled]);

        return compact('agent', 'admin', 'konsumen', 'product', 'order', 'item', 'request');
    }

    public function test_unfulfilled_cancellation_releases_reservation_without_physical_restock_and_is_idempotent(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['admin'])->postJson("/api/v1/orders/{$f['order']->id}/cancel", ['reason' => 'Customer cancel'])->assertOk();
        $this->assertDatabaseHas('product_stocks', ['product_id' => $f['product']->id, 'quantity_reserved' => 0]);
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $f['product']->id, 'stock_type' => 'transit', 'quantity' => 100]);
        $this->assertDatabaseHas('stock_requests', ['id' => $f['request']->id, 'status' => 'cancelled']);
        $this->assertDatabaseCount('inventory_cancellation_reversals', 1);
        $this->actingAs($f['admin'])->postJson("/api/v1/orders/{$f['order']->id}/cancel", ['reason' => 'Retry'])->assertStatus(422);
        $this->assertDatabaseCount('inventory_cancellation_reversals', 1);
    }

    public function test_fulfilled_cancellation_reverses_shipping_to_transit_without_releasing_again(): void
    {
        $f = $this->fixture(6);
        $this->actingAs($f['admin'])->postJson("/api/v1/orders/{$f['order']->id}/cancel", ['reason' => 'Before dispatch'])->assertOk();
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $f['product']->id, 'stock_type' => 'transit', 'quantity' => 100]);
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $f['product']->id, 'stock_type' => 'shipping', 'quantity' => 0]);
        $this->assertDatabaseHas('product_stocks', ['product_id' => $f['product']->id, 'quantity_reserved' => 0]);
        $this->assertDatabaseHas('stock_movements', ['type' => 'cancellation_release', 'stock_type' => 'shipping', 'quantity' => -6]);
        $this->assertDatabaseHas('stock_movements', ['type' => 'cancellation_release', 'stock_type' => 'transit', 'quantity' => 6]);
    }

    public function test_good_return_restock_requires_inspection_and_damaged_return_does_not_enter_transit(): void
    {
        $f = $this->fixture();
        $f['item']->update(['status' => 'terkirim', 'fulfilled_quantity' => 4]);
        $return = ReturnRequest::create(['order_id' => $f['order']->id, 'requested_by' => $f['konsumen']->id, 'reason' => 'return', 'status' => 'approved']);
        $item = ReturnItem::create(['return_id' => $return->id, 'order_item_id' => $f['item']->id, 'quantity_returned' => 4, 'refund_amount' => 4000, 'status' => 'approved', 'refund_status' => 'pending']);
        $this->actingAs($f['admin'])->postJson("/api/v1/warehouse/returns/{$item->id}/inspect", ['received_quantity' => 4, 'good_quantity' => 3, 'damaged_quantity' => 1])->assertForbidden();
        $gudang = User::factory()->gudang()->create(['agent_id' => $f['agent']->id, 'parent_id' => $f['agent']->id]);
        $this->actingAs($gudang)->postJson("/api/v1/warehouse/returns/{$item->id}/inspect", ['received_quantity' => 4, 'good_quantity' => 3, 'damaged_quantity' => 1])->assertOk();
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $f['product']->id, 'stock_type' => 'transit', 'quantity' => 100]);
        $this->assertDatabaseHas('return_items', ['id' => $item->id, 'good_quantity' => 3, 'damaged_quantity' => 1, 'condition_status' => 'mixed', 'disposition_status' => 'pending_disposition']);
        $this->assertDatabaseMissing('stock_movements', ['type' => 'return_restock']);
        $this->actingAs($f['admin'])->postJson("/api/v1/warehouse/returns/{$item->id}/finalize")->assertOk();
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $f['product']->id, 'stock_type' => 'transit', 'quantity' => 103]);
        $this->assertDatabaseHas('stock_movements', ['type' => 'return_restock', 'quantity' => 3]);
        $this->actingAs($gudang)->postJson("/api/v1/warehouse/returns/{$item->id}/inspect", ['received_quantity' => 4, 'good_quantity' => 3, 'damaged_quantity' => 1])->assertUnprocessable();
        $this->actingAs($f['admin'])->postJson("/api/v1/warehouse/returns/{$item->id}/finalize")->assertOk();
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $f['product']->id, 'stock_type' => 'transit', 'quantity' => 103]);
        $this->assertDatabaseCount('stock_movements', 1);
    }
}
