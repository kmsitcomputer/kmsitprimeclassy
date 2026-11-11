<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\StockRequest;
use App\Models\StockRequestProposal;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REM-002 — dual physical handover: Gudang prepares (proposal), Koordinator Kurir confirms receipt
 * with accepted / short / damaged. Only accepted units move stock, exactly once.
 */
class CoordinatorReceiptTest extends TestCase
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

        return [
            'agent' => $agent,
            'admin' => User::factory()->admin()->create(['agent_id' => $agent->id]),
            'gudang' => User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]),
            'koordinator' => User::factory()->koordinatorKurir()->create(['agent_id' => $agent->id]),
            'konsumen' => User::factory()->konsumen()->create(['agent_id' => $agent->id]),
        ];
    }

    /** @return array{0: array, 1: Product, 2: Order, 3: StockRequest} */
    private function processedOrder(int $qty = 10): array
    {
        $b = $this->branch();
        $product = Product::create(['sku' => 'RC-'.uniqid(), 'name' => 'Receipt Cake', 'slug' => 'rc-'.uniqid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
        $order = Order::create(['order_no' => 'RC-'.Str::upper(Str::random(10)), 'konsumen_id' => $b['konsumen']->id, 'agent_id' => $b['agent']->id, 'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'), 'status' => 'diterima', 'payment_status' => 'unpaid', 'subtotal_amount' => $qty * 1000, 'total_amount' => $qty * 1000, 'recipient_name_snapshot' => 'T', 'recipient_phone_snapshot' => '0811', 'address_snapshot' => 'T']);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku, 'unit_price_snapshot' => 1000, 'subtotal_snapshot' => $qty * 1000, 'original_quantity' => $qty, 'fulfilled_quantity' => $qty, 'status' => 'diterima']);
        ProductStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => $qty]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 20]);
        WarehouseSetting::create(['agent_id' => $b['agent']->id, 'factory_plan_enabled' => false]);
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();

        return [$b, $product, $order->fresh(), StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->firstOrFail()];
    }

    private function bucket(int $agentId, int $productId, string $type): int
    {
        return (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('stock_type', $type)->where('product_id', $productId)->sum('quantity');
    }

    private function propose(array $b, StockRequest $request, int $qty): array
    {
        $item = $request->items()->firstOrFail();

        return $this->actingAs($b['gudang'])->post("/api/v1/warehouse/stock-requests/{$request->id}/proposals", $this->withHandoverProof(['items' => [['item_id' => $item->id, 'quantity' => $qty]]]), ['Accept' => 'application/json'])
            ->assertCreated()->json('data');
    }

    public function test_receipt_moves_only_accepted_units_once_and_keeps_short_damaged_outstanding(): void
    {
        [$b, $product, , $request] = $this->processedOrder(10);
        $proposal = $this->propose($b, $request, 10);
        $itemId = $proposal['items'][0]['id'];

        $payload = ['items' => [['item_id' => $itemId, 'accepted' => 6, 'short' => 3, 'damaged' => 1]], 'note' => 'cek fisik'];
        $this->actingAs($b['koordinator'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/receive", $payload)
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.items.0.accepted_qty', 6)
            ->assertJsonPath('data.items.0.short_qty', 3)
            ->assertJsonPath('data.items.0.damaged_qty', 1);

        $item = $request->items()->firstOrFail()->fresh();
        $this->assertSame(6, $item->fulfilled_qty);
        $this->assertSame(4, $item->remaining_qty);
        $this->assertSame('partial', $request->fresh()->status);
        $this->assertSame(14, $this->bucket($b['agent']->id, $product->id, 'transit'));
        $this->assertSame(6, $this->bucket($b['agent']->id, $product->id, 'shipping'));
        $this->assertSame(4, (int) ProductStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->value('quantity_reserved'));
        $this->assertSame(-6, (int) StockMovement::withoutGlobalScopes()->where('stock_type', 'transit')->sum('quantity'));
        $this->assertSame(6, (int) StockMovement::withoutGlobalScopes()->where('stock_type', 'shipping')->sum('quantity'));
        $stored = StockRequestProposal::withoutGlobalScopes()->findOrFail($proposal['id']);
        $this->assertSame($b['koordinator']->id, $stored->received_by);
        $this->assertNotNull($stored->received_at);

        // Retry and Admin exception path after the receipt: replay, never a second movement.
        $this->actingAs($b['koordinator'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/receive", $payload)->assertOk();
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/approve", ['reason' => 'pengecualian uji coba'])->assertOk();
        $this->assertSame(6, $this->bucket($b['agent']->id, $product->id, 'shipping'));
        $this->assertSame(1, StockMovement::withoutGlobalScopes()->where('stock_type', 'shipping')->count());

        // F07: outstanding SHORT units can be prepared again, but the DAMAGED unit is held — never re-prepared as valid goods.
        $this->actingAs($b['gudang'])->post("/api/v1/warehouse/stock-requests/{$request->id}/proposals", $this->withHandoverProof(['items' => [['item_id' => $item->id, 'quantity' => 4]]]), ['Accept' => 'application/json'])->assertStatus(422);
        $this->actingAs($b['gudang'])->post("/api/v1/warehouse/stock-requests/{$request->id}/proposals", $this->withHandoverProof(['items' => [['item_id' => $item->id, 'quantity' => 3]]]), ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame(1, (int) $item->fresh()->damaged_hold_qty);
    }

    public function test_receipt_must_account_for_every_prepared_unit(): void
    {
        [$b, , , $request] = $this->processedOrder(10);
        $proposal = $this->propose($b, $request, 10);
        $itemId = $proposal['items'][0]['id'];

        foreach ([[11, 0, 0], [5, 1, 1], [0, 0, 0]] as [$a, $s, $d]) {
            $this->actingAs($b['koordinator'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/receive", ['items' => [['item_id' => $itemId, 'accepted' => $a, 'short' => $s, 'damaged' => $d]]])
                ->assertStatus(422);
        }
        $this->assertSame('pending', StockRequestProposal::withoutGlobalScopes()->findOrFail($proposal['id'])->status);
        $this->assertSame(0, StockMovement::withoutGlobalScopes()->count());
    }

    public function test_zero_accepted_receipt_records_shortage_without_moving_stock(): void
    {
        [$b, $product, , $request] = $this->processedOrder(10);
        $proposal = $this->propose($b, $request, 10);

        $this->actingAs($b['koordinator'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/receive", ['items' => [['item_id' => $proposal['items'][0]['id'], 'accepted' => 0, 'short' => 4, 'damaged' => 6]]])->assertOk();
        $this->assertSame(0, $request->items()->firstOrFail()->fresh()->fulfilled_qty);
        $this->assertSame(20, $this->bucket($b['agent']->id, $product->id, 'transit'));
        $this->assertSame(0, StockMovement::withoutGlobalScopes()->count());
    }

    public function test_only_same_branch_koordinator_may_receive(): void
    {
        [$b, , , $request] = $this->processedOrder(10);
        $proposal = $this->propose($b, $request, 10);
        $payload = ['items' => [['item_id' => $proposal['items'][0]['id'], 'accepted' => 10, 'short' => 0, 'damaged' => 0]]];
        $url = "/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/receive";

        $foreign = User::factory()->agen()->create();
        $foreign->update(['agent_id' => $foreign->id]);
        $foreignKoordinator = User::factory()->koordinatorKurir()->create(['agent_id' => $foreign->id]);

        foreach (['gudang', 'admin', 'agent'] as $role) {
            $this->actingAs($b[$role])->postJson($url, $payload)->assertForbidden();
        }
        $this->actingAs($foreignKoordinator)->postJson($url, $payload)->assertStatus(404);
        $this->assertSame(0, StockMovement::withoutGlobalScopes()->count());
    }

    public function test_koordinator_requests_fulfilment_on_the_single_stock_request_without_duplicates(): void
    {
        [$b, $product, $order, $request] = $this->processedOrder(10);
        $reservedBefore = (int) ProductStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->value('quantity_reserved');

        $this->actingAs($b['koordinator'])->postJson("/api/v1/dispatch/orders/{$order->id}/request-fulfillment", ['note' => 'batch 1'])
            ->assertOk()->assertJsonPath('data.coordinator_request_note', 'batch 1')
            ->assertJsonPath('data.coordinator_requested_by', $b['koordinator']->id);
        $this->actingAs($b['koordinator'])->postJson("/api/v1/dispatch/orders/{$order->id}/request-fulfillment")->assertOk(); // repeatable

        $this->assertSame(1, StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->count());
        $this->assertSame($reservedBefore, (int) ProductStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->value('quantity_reserved'));
        $this->assertSame(10, (int) $request->items()->firstOrFail()->fresh()->remaining_qty);
        $this->assertSame(2, \App\Models\ActivityLog::where('event', 'stock_request.coordinator_requested')->count());

        // Gudang sees the request marker on the demand it works against.
        $this->actingAs($b['gudang'])->getJson("/api/v1/warehouse/orders/{$order->id}/stock-request")->assertOk()
            ->assertJsonPath('data.coordinator_requested_by', $b['koordinator']->id);
    }

    public function test_fulfilment_request_is_refused_when_nothing_is_outstanding_or_for_other_roles_and_branches(): void
    {
        [$b, , $order, $request] = $this->processedOrder(10);
        $url = "/api/v1/dispatch/orders/{$order->id}/request-fulfillment";

        foreach (['gudang', 'admin', 'agent'] as $role) {
            $this->actingAs($b[$role])->postJson($url)->assertForbidden();
        }
        $foreign = User::factory()->agen()->create();
        $foreign->update(['agent_id' => $foreign->id]);
        $this->actingAs(User::factory()->koordinatorKurir()->create(['agent_id' => $foreign->id]))->postJson($url)->assertStatus(404);

        // Everything accepted → nothing outstanding.
        $item = $request->items()->firstOrFail();
        $proposal = $this->propose($b, $request, 10);
        $this->actingAs($b['koordinator'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/receive", ['items' => [['item_id' => $proposal['items'][0]['id'], 'accepted' => 10, 'short' => 0, 'damaged' => 0]]])->assertOk();
        $this->actingAs($b['koordinator'])->postJson($url)->assertStatus(422);
    }

    public function test_admin_qty_reduction_after_partial_receipt_keeps_reservation_demand_and_accepted_stock_consistent(): void
    {
        [$b, $product, $order, $request] = $this->processedOrder(10);
        $proposal = $this->propose($b, $request, 6);
        $this->actingAs($b['koordinator'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/receive", ['items' => [['item_id' => $proposal['items'][0]['id'], 'accepted' => 6, 'short' => 0, 'damaged' => 0]]])->assertOk();
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();
        $reserved = fn () => (int) ProductStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->value('quantity_reserved');
        $this->assertSame(4, $reserved()); // 10 reserved − 6 consumed by the accepted units

        // Reduce 10 → 8: only the 2 un-accepted units may be released.
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/fulfillment", ['fulfilled_quantity' => 8, 'reason' => 'kurang'])->assertOk();
        $ri = $request->items()->firstOrFail()->fresh();
        $this->assertSame(2, $reserved(), 'reservation follows the outstanding demand');
        $this->assertSame(6, $ri->fulfilled_qty, 'accepted history untouched');
        $this->assertSame(2, $ri->remaining_qty, 'outstanding demand shrinks with the order line');
        $this->assertSame(8, $ri->requested_qty);
        $this->assertSame(6, $this->bucket($b['agent']->id, $product->id, 'shipping'));

        // Reduce 8 → 5: 2 un-accepted units released AND 1 accepted unit goes back Shipping → Transit
        // (its reservation was already consumed — no second release).
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/fulfillment", ['fulfilled_quantity' => 5, 'reason' => 'kurang lagi'])->assertOk();
        $ri = $request->items()->firstOrFail()->fresh();
        $this->assertSame(0, $reserved());
        $this->assertSame([5, 0, 5], [$ri->fulfilled_qty, $ri->remaining_qty, $ri->requested_qty]);
        $this->assertSame(5, $this->bucket($b['agent']->id, $product->id, 'shipping'));
        $this->assertSame(15, $this->bucket($b['agent']->id, $product->id, 'transit'));
        $this->assertSame('fulfilled', $request->fresh()->status);
    }

    public function test_admin_qty_increase_raises_outstanding_demand_so_gudang_can_prepare_it(): void
    {
        [$b, , $order, $request] = $this->processedOrder(10);
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();
        \App\Models\ProductStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->update(['quantity_on_hand' => 50]);

        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/fulfillment", ['fulfilled_quantity' => 12, 'reason' => 'tambah'])->assertOk();
        $ri = $request->items()->firstOrFail()->fresh();
        $this->assertSame([12, 12, 0], [$ri->requested_qty, $ri->remaining_qty, $ri->fulfilled_qty]);
        $this->assertSame('pending', $request->fresh()->status);
        $this->propose($b, $request, 12); // Gudang can prepare the increased demand
    }
}
