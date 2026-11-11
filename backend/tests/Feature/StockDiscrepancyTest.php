<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\StockRequest;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F07 — short/damaged units reported at receipt never silently become sellable: damaged units are HELD until an
 * Admin resolves them; short units stay owed; the Admin approval path is an audited exception.
 */
class StockDiscrepancyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function fixture(int $damaged = 1, bool $queueDuplicate = false): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $b = [
            'agent' => $agent,
            'admin' => User::factory()->admin()->create(['agent_id' => $agent->id]),
            'gudang' => User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]),
            'koordinator' => User::factory()->koordinatorKurir()->create(['agent_id' => $agent->id]),
            'konsumen' => User::factory()->konsumen()->create(['agent_id' => $agent->id]),
        ];
        $product = Product::create(['sku' => 'SD-'.uniqid(), 'name' => 'Discrepancy Cake', 'slug' => 'sd-'.uniqid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
        $order = Order::create(['order_no' => 'SD-'.Str::upper(Str::random(10)), 'konsumen_id' => $b['konsumen']->id, 'agent_id' => $agent->id, 'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'), 'status' => 'diterima', 'payment_status' => 'unpaid', 'subtotal_amount' => 10000, 'total_amount' => 10000, 'remaining_amount' => 10000, 'recipient_name_snapshot' => 'T', 'recipient_phone_snapshot' => '0811', 'address_snapshot' => 'T']);
        \App\Models\Shipment::create(['order_id' => $order->id, 'status' => 'pending', 'delivery_mode' => 'standard', 'shipping_provider_code' => 'openroute']);
        $item = OrderItem::create(['order_id' => $order->id, 'shipment_id' => $order->shipments()->value('id'), 'product_id' => $product->id, 'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku, 'unit_price_snapshot' => 1000, 'subtotal_snapshot' => 10000, 'original_quantity' => 10, 'fulfilled_quantity' => 10, 'status' => 'diterima']);
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 10]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 20]);
        WarehouseSetting::create(['agent_id' => $agent->id, 'factory_plan_enabled' => false]);
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();
        $request = StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->firstOrFail();

        // Prepared 10 → accepted 6; the other 4 are short or damaged.
        $di = $request->items()->firstOrFail();
        $proposal = $this->actingAs($b['gudang'])->post("/api/v1/warehouse/stock-requests/{$request->id}/proposals", $this->withHandoverProof(['items' => [['item_id' => $di->id, 'quantity' => 10]]]), ['Accept' => 'application/json'])->assertCreated()->json('data');
        if ($queueDuplicate) {
            $this->actingAs($b['gudang'])->post("/api/v1/warehouse/stock-requests/{$request->id}/proposals", $this->withHandoverProof(['items' => [['item_id' => $di->id, 'quantity' => 4]]]), ['Accept' => 'application/json'])->assertCreated();
        }
        $this->actingAs($b['koordinator'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/receive", ['items' => [['item_id' => $proposal['items'][0]['id'], 'accepted' => 6, 'short' => 4 - $damaged, 'damaged' => $damaged]]])->assertOk();

        return [$b, $product, $order->fresh(), $item->fresh(), $request, $di, $proposal];
    }

    private function bucket(int $agentId, int $productId, string $type): int
    {
        return (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('stock_type', $type)->where('product_id', $productId)->sum('quantity');
    }

    private function reserved(int $agentId): int
    {
        return (int) ProductStock::withoutGlobalScopes()->where('agent_id', $agentId)->value('quantity_reserved');
    }

    public function test_cancelling_the_remainder_releases_short_units_but_keeps_the_damaged_unit_held(): void
    {
        [$b, $product, $order, $item, $request, $di] = $this->fixture();
        $this->assertSame([6, 4, 1], [$di->fresh()->fulfilled_qty, $di->fresh()->remaining_qty, (int) $di->fresh()->damaged_hold_qty]);
        $this->assertSame(4, $this->reserved($b['agent']->id));

        // Cancel the 4 outstanding units: only the 3 releasable ones free their reservation; the damaged unit stays reserved/held.
        $this->actingAs($b['admin'])->postJson("/api/v1/orders/{$order->id}/items/{$item->id}/cancel-undelivered", ['quantity' => 4, 'expected_quantity' => 10, 'reason' => 'batal'])->assertOk();
        $this->assertSame(1, $this->reserved($b['agent']->id), 'the held damaged unit keeps its reservation — not sellable');
        $this->assertSame(1, (int) $di->fresh()->damaged_hold_qty);
        $this->assertSame(1, StockMovement::withoutGlobalScopes()->where('type', 'release')->count(), 'one release movement, for the 3 released units only');
        $this->assertSame(-3, (int) StockMovement::withoutGlobalScopes()->where('type', 'release')->value('quantity'));

        // Active is now 6 = 5 accepted + the held damaged unit. Cancelling all 6 would release the held unit → refused.
        $this->actingAs($b['admin'])->postJson("/api/v1/orders/{$order->id}/items/{$item->id}/cancel-undelivered", ['quantity' => 6, 'expected_quantity' => 6, 'reason' => 'x'])
            ->assertStatus(422)->assertJsonPath('errors.code', 'discrepancy_unresolved');
        $this->assertSame(1, $this->reserved($b['agent']->id));
        $this->assertSame(1, (int) $di->fresh()->damaged_hold_qty);
    }

    public function test_held_damaged_units_cannot_be_prepared_again_and_admin_resolution_is_the_only_exit(): void
    {
        [$b, $product, , , $request, $di] = $this->fixture();

        // 4 outstanding − 1 held = 3 may be re-prepared; 4 may not.
        $this->actingAs($b['gudang'])->post("/api/v1/warehouse/stock-requests/{$request->id}/proposals", $this->withHandoverProof(['items' => [['item_id' => $di->id, 'quantity' => 4]]]), ['Accept' => 'application/json'])->assertStatus(422);

        // Only an Admin resolves; Koordinator/Gudang cannot.
        $url = "/api/v1/warehouse/stock-request-items/{$di->id}/resolve-discrepancy";
        foreach (['gudang', 'koordinator'] as $role) {
            $this->actingAs($b[$role])->postJson($url, ['idempotency_key' => (string) Str::uuid(), 'action' => 'write_off', 'quantity' => 1, 'reason' => 'rusak berat'])->assertForbidden();
        }
        $this->actingAs($b['admin'])->postJson($url, ['idempotency_key' => (string) Str::uuid(), 'action' => 'write_off', 'quantity' => 2, 'reason' => 'rusak berat'])->assertStatus(422); // more than held

        $transitBefore = $this->bucket($b['agent']->id, $product->id, 'transit');
        $this->actingAs($b['admin'])->postJson($url, ['idempotency_key' => (string) Str::uuid(), 'action' => 'write_off', 'quantity' => 1, 'reason' => 'rusak berat'])->assertOk()->assertJsonPath('data.damaged_hold_qty', 0);
        $this->assertSame($transitBefore - 1, $this->bucket($b['agent']->id, $product->id, 'transit'), 'write-off removes the lost unit from Transit, once');
        $this->assertSame(1, StockMovement::withoutGlobalScopes()->where('type', 'adjustment')->count());
        $this->assertSame(1, ActivityLog::where('event', 'stock_request.discrepancy_resolved')->count());
        $this->actingAs($b['admin'])->postJson($url, ['idempotency_key' => (string) Str::uuid(), 'action' => 'write_off', 'quantity' => 1, 'reason' => 'ulang'])->assertStatus(422); // nothing left to resolve
        $this->assertSame(4, $this->reserved($b['agent']->id), 'the line still owes the units: reservation unchanged');

        // After resolution the full outstanding demand can be prepared again.
        $this->actingAs($b['gudang'])->post("/api/v1/warehouse/stock-requests/{$request->id}/proposals", $this->withHandoverProof(['items' => [['item_id' => $di->id, 'quantity' => 4]]]), ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_restock_without_an_approved_inspection_workflow_keeps_the_hold(): void
    {
        [$b, $product, , , , $di] = $this->fixture();
        $transit = $this->bucket($b['agent']->id, $product->id, 'transit');
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/stock-request-items/{$di->id}/resolve-discrepancy", ['idempotency_key' => (string) Str::uuid(), 'action' => 'restock', 'quantity' => 1, 'reason' => 'ternyata layak'])->assertStatus(422);
        $this->assertSame(1, (int) $di->fresh()->damaged_hold_qty);
        $this->assertSame($transit, $this->bucket($b['agent']->id, $product->id, 'transit'));
        $this->assertSame(0, StockMovement::withoutGlobalScopes()->where('type', 'adjustment')->count());
    }


    public function test_queued_proposal_cannot_accept_units_held_after_it_was_prepared(): void
    {
        [$b, $product, , , $request, $di] = $this->fixture(queueDuplicate: true);
        $queued = \App\Models\StockRequestProposal::withoutGlobalScopes()->where('stock_request_id', $request->id)->where('status', 'pending')->firstOrFail();
        $before = [$this->bucket($b['agent']->id, $product->id, 'transit'), $this->bucket($b['agent']->id, $product->id, 'shipping'), $this->reserved($b['agent']->id), StockMovement::withoutGlobalScopes()->count()];
        $this->actingAs($b['koordinator'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$queued->id}/receive", ['items' => [['item_id' => $queued->items()->value('id'), 'accepted' => 4, 'short' => 0, 'damaged' => 0]]])->assertStatus(422);
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$queued->id}/approve", ['reason' => 'pengecualian uji'])->assertStatus(422);
        $this->assertSame($before, [$this->bucket($b['agent']->id, $product->id, 'transit'), $this->bucket($b['agent']->id, $product->id, 'shipping'), $this->reserved($b['agent']->id), StockMovement::withoutGlobalScopes()->count()]);
        $this->assertSame([6, 4, 1], [$di->fresh()->fulfilled_qty, $di->fresh()->remaining_qty, (int) $di->fresh()->damaged_hold_qty]);
        $this->assertSame('pending', $queued->fresh()->status);
    }

    public function test_partial_write_off_replay_does_not_consume_another_held_unit(): void
    {
        [$b, $product, , , , $di] = $this->fixture(damaged: 3);
        $url = "/api/v1/warehouse/stock-request-items/{$di->id}/resolve-discrepancy";
        $payload = ['idempotency_key' => (string) Str::uuid(), 'action' => 'write_off', 'quantity' => 1, 'reason' => 'rusak berat'];
        $transit = $this->bucket($b['agent']->id, $product->id, 'transit');
        $this->actingAs($b['admin'])->postJson($url, $payload)->assertOk();
        $this->actingAs($b['admin'])->postJson($url, $payload)->assertStatus(409);
        $this->assertSame(2, (int) $di->fresh()->damaged_hold_qty);
        $this->assertSame($transit - 1, $this->bucket($b['agent']->id, $product->id, 'transit'));
        $this->assertSame(4, $this->reserved($b['agent']->id));
        $this->assertSame(1, StockMovement::withoutGlobalScopes()->where('type', 'adjustment')->count());
        $this->assertSame(1, ActivityLog::where('event', 'stock_request.discrepancy_resolved')->count());
        $payload['quantity'] = 2;
        $this->actingAs($b['admin'])->postJson($url, $payload)->assertStatus(409);
        $payload['idempotency_key'] = (string) Str::uuid();
        $this->actingAs($b['admin'])->postJson($url, $payload)->assertOk();
        $this->assertSame(0, (int) $di->fresh()->damaged_hold_qty);
        $this->assertSame($transit - 3, $this->bucket($b['agent']->id, $product->id, 'transit'));
    }

    public function test_admin_approval_is_an_audited_exception_that_needs_a_reason_and_cannot_double_settle(): void
    {
        [$b, , , , $request, $di, $proposal] = $this->fixture();
        $url = "/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/approve";
        $this->actingAs($b['admin'])->postJson($url)->assertStatus(422); // reason mandatory
        $shippingBefore = (int) WarehouseStock::withoutGlobalScopes()->where('stock_type', 'shipping')->sum('quantity');

        // The Koordinator already received it: the exception path is a replay, not a second settlement.
        $this->actingAs($b['admin'])->postJson($url, ['reason' => 'pengecualian uji'])->assertOk();
        $this->assertSame($shippingBefore, (int) WarehouseStock::withoutGlobalScopes()->where('stock_type', 'shipping')->sum('quantity'));
        $this->assertSame(6, $di->fresh()->fulfilled_qty);
    }
}
