<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderFinancialSnapshot;
use App\Models\OrderFinancialSnapshotUnit;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
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
 * REM-003 / Owner Option A — cancellation of the UNDELIVERED, UNDISPATCHED remainder, also after the
 * first dispatch; frozen snapshot only consumed (never rewritten), stock once, refund on overpayment only.
 */
class PartialCancellationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    /** Order with line X (10) and line Y (4), each on its own pending shipment, unit price 1000. */
    private function fixture(): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $b = [
            'agent' => $agent,
            'admin' => User::factory()->admin()->create(['agent_id' => $agent->id]),
            'gudang' => User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]),
            'koordinator' => User::factory()->koordinatorKurir()->create(['agent_id' => $agent->id]),
            'konsumen' => User::factory()->konsumen()->create(['agent_id' => $agent->id]),
            'kurir' => User::factory()->kurir()->create(['agent_id' => $agent->id]),
        ];
        $b['courier'] = Courier::create(['type' => 'internal', 'user_id' => $b['kurir']->id, 'agent_id' => $agent->id, 'name' => 'K1', 'is_active' => true]);
        $product = Product::create(['sku' => 'PC-'.uniqid(), 'name' => 'Cancel Cake', 'slug' => 'pc-'.uniqid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
        $order = Order::create(['order_no' => 'PC-'.Str::upper(Str::random(10)), 'konsumen_id' => $b['konsumen']->id, 'agent_id' => $agent->id, 'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'), 'status' => 'diterima', 'payment_status' => 'unpaid', 'subtotal_amount' => 14000, 'total_amount' => 14000, 'remaining_amount' => 14000, 'recipient_name_snapshot' => 'T', 'recipient_phone_snapshot' => '0811', 'address_snapshot' => 'T']);
        $items = [];
        $shipments = [];
        foreach ([10, 4] as $qty) {
            $shipment = Shipment::create(['order_id' => $order->id, 'status' => 'pending', 'delivery_mode' => 'standard', 'shipping_provider_code' => 'openroute']);
            $items[] = OrderItem::create(['order_id' => $order->id, 'shipment_id' => $shipment->id, 'product_id' => $product->id, 'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku, 'unit_price_snapshot' => 1000, 'subtotal_snapshot' => $qty * 1000, 'original_quantity' => $qty, 'fulfilled_quantity' => $qty, 'status' => 'diterima']);
            $shipments[] = $shipment;
        }
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 14]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 30]);
        WarehouseSetting::create(['agent_id' => $agent->id, 'factory_plan_enabled' => false]);
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();

        return [$b, $product, $order->fresh(), $items, $shipments, StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->firstOrFail()];
    }

    private function receive(array $b, StockRequest $request, OrderItem $item, int $qty): void
    {
        $di = $request->items()->where('order_item_id', $item->id)->firstOrFail();
        $proposal = $this->actingAs($b['gudang'])->post("/api/v1/warehouse/stock-requests/{$request->id}/proposals", $this->withHandoverProof(['items' => [['item_id' => $di->id, 'quantity' => $qty]]]), ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->actingAs($b['koordinator'])->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposal['id']}/receive", ['items' => [['item_id' => $proposal['items'][0]['id'], 'accepted' => $qty, 'short' => 0, 'damaged' => 0]]])->assertOk();
    }

    /** Accept + assign + pick up line X → first handover, financial snapshot frozen, order `dikirim`. */
    private function dispatchFirstLine(array $b, StockRequest $request, array $items, array $shipments): void
    {
        $this->receive($b, $request, $items[0], 10);
        $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$shipments[0]->id}/courier", ['courier_id' => $b['courier']->id])->assertOk();
        $this->actingAs($b['kurir'])->patchJson("/api/v1/shipments/{$shipments[0]->id}/status", ['status' => 'dikirim'])->assertOk();
    }

    private function cancel(User $actor, Order $order, OrderItem $item, int $qty, int $expected)
    {
        return $this->actingAs($actor)->postJson("/api/v1/orders/{$order->id}/items/{$item->id}/cancel-undelivered", ['quantity' => $qty, 'expected_quantity' => $expected, 'reason' => 'pelanggan batal']);
    }

    private function reserved(int $agentId): int
    {
        return (int) ProductStock::withoutGlobalScopes()->where('agent_id', $agentId)->value('quantity_reserved');
    }

    public function test_cancellation_before_any_dispatch_releases_reservation_and_shrinks_demand(): void
    {
        [$b, , $order, $items, , $request] = $this->fixture();

        $this->actingAs($b['admin'])->getJson("/api/v1/orders/{$order->id}/items/{$items[1]->id}/cancel-eligibility")->assertOk()
            ->assertJsonPath('data.eligible', true)->assertJsonPath('data.max_quantity', 4);
        $this->cancel($b['admin'], $order, $items[1], 3, 4)->assertOk();

        $this->assertSame(1, $items[1]->fresh()->fulfilled_quantity);
        $this->assertSame(3, $items[1]->fresh()->cancelled_quantity);
        $this->assertSame(11, $this->reserved($b['agent']->id));
        $di = $request->items()->where('order_item_id', $items[1]->id)->firstOrFail();
        $this->assertSame([1, 1, 0], [$di->requested_qty, $di->remaining_qty, $di->fulfilled_qty]);
        $this->assertEquals(11000, $order->fresh()->total_amount);
    }

    public function test_cancellation_after_first_dispatch_consumes_the_frozen_snapshot_without_rewriting_it(): void
    {
        [$b, , $order, $items, $shipments, $request] = $this->fixture();
        $this->dispatchFirstLine($b, $request, $items, $shipments);
        $this->assertSame('dikirim', $order->fresh()->status);

        $snapshot = OrderFinancialSnapshot::where('order_id', $order->id)->firstOrFail();
        $before = [$snapshot->id, $snapshot->allocation_checksum, (string) $snapshot->subtotal_basis, $snapshot->trigger, OrderFinancialSnapshotUnit::where('snapshot_id', $snapshot->id)->count()];
        $paymentsBefore = PaymentTransaction::where('order_id', $order->id)->get(['id', 'amount', 'status'])->toArray();
        $movementsBefore = StockMovement::withoutGlobalScopes()->count();
        $reservedBefore = $this->reserved($b['agent']->id);

        // The ordinary Admin QTY path stays frozen — cancellation is the only way through, and only for Y.
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/items/{$items[1]->id}/fulfillment", ['fulfilled_quantity' => 2, 'reason' => 'x'])->assertStatus(422);

        $this->cancel($b['admin'], $order, $items[1], 3, 4)->assertOk();

        $snapshot->refresh();
        $this->assertSame($before, [$snapshot->id, $snapshot->allocation_checksum, (string) $snapshot->subtotal_basis, $snapshot->trigger, OrderFinancialSnapshotUnit::where('snapshot_id', $snapshot->id)->count()], 'snapshot immutable');
        $this->assertSame(3, OrderFinancialSnapshotUnit::where('snapshot_id', $snapshot->id)->where('order_item_id', $items[1]->id)->whereNotNull('consumed_at')->count());
        $this->assertSame(1, OrderFinancialSnapshotUnit::where('snapshot_id', $snapshot->id)->where('order_item_id', $items[1]->id)->whereNull('consumed_at')->count());
        $this->assertSame(0, OrderFinancialSnapshotUnit::where('snapshot_id', $snapshot->id)->where('order_item_id', $items[0]->id)->whereNotNull('consumed_at')->count(), 'dispatched line untouched');
        $this->assertSame($paymentsBefore, PaymentTransaction::where('order_id', $order->id)->get(['id', 'amount', 'status'])->toArray());
        $this->assertSame($reservedBefore - 3, $this->reserved($b['agent']->id));
        $this->assertSame(1, StockMovement::withoutGlobalScopes()->count() - $movementsBefore, 'exactly one release movement');
        $this->assertSame(10, $items[0]->fresh()->fulfilled_quantity);
        $this->assertSame('dikirim', $items[0]->fresh()->status);
    }

    public function test_repeat_and_stale_requests_never_double_cancel(): void
    {
        [$b, , $order, $items, $shipments, $request] = $this->fixture();
        $this->dispatchFirstLine($b, $request, $items, $shipments);

        $this->cancel($b['admin'], $order, $items[1], 3, 4)->assertOk();
        $movements = StockMovement::withoutGlobalScopes()->count();
        $reserved = $this->reserved($b['agent']->id);
        $this->cancel($b['admin'], $order, $items[1], 3, 4)->assertStatus(409); // identical retry
        $this->assertSame($movements, StockMovement::withoutGlobalScopes()->count());
        $this->assertSame($reserved, $this->reserved($b['agent']->id));
        $this->assertSame(1, $items[1]->fresh()->fulfilled_quantity);
        $this->cancel($b['admin'], $order, $items[1], 5, 1)->assertStatus(422); // more than active
    }

    public function test_delivered_dispatched_and_refused_quantity_cannot_be_cancelled(): void
    {
        [$b, , $order, $items, $shipments, $request] = $this->fixture();
        $this->dispatchFirstLine($b, $request, $items, $shipments);

        // In the courier's custody (dikirim) → refused.
        $this->actingAs($b['admin'])->getJson("/api/v1/orders/{$order->id}/items/{$items[0]->id}/cancel-eligibility")->assertOk()->assertJsonPath('data.eligible', false)->assertJsonPath('data.reason_code', 'not_undispatched');
        $this->cancel($b['admin'], $order, $items[0], 1, 10)->assertStatus(422);

        // Delivered → refused.
        $this->actingAs($b['kurir'])->patch("/api/v1/shipments/{$shipments[0]->id}/items/{$items[0]->id}/status", ['status' => 'terkirim', 'proof' => \Illuminate\Http\UploadedFile::fake()->image('p.jpg')], ['Accept' => 'application/json'])->assertOk();
        $this->cancel($b['admin'], $order, $items[0], 1, 10)->assertStatus(422);
        $this->assertSame(10, $items[0]->fresh()->fulfilled_quantity);
    }

    public function test_open_refusal_blocks_cancellation_until_resolved(): void
    {
        [$b, , $order, $items, $shipments, $request] = $this->fixture();
        $this->dispatchFirstLine($b, $request, $items, $shipments);
        $r = $this->actingAs($b['kurir'])->postJson("/api/v1/shipments/{$shipments[0]->id}/items/{$items[0]->id}/refuse", ['reason' => 'tolak'])->assertCreated()->json('data');
        $this->actingAs($b['kurir'])->postJson("/api/v1/delivery-refusals/{$r['id']}/hand-back")->assertOk();
        $this->actingAs($b['koordinator'])->postJson("/api/v1/delivery-refusals/{$r['id']}/receive")->assertOk();
        $this->actingAs($b['koordinator'])->postJson("/api/v1/delivery-refusals/{$r['id']}/disposition", ['action' => 'redelivery'])->assertOk();

        // Back to `diproses` on a fresh shipment, nothing dispatched → the undelivered units ARE cancellable now.
        $this->actingAs($b['admin'])->getJson("/api/v1/orders/{$order->id}/items/{$items[0]->id}/cancel-eligibility")->assertOk()->assertJsonPath('data.eligible', true);
        $this->cancel($b['admin'], $order, $items[0], 4, 10)->assertOk();
        $this->assertSame(6, $items[0]->fresh()->fulfilled_quantity);
        // accepted units went Shipping → Transit exactly for the cancelled part (reservation was consumed on accept).
        $this->assertSame(6, (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->where('stock_type', 'shipping')->sum('quantity'));
    }

    public function test_overpayment_from_cancellation_becomes_one_pending_refund_and_history_stays(): void
    {
        [$b, , $order, $items, $shipments, $request] = $this->fixture();
        $this->dispatchFirstLine($b, $request, $items, $shipments);
        $order->update(['paid_amount' => 14000, 'remaining_amount' => 0, 'payment_status' => 'paid', 'gross_received_amount' => 14000]);

        $this->cancel($b['admin'], $order, $items[1], 3, 4)->assertOk();
        $this->assertDatabaseHas('order_item_adjustments', ['order_item_id' => $items[1]->id, 'quantity_reduced' => 3, 'refund_amount' => 3000, 'refund_status' => 'pending']);
        $this->assertEquals(14000, $order->fresh()->paid_amount, 'paid history untouched until a refund is processed');
        $this->cancel($b['admin'], $order, $items[1], 3, 4)->assertStatus(409);
        $this->assertSame(1, \App\Models\OrderItemAdjustment::where('order_item_id', $items[1]->id)->count(), 'no double refund');
    }

    public function test_cancelling_the_last_undelivered_line_lets_the_order_complete(): void
    {
        [$b, , $order, $items, $shipments, $request] = $this->fixture();
        $this->dispatchFirstLine($b, $request, $items, $shipments);
        $this->actingAs($b['kurir'])->patch("/api/v1/shipments/{$shipments[0]->id}/items/{$items[0]->id}/status", ['status' => 'terkirim', 'proof' => \Illuminate\Http\UploadedFile::fake()->image('p.jpg')], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('dikirim', $order->fresh()->status);

        $this->cancel($b['admin'], $order, $items[1], 4, 4)->assertOk();
        $this->assertSame('dibatalkan', $items[1]->fresh()->status);
        $this->assertSame('terkirim', $order->fresh()->status, 'only delivered goods remain → order is complete');
        // The emptied shipment is not offered for dispatch any more.
        $this->assertSame([], $this->actingAs($b['koordinator'])->getJson('/api/v1/dispatch')->json('data'));
    }

    public function test_authorization_is_admin_scope_only_and_cross_branch_is_refused(): void
    {
        [$b, , $order, $items] = $this->fixture();
        foreach (['kurir', 'koordinator', 'gudang', 'konsumen'] as $role) {
            $this->cancel($b[$role], $order, $items[1], 1, 4)->assertForbidden();
        }
        $other = User::factory()->agen()->create();
        $other->update(['agent_id' => $other->id]);
        $this->cancel(User::factory()->admin()->create(['agent_id' => $other->id]), $order, $items[1], 1, 4)->assertStatus(404);
        $this->assertSame(4, $items[1]->fresh()->fulfilled_quantity);
    }

    // ---- Codex remediation ------------------------------------------------------------------

    /** F01 — a split child created AFTER the freeze still hits the real ledger when cancelled. */
    public function test_f01_cancelling_a_refusal_split_child_uses_the_refund_ledger_not_the_legacy_fallback(): void
    {
        [$b, , $order, $items, $shipments, $request] = $this->fixture();
        $this->dispatchFirstLine($b, $request, $items, $shipments);
        $order->update(['paid_amount' => 14000, 'remaining_amount' => 0, 'payment_status' => 'paid', 'gross_received_amount' => 14000]);

        $snapshot = OrderFinancialSnapshot::where('order_id', $order->id)->firstOrFail();
        $checksum = $snapshot->allocation_checksum;
        $unitsBefore = OrderFinancialSnapshotUnit::where('snapshot_id', $snapshot->id)->count();

        // Refuse 2 of the 10 dispatched units → child item; two-party custody; redelivery puts it back to `diproses`.
        $r = $this->actingAs($b['kurir'])->postJson("/api/v1/shipments/{$shipments[0]->id}/items/{$items[0]->id}/refuse", ['reason' => 'tolak', 'quantity' => 2])->assertCreated()->json('data');
        $child = OrderItem::findOrFail($r['order_item_id']);
        $this->assertNull(OrderFinancialSnapshotUnit::where('snapshot_id', $snapshot->id)->where('order_item_id', $child->id)->first(), 'child owns no frozen rows (history not re-pointed)');
        $this->actingAs($b['kurir'])->postJson("/api/v1/delivery-refusals/{$r['id']}/hand-back")->assertOk();
        $this->actingAs($b['koordinator'])->postJson("/api/v1/delivery-refusals/{$r['id']}/receive")->assertOk();
        $this->actingAs($b['koordinator'])->postJson("/api/v1/delivery-refusals/{$r['id']}/disposition", ['action' => 'redelivery'])->assertOk();

        $this->cancel($b['admin'], $order, $child->fresh(), 2, 2)->assertOk();

        // Units come from the ancestor's frozen pool, consumed (not rewritten); the ledger obligation exists.
        $this->assertSame(2, OrderFinancialSnapshotUnit::where('snapshot_id', $snapshot->id)->where('order_item_id', $items[0]->id)->whereNotNull('consumed_at')->count());
        $snapshot->refresh();
        $this->assertSame([$checksum, $unitsBefore], [$snapshot->allocation_checksum, OrderFinancialSnapshotUnit::where('snapshot_id', $snapshot->id)->count()]);
        $adjustment = \App\Models\OrderItemAdjustment::where('order_item_id', $child->id)->firstOrFail();
        $obligation = \App\Models\RefundObligation::where('obligation_key', 'adjustment:'.$adjustment->id)->first();
        $this->assertNotNull($obligation, 'refund obligation recorded in the ledger');

        // Processing settles through the ledger exactly once; a retry changes nothing.
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $b['agent']->id]);
        $this->actingAs($keuangan)->patchJson("/api/v1/admin/order-refunds/{$adjustment->id}/status", ['refund_status' => 'processed'])->assertOk();
        $paidAfter = (float) $order->fresh()->paid_amount;
        $this->actingAs($keuangan)->patchJson("/api/v1/admin/order-refunds/{$adjustment->id}/status", ['refund_status' => 'processed'])->assertStatus(422);
        $this->assertEquals($paidAfter, (float) $order->fresh()->paid_amount);
        $this->assertLessThan(14000, $paidAfter);
        $this->assertSame(1, \App\Models\RefundObligation::where('obligation_key', 'adjustment:'.$adjustment->id)->count());
        // F09: cancellation and the processed refund each left a balance snapshot on the single invoice.
        $last = \App\Models\OrderInvoiceSnapshot::where('order_id', $order->id)->orderByDesc('version')->firstOrFail();
        $this->assertEquals([(float) $order->fresh()->total_amount, (float) $order->fresh()->paid_amount], [(float) $last->total_amount, (float) $last->verified_paid_amount]);
        $this->assertGreaterThanOrEqual(2, \App\Models\OrderInvoiceSnapshot::where('order_id', $order->id)->count());
    }

    /** F02 — the office bulk override cannot dispatch unassigned/unaccepted Kurir Toko, nor deliver refused goods. */
    public function test_f02_bulk_status_cannot_bypass_receipt_assignment_or_open_refusal(): void
    {
        [$b, , $order, $items, $shipments, $request] = $this->fixture();
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'dikirim'])->assertStatus(422);
        $this->assertSame('diproses', $order->fresh()->status);

        // Accepted but not assigned → still refused.
        $this->receive($b, $request, $items[0], 10);
        $this->receive($b, $request, $items[1], 4);
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'dikirim'])->assertStatus(422);

        // Assigned + accepted → the legitimate courier path works; then a refusal blocks bulk delivery.
        foreach ([0, 1] as $i) {
            $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$shipments[$i]->id}/courier", ['courier_id' => $b['courier']->id])->assertOk();
        }
        $this->actingAs($b['kurir'])->patchJson("/api/v1/shipments/{$shipments[0]->id}/status", ['status' => 'dikirim'])->assertOk();
        $this->actingAs($b['kurir'])->patchJson("/api/v1/shipments/{$shipments[1]->id}/status", ['status' => 'dikirim'])->assertOk();
        $this->actingAs($b['kurir'])->postJson("/api/v1/shipments/{$shipments[0]->id}/items/{$items[0]->id}/refuse", ['reason' => 'tolak'])->assertCreated();
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'terkirim'])->assertStatus(422);
        $this->assertSame('dikirim', $items[0]->fresh()->status);
    }

    /** F06 — accepted stock of one line never authorises (or is reversed under) another line of the same product. */
    public function test_f06_accepted_allocation_is_per_line_for_assignment_and_cancellation(): void
    {
        [$b, $product, $order, $items, $shipments, $request] = $this->fixture();
        $this->receive($b, $request, $items[0], 10); // line X fully accepted; line Y (same product, 4) not at all

        $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$shipments[1]->id}/courier", ['courier_id' => $b['courier']->id])->assertStatus(422);
        $this->actingAs($b['koordinator'])->patchJson("/api/v1/shipments/{$shipments[0]->id}/courier", ['courier_id' => $b['courier']->id])->assertOk();

        $shipping = fn () => (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $b['agent']->id)->where('product_id', $product->id)->where('stock_type', 'shipping')->sum('quantity');
        $this->assertSame(10, $shipping());
        $this->cancel($b['admin'], $order, $items[1], 4, 4)->assertOk(); // cancelling Y reverses nothing of X's stock
        $this->assertSame(10, $shipping(), 'X\'s accepted units untouched');
        $this->assertSame(10, $items[0]->fresh()->fulfilled_quantity);
    }
}
