<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariation;
use App\Models\ProductVariationStock;
use App\Models\StockMovement;
use App\Models\StockRequest;
use App\Models\StockRequestFulfillment;
use App\Models\StockRequestProposal;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Services\Stock\SellableStockService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WarehouseFulfillmentProposalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function branch(): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $admin = User::factory()->admin()->create(['agent_id' => $agent->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        $foreign = User::factory()->agen()->create();
        $foreign->update(['agent_id' => $foreign->id]);
        $foreignAdmin = User::factory()->admin()->create(['agent_id' => $foreign->id]);
        $foreignGudang = User::factory()->gudang()->create(['agent_id' => $foreign->id, 'parent_id' => $foreign->id]);

        return compact('agent', 'admin', 'gudang', 'konsumen', 'foreign', 'foreignAdmin', 'foreignGudang');
    }

    private function product(): Product
    {
        return Product::create(['sku' => 'PROP-'.uniqid(), 'name' => 'Proposal Cake', 'slug' => 'proposal-'.uniqid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
    }

    private function order(array $b, Product $product, int $qty = 10): Order
    {
        $order = Order::create(['order_no' => 'PROP-ORDER-'.Str::upper(Str::random(10)), 'konsumen_id' => $b['konsumen']->id, 'agent_id' => $b['agent']->id, 'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'), 'status' => 'diterima', 'payment_status' => 'unpaid', 'subtotal_amount' => $qty * 1000, 'total_amount' => $qty * 1000, 'recipient_name_snapshot' => 'Test', 'recipient_phone_snapshot' => '0811', 'address_snapshot' => 'Test']);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku, 'unit_price_snapshot' => 1000, 'subtotal_snapshot' => $qty * 1000, 'original_quantity' => $qty, 'fulfilled_quantity' => 0, 'status' => 'diterima']);
        ProductStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => $qty]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 20]);
        WarehouseSetting::create(['agent_id' => $b['agent']->id, 'factory_plan_enabled' => false]);
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();

        return $order->fresh();
    }

    private function bucket(int $agentId, int $productId, string $type): int
    {
        return (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('stock_type', $type)->where('product_id', $productId)->sum('quantity');
    }

    public function test_shared_simple_target_processes_every_line_once(): void
    {
        $b = $this->branch();
        $product = $this->product();
        $a = Order::create(['order_no' => 'SH-A-'.Str::upper(Str::random(8)), 'konsumen_id' => $b['konsumen']->id, 'agent_id' => $b['agent']->id, 'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'), 'status' => 'diterima', 'payment_status' => 'unpaid', 'subtotal_amount' => 2000, 'total_amount' => 2000, 'recipient_name_snapshot' => 'T', 'recipient_phone_snapshot' => '0', 'address_snapshot' => 'T']);
        OrderItem::create(['order_id' => $a->id, 'product_id' => $product->id, 'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku, 'unit_price_snapshot' => 1000, 'subtotal_snapshot' => 2000, 'original_quantity' => 2, 'fulfilled_quantity' => 0, 'status' => 'diterima']);
        $c = Order::create(['order_no' => 'SH-B-'.Str::upper(Str::random(8)), 'konsumen_id' => $b['konsumen']->id, 'agent_id' => $b['agent']->id, 'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'), 'status' => 'diterima', 'payment_status' => 'unpaid', 'subtotal_amount' => 3000, 'total_amount' => 3000, 'recipient_name_snapshot' => 'T', 'recipient_phone_snapshot' => '0', 'address_snapshot' => 'T']);
        OrderItem::create(['order_id' => $c->id, 'product_id' => $product->id, 'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku, 'unit_price_snapshot' => 1000, 'subtotal_snapshot' => 3000, 'original_quantity' => 3, 'fulfilled_quantity' => 0, 'status' => 'diterima']);
        ProductStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 5]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 10]);
        WarehouseSetting::create(['agent_id' => $b['agent']->id, 'factory_plan_enabled' => false]);
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$a->id}/status", ['status' => 'diproses'])->assertOk();
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$c->id}/status", ['status' => 'diproses'])->assertOk();

        $requestA = StockRequest::withoutGlobalScopes()->where('order_id', $a->id)->firstOrFail();
        $requestB = StockRequest::withoutGlobalScopes()->where('order_id', $c->id)->firstOrFail();
        $itemA = $requestA->items()->firstOrFail();
        $itemB = $requestB->items()->firstOrFail();
        $this->assertNotSame($itemA->id, $itemB->id);

        $itemB->update(['stock_request_id' => $requestA->id]);
        $requestB->delete();
        $both = $requestA->fresh();
        $this->assertSame(2, $both->items()->count());

        $proposal = $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/stock-requests/{$both->id}/proposals", ['items' => [['item_id' => $itemA->id, 'quantity' => 2], ['item_id' => $itemB->id, 'quantity' => 3]]])->assertCreated()->json('data');
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertSame('approved', StockRequestProposal::withoutGlobalScopes()->findOrFail($proposal['id'])->status);
        $this->assertSame(2, $itemA->fresh()->fulfilled_qty);
        $this->assertSame(0, $itemA->fresh()->remaining_qty);
        $this->assertSame(3, $itemB->fresh()->fulfilled_qty);
        $this->assertSame(0, $itemB->fresh()->remaining_qty);
        $this->assertSame(5, $this->bucket($b['agent']->id, $product->id, 'transit'));
        $this->assertSame(5, $this->bucket($b['agent']->id, $product->id, 'shipping'));
        $this->assertSame(0, (int) ProductStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->value('quantity_reserved'));
        $this->assertSame('fulfilled', $both->fresh()->status);
        $this->assertSame(1, StockRequestFulfillment::withoutGlobalScopes()->where('stock_request_id', $both->id)->count());
        $this->assertSame(-5, (int) StockMovement::withoutGlobalScopes()->where('stock_type', 'transit')->sum('quantity'));
        $this->assertSame(5, (int) StockMovement::withoutGlobalScopes()->where('stock_type', 'shipping')->sum('quantity'));
    }

    public function test_shared_target_aggregate_transit_and_reserved_fail_without_mutation(): void
    {
        $b = $this->branch();
        $product = $this->product();
        $a = Order::create(['order_no' => 'AG-A-'.Str::upper(Str::random(8)), 'konsumen_id' => $b['konsumen']->id, 'agent_id' => $b['agent']->id, 'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'), 'status' => 'diterima', 'payment_status' => 'unpaid', 'subtotal_amount' => 4000, 'total_amount' => 4000, 'recipient_name_snapshot' => 'T', 'recipient_phone_snapshot' => '0', 'address_snapshot' => 'T']);
        OrderItem::create(['order_id' => $a->id, 'product_id' => $product->id, 'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku, 'unit_price_snapshot' => 1000, 'subtotal_snapshot' => 4000, 'original_quantity' => 4, 'fulfilled_quantity' => 0, 'status' => 'diterima']);
        $c = Order::create(['order_no' => 'AG-B-'.Str::upper(Str::random(8)), 'konsumen_id' => $b['konsumen']->id, 'agent_id' => $b['agent']->id, 'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'), 'status' => 'diterima', 'payment_status' => 'unpaid', 'subtotal_amount' => 4000, 'total_amount' => 4000, 'recipient_name_snapshot' => 'T', 'recipient_phone_snapshot' => '0', 'address_snapshot' => 'T']);
        OrderItem::create(['order_id' => $c->id, 'product_id' => $product->id, 'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku, 'unit_price_snapshot' => 1000, 'subtotal_snapshot' => 4000, 'original_quantity' => 4, 'fulfilled_quantity' => 0, 'status' => 'diterima']);
        ProductStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 8]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 6]);
        WarehouseSetting::create(['agent_id' => $b['agent']->id, 'factory_plan_enabled' => false]);
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$a->id}/status", ['status' => 'diproses'])->assertOk();
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$c->id}/status", ['status' => 'diproses'])->assertOk();

        $requestA = StockRequest::withoutGlobalScopes()->where('order_id', $a->id)->firstOrFail();
        $requestB = StockRequest::withoutGlobalScopes()->where('order_id', $c->id)->firstOrFail();
        $itemA = $requestA->items()->firstOrFail();
        $itemB = $requestB->items()->firstOrFail();
        $itemB->update(['stock_request_id' => $requestA->id]);
        $requestB->delete();

        $proposal = $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/stock-requests/{$requestA->id}/proposals", ['items' => [['item_id' => $itemA->id, 'quantity' => 4], ['item_id' => $itemB->id, 'quantity' => 4]]])->assertCreated()->json('data');
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/approve")->assertUnprocessable();

        $this->assertSame('pending', StockRequestProposal::withoutGlobalScopes()->findOrFail($proposal['id'])->status);
        $this->assertSame(6, $this->bucket($b['agent']->id, $product->id, 'transit'));
        $this->assertSame(0, $this->bucket($b['agent']->id, $product->id, 'shipping'));
        $this->assertSame(8, (int) ProductStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->value('quantity_reserved'));
        $this->assertSame(4, $itemA->fresh()->remaining_qty);
        $this->assertSame(4, $itemB->fresh()->remaining_qty);
        $this->assertSame(0, StockRequestFulfillment::withoutGlobalScopes()->count());
        $this->assertSame(0, StockMovement::query()->count());

        WarehouseStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->where('stock_type', 'transit')->update(['quantity' => 10]);
        ProductStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->update(['quantity_reserved' => 6]);
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/approve")->assertUnprocessable();
        $this->assertSame('pending', StockRequestProposal::withoutGlobalScopes()->findOrFail($proposal['id'])->status);
        $this->assertSame(10, $this->bucket($b['agent']->id, $product->id, 'transit'));
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_trigger_creates_one_request_and_pending_proposal_has_zero_effect(): void
    {
        $b = $this->branch();
        $product = $this->product();

        $pre = Order::create(['order_no' => 'PRE-'.Str::upper(Str::random(10)), 'konsumen_id' => $b['konsumen']->id, 'agent_id' => $b['agent']->id, 'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'), 'status' => 'diterima', 'payment_status' => 'unpaid', 'subtotal_amount' => 1000, 'total_amount' => 1000, 'recipient_name_snapshot' => 'T', 'recipient_phone_snapshot' => '0', 'address_snapshot' => 'T']);
        $this->assertDatabaseCount('stock_requests', 0);

        $this->order($b, $product);
        $this->assertDatabaseCount('stock_requests', 1);
        $request = StockRequest::withoutGlobalScopes()->firstOrFail();
        $item = $request->items()->firstOrFail();
        $this->assertSame(10, $item->requested_qty);

        $proposal = $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/stock-requests/{$request->id}/proposals", ['items' => [['item_id' => $item->id, 'quantity' => 6]]])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');

        $this->assertSame(20, $this->bucket($b['agent']->id, $product->id, 'transit'));
        $this->assertSame(0, $this->bucket($b['agent']->id, $product->id, 'shipping'));
        $this->assertSame(10, (int) ProductStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->value('quantity_reserved'));
        $this->assertSame(10, app(SellableStockService::class)->forProduct($b['agent']->id, $product->id)['available']);
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertDatabaseCount('stock_request_fulfillments', 0);
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame($proposal['id'], StockRequestProposal::withoutGlobalScopes()->firstOrFail()->id);
    }

    public function test_approval_moves_buckets_and_keeps_sellable(): void
    {
        $b = $this->branch();
        $product = $this->product();
        $this->order($b, $product);
        $request = StockRequest::withoutGlobalScopes()->firstOrFail();
        $item = $request->items()->firstOrFail();
        $before = app(SellableStockService::class)->forProduct($b['agent']->id, $product->id)['available'];
        $this->assertSame(10, $before);

        $proposal = $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/stock-requests/{$request->id}/proposals", ['items' => [['item_id' => $item->id, 'quantity' => 6]]])->assertCreated()->json('data');
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertSame(14, $this->bucket($b['agent']->id, $product->id, 'transit'));
        $this->assertSame(6, $this->bucket($b['agent']->id, $product->id, 'shipping'));
        $this->assertSame(4, (int) ProductStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->value('quantity_reserved'));
        $this->assertSame(10, app(SellableStockService::class)->forProduct($b['agent']->id, $product->id)['available']);
        $item->refresh();
        $this->assertSame(6, $item->fulfilled_qty);
        $this->assertSame(4, $item->remaining_qty);
        $this->assertSame('partial', $request->fresh()->status);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseHas('stock_movements', ['type' => 'fulfillment', 'stock_type' => 'transit', 'quantity' => -6]);
        $this->assertDatabaseHas('stock_movements', ['type' => 'fulfillment', 'stock_type' => 'shipping', 'quantity' => 6]);
    }

    public function test_partial_then_fulfilled_on_same_request(): void
    {
        $b = $this->branch();
        $product = $this->product();
        $this->order($b, $product);
        $request = StockRequest::withoutGlobalScopes()->firstOrFail();
        $item = $request->items()->firstOrFail();

        $first = $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/stock-requests/{$request->id}/proposals", ['items' => [['item_id' => $item->id, 'quantity' => 6]]])->assertCreated()->json('data');
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$first['id']}/approve")->assertOk();
        $this->assertSame('partial', $request->fresh()->status);

        $second = $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/stock-requests/{$request->id}/proposals", ['items' => [['item_id' => $item->id, 'quantity' => 4]]])->assertCreated()->json('data');
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$second['id']}/approve")->assertOk();

        $request->refresh();
        $this->assertSame('fulfilled', $request->status);
        $item->refresh();
        $this->assertSame(10, $item->fulfilled_qty);
        $this->assertSame(0, $item->remaining_qty);
        $this->assertSame(10, $this->bucket($b['agent']->id, $product->id, 'transit'));
        $this->assertSame(10, $this->bucket($b['agent']->id, $product->id, 'shipping'));
        $this->assertSame(0, (int) ProductStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->value('quantity_reserved'));
        $this->assertSame(1, StockRequest::withoutGlobalScopes()->count());
    }

    public function test_insufficient_transit_and_stale_remaining_fail_safely(): void
    {
        $b = $this->branch();
        $product = $this->product();
        $this->order($b, $product);
        $request = StockRequest::withoutGlobalScopes()->firstOrFail();
        $item = $request->items()->firstOrFail();

        $proposal = $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/stock-requests/{$request->id}/proposals", ['items' => [['item_id' => $item->id, 'quantity' => 10]]])->assertCreated()->json('data');
        WarehouseStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->where('stock_type', 'transit')->update(['quantity' => 7]);
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/approve")->assertUnprocessable();
        $this->assertSame('pending', StockRequestProposal::withoutGlobalScopes()->findOrFail($proposal['id'])->status);
        $this->assertSame(7, $this->bucket($b['agent']->id, $product->id, 'transit'));
        $this->assertSame(0, StockMovement::query()->count());

        WarehouseStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->where('stock_type', 'transit')->update(['quantity' => 20]);
        $a = $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/stock-requests/{$request->id}/proposals", ['items' => [['item_id' => $item->id, 'quantity' => 6]]])->assertCreated()->json('data');
        $second = $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/stock-requests/{$request->id}/proposals", ['items' => [['item_id' => $item->id, 'quantity' => 7]]])->assertCreated()->json('data');
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$second['id']}/approve")->assertOk();
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$a['id']}/approve")->assertUnprocessable();
        $item->refresh();
        $this->assertSame(7, $item->fulfilled_qty);
        $this->assertSame(3, $item->remaining_qty);
        $this->assertSame(2, StockMovement::query()->count());
    }

    public function test_terminal_states_have_no_divergence(): void
    {
        $b = $this->branch();
        $product = $this->product();
        $this->order($b, $product);
        $request = StockRequest::withoutGlobalScopes()->firstOrFail();
        $item = $request->items()->firstOrFail();

        $proposal = $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/stock-requests/{$request->id}/proposals", ['items' => [['item_id' => $item->id, 'quantity' => 6]]])->assertCreated()->json('data');
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/approve")->assertOk();
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/approve")->assertOk();
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/reject", ['reason' => 'late'])->assertUnprocessable();
        $this->assertSame('approved', StockRequestProposal::withoutGlobalScopes()->findOrFail($proposal['id'])->status);
        $this->assertSame(2, StockMovement::query()->count());

        $second = $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/stock-requests/{$request->id}/proposals", ['items' => [['item_id' => $item->id, 'quantity' => 2]]])->assertCreated()->json('data');
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$second['id']}/reject", ['reason' => 'stok opname'])->assertOk();
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$second['id']}/reject", ['reason' => 'lagi'])->assertOk();
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$second['id']}/approve")->assertUnprocessable();
        $this->assertSame('rejected', StockRequestProposal::withoutGlobalScopes()->findOrFail($second['id'])->status);
        $this->assertSame(14, $this->bucket($b['agent']->id, $product->id, 'transit'));
        $this->assertSame(6, $this->bucket($b['agent']->id, $product->id, 'shipping'));
        $this->assertSame(2, StockMovement::query()->count());
    }

    public function test_authorization_matrix(): void
    {
        $b = $this->branch();
        $product = $this->product();
        $this->order($b, $product);
        $request = StockRequest::withoutGlobalScopes()->firstOrFail();
        $item = $request->items()->firstOrFail();

        $proposal = $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/stock-requests/{$request->id}/proposals", ['items' => [['item_id' => $item->id, 'quantity' => 3]]])->assertCreated()->json('data');

        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/approve")->assertForbidden();
        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/reject", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($b['foreignAdmin'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/approve")->assertNotFound();
        $this->actingAs($b['foreignAdmin'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/reject", ['reason' => 'x'])->assertNotFound();
        $this->actingAs($b['foreignGudang'])->postJson("/api/v1/warehouse/stock-requests/{$request->id}/proposals", ['items' => [['item_id' => $item->id, 'quantity' => 1]]])->assertNotFound();
        $superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($superAdmin)->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/approve")->assertForbidden();
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        $agenAdmin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $this->actingAs($agenAdmin)->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/approve")->assertNotFound();
        $this->actingAs($b['agent'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/approve")->assertForbidden();

        $this->assertSame('pending', StockRequestProposal::withoutGlobalScopes()->findOrFail($proposal['id'])->status);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_variation_isolation(): void
    {
        $b = $this->branch();
        $product = Product::create(['name' => 'Proposal Var Cake', 'slug' => 'proposal-var-'.uniqid(), 'has_variations' => true, 'status' => 'active']);
        $small = ProductVariation::create(['product_id' => $product->id, 'sku' => 'PV-S-'.uniqid(), 'price' => 1000, 'weight_grams' => 100, 'is_active' => true]);
        $large = ProductVariation::create(['product_id' => $product->id, 'sku' => 'PV-L-'.uniqid(), 'price' => 1000, 'weight_grams' => 100, 'is_active' => true]);
        $order = Order::create(['order_no' => 'PV-'.Str::upper(Str::random(10)), 'konsumen_id' => $b['konsumen']->id, 'agent_id' => $b['agent']->id, 'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'), 'status' => 'diterima', 'payment_status' => 'unpaid', 'subtotal_amount' => 4000, 'total_amount' => 4000, 'recipient_name_snapshot' => 'T', 'recipient_phone_snapshot' => '0', 'address_snapshot' => 'T']);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_variation_id' => $small->id, 'product_name_snapshot' => $product->name, 'sku_snapshot' => $small->sku, 'unit_price_snapshot' => 1000, 'subtotal_snapshot' => 4000, 'original_quantity' => 4, 'fulfilled_quantity' => 0, 'status' => 'diterima']);
        foreach ([$small, $large] as $variation) {
            ProductVariationStock::create(['agent_id' => $b['agent']->id, 'product_variation_id' => $variation->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 4]);
            WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_variation_id' => $variation->id, 'stock_type' => 'transit', 'quantity' => $variation->id === $small->id ? 10 : 20]);
        }
        WarehouseSetting::create(['agent_id' => $b['agent']->id, 'factory_plan_enabled' => false]);
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();

        $request = StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->firstOrFail();
        $item = $request->items()->firstOrFail();
        $proposal = $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/stock-requests/{$request->id}/proposals", ['items' => [['item_id' => $item->id, 'quantity' => 4]]])->assertCreated()->json('data');
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/approve")->assertOk();

        $this->assertSame(6, (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->where('product_variation_id', $small->id)->where('stock_type', 'transit')->value('quantity'));
        $this->assertSame(20, (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->where('product_variation_id', $large->id)->where('stock_type', 'transit')->value('quantity'));
        $this->assertSame(4, (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->where('product_variation_id', $small->id)->where('stock_type', 'shipping')->value('quantity'));
        $this->assertSame(0, (int) ProductVariationStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->where('product_variation_id', $small->id)->value('quantity_reserved'));
        $this->assertSame(4, (int) ProductVariationStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->where('product_variation_id', $large->id)->value('quantity_reserved'));
    }
}
