<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockRequest;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function fixture(int $transit = 100, int $reserved = 10): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $admin = User::factory()->admin()->create(['agent_id' => $agent->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        $product = Product::create(['sku' => 'REQ-'.uniqid(), 'name' => 'Request Cake', 'slug' => 'request-'.uniqid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
        $order = Order::create(['order_no' => 'REQ-ORDER-'.uniqid(), 'konsumen_id' => $konsumen->id, 'agent_id' => $agent->id, 'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'), 'status' => 'diterima', 'payment_status' => 'unpaid', 'subtotal_amount' => 10000, 'total_amount' => 10000, 'recipient_name_snapshot' => 'Test', 'recipient_phone_snapshot' => '0811', 'address_snapshot' => 'Test']);
        $item = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku, 'unit_price_snapshot' => 1000, 'subtotal_snapshot' => 10000, 'original_quantity' => 10, 'fulfilled_quantity' => 0, 'status' => 'diterima']);
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => $reserved]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => $transit]);
        WarehouseSetting::create(['agent_id' => $agent->id, 'factory_plan_enabled' => false]);

        return compact('agent', 'admin', 'gudang', 'product', 'order', 'item');
    }

    public function test_first_diproses_transition_creates_one_request_and_repeated_handling_is_idempotent(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['admin'])->patchJson("/api/v1/orders/{$f['order']->id}/status", ['status' => 'diproses'])->assertOk();
        $this->assertDatabaseCount('stock_requests', 1);
        $this->actingAs($f['gudang'])->getJson('/api/v1/warehouse/stock-requests')->assertNotFound();
        $this->actingAs($f['gudang'])->getJson('/api/v1/warehouse/orders/diproses')->assertOk()->assertJsonPath('data.0.id', $f['order']->id);
        $request = StockRequest::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(10, $request->items()->firstOrFail()->requested_qty);
        $this->assertDatabaseCount('stock_requests', 1);
    }

    public function test_direct_gudang_fulfill_is_closed_in_favor_of_admin_approval(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['admin'])->patchJson("/api/v1/orders/{$f['order']->id}/status", ['status' => 'diproses'])->assertOk();
        $request = StockRequest::withoutGlobalScopes()->firstOrFail();
        $item = $request->items()->firstOrFail();
        $this->actingAs($f['gudang'])->postJson("/api/v1/warehouse/stock-requests/{$request->id}/fulfill", ['idempotency_key' => 'fulfill-1', 'items' => [['item_id' => $item->id, 'quantity' => 6]]])->assertNotFound();
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $f['product']->id, 'stock_type' => 'transit', 'quantity' => 100]);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->actingAs($f['gudang'])->postJson("/api/v1/warehouse/stock-requests/{$request->id}/proposals", ['items' => [['item_id' => $item->id, 'quantity' => 6]]])->assertNotFound();
        $this->assertSame(10, $request->items()->firstOrFail()->remaining_qty);
    }

    public function test_direct_fulfill_cannot_use_plan_and_proposal_needs_physical_transit(): void
    {
        $f = $this->fixture(0, 10);
        WarehouseStock::create(['agent_id' => $f['agent']->id, 'product_id' => $f['product']->id, 'stock_type' => 'factory_plan', 'quantity' => 10]);
        WarehouseSetting::query()->where('agent_id', $f['agent']->id)->update(['factory_plan_enabled' => true]);
        $this->actingAs($f['admin'])->patchJson("/api/v1/orders/{$f['order']->id}/status", ['status' => 'diproses']);
        $request = StockRequest::withoutGlobalScopes()->firstOrFail();
        $item = $request->items()->firstOrFail();
        $this->actingAs($f['gudang'])->postJson("/api/v1/warehouse/stock-requests/{$request->id}/fulfill", ['idempotency_key' => 'same-key', 'items' => [['item_id' => $item->id, 'quantity' => 1]]])->assertNotFound();
        $this->assertDatabaseHas('stock_requests', ['id' => $request->id, 'status' => 'pending']);
        $this->actingAs($f['gudang'])->postJson("/api/v1/warehouse/stock-requests/{$request->id}/proposals", ['items' => [['item_id' => $item->id, 'quantity' => 1]]])->assertNotFound();
        $this->assertDatabaseHas('warehouse_stocks', ['product_id' => $f['product']->id, 'stock_type' => 'factory_plan', 'quantity' => 10]);
    }
}
