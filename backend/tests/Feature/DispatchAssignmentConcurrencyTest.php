<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Models\StockRequestProposal;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

/**
 * Codex Audit #1 — A1-03/A1-04 deterministic two-connection races.
 *
 * A1-03  two simultaneous assignments to the same shipment → exactly one
 *        winner (the second re-read sees courier_id set).
 * A1-04  a Gudang fulfillment proposal racing a dispatch assignment on the
 *        SAME order. The canonical Order-first lock serializes them:
 *          - assignment commits first → proposal fails, no proposal row
 *          - proposal gets the boundary first → assignment may follow and
 *            the intended business result holds; no deadlock.
 * A1-04(C) order cancelled/status-changed while proposal in flight → stale
 *        proposal rejected (covered by GudangOrderQueueTest already); this
 *        file asserts the serialization invariant that prevents both the
 *        assignment-then-proposal and proposal-then-assignment races.
 */
class DispatchAssignmentConcurrencyTest extends TestCase
{
    use HasTestRegion;
    use RestoresIsolatedTestDatabase;
    use \Tests\Support\AcceptsWarehouseDemand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    /** @return array{agen:User, gudang:User, admin:User, konsumen:User, koordinator:User, kurirA:Courier, kurirB:Courier, order:Order, request:StockRequest} */
    /** @param bool $accepted REM-003: assignment requires the Koordinator's accepted receipt — default fully accepted. */
    private function fixture(bool $accepted = true): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Race Branch A', 'address' => 'Test', 'latitude' => -6.2, 'longitude' => 106.8166]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agen->id, 'name' => 'Gudang Race']);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        $koordinator = User::factory()->koordinatorKurir()->create(['agent_id' => $agen->id, 'name' => 'Koord Race']);
        $kurirUserA = User::factory()->kurir()->create(['agent_id' => $agen->id, 'name' => 'Kurir Race A']);
        $kurirA = Courier::create(['type' => 'internal', 'user_id' => $kurirUserA->id, 'agent_id' => $agen->id, 'name' => $kurirUserA->name, 'is_active' => true]);
        $kurirUserB = User::factory()->kurir()->create(['agent_id' => $agen->id, 'name' => 'Kurir Race B']);
        $kurirB = Courier::create(['type' => 'internal', 'user_id' => $kurirUserB->id, 'agent_id' => $agen->id, 'name' => $kurirUserB->name, 'is_active' => true]);

        $product = Product::create([
            'sku' => 'RACE-A1-'.Str::uuid(), 'name' => 'Race Product A1', 'slug' => 'race-a1-'.uniqid(),
            'has_variations' => false, 'base_price' => 20000, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 50, 'quantity_reserved' => 0]);

        $response = $this->actingAs($agen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'konsumen_id' => $konsumen->id,
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);
        $response->assertCreated();
        $order = Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
        $order->refresh();

        // Internal Stock Request is created canonically on first diproses (ordering service).
        $request = StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->firstOrFail();
        if ($accepted) {
            // These races include quantity reductions of accepted lines, which reverse the accepted units out of Shipping.
            $this->acceptWarehouseDemand($order->id, null, true);
        }
        $requestItem = StockRequestItem::where('stock_request_id', $request->id)->first();

        return compact('agen', 'gudang', 'admin', 'konsumen', 'koordinator', 'kurirA', 'kurirB', 'order', 'request', 'requestItem');
    }

    public function test_concurrent_return_assignments_have_one_winner_and_one_conflict(): void
    {
        $f = $this->fixture();
        $item = $f['order']->items()->firstOrFail();
        $item->update(['status' => 'pengembalian']);
        $return = \App\Models\ReturnRequest::create([
            'order_id' => $f['order']->id, 'requested_by' => $f['konsumen']->id,
            'reason' => 'Race fixture', 'status' => 'requested', 'total_refund_amount' => 0,
        ]);
        $line = \App\Models\ReturnItem::create([
            'return_id' => $return->id, 'order_item_id' => $item->id,
            'quantity_returned' => 1, 'refund_amount' => 0, 'status' => 'pending',
        ]);
        $base = ['op' => 'return-assign', 'actor_id' => $f['koordinator']->id, 'item_id' => $line->id];
        $report = (new \Tests\Support\ConcurrencyHarness)->runDispatchRace(
            $base + ['courier_id' => $f['kurirA']->id], $base + ['courier_id' => $f['kurirB']->id],
        );
        $this->assertTrue($report['different_connections']);
        $this->assertTrue($report['true_overlap']);
        $this->assertSame('success', $report['a']['outcome'], json_encode($report));
        $this->assertSame(409, $report['b']['business_status'], json_encode($report));
        $this->assertSame($f['kurirA']->id, $line->fresh()->courier_id);
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('activity_logs')->where('event', 'return_item.courier_assigned')->count());
    }

    public function test_concurrent_same_shipment_assignments_have_exactly_one_winner(): void
    {
        $f = $this->fixture();
        $shipment = Shipment::withoutGlobalScopes()->where('order_id', $f['order']->id)->firstOrFail();

        $sideA = [
            'op' => 'assign', 'actor_id' => $f['koordinator']->id, 'order_id' => $f['order']->id,
            'courier_id' => $f['kurirA']->id,
        ];
        $sideB = [
            'op' => 'assign', 'actor_id' => $f['koordinator']->id, 'order_id' => $f['order']->id,
            'courier_id' => $f['kurirB']->id,
        ];

        $report = (new \Tests\Support\ConcurrencyHarness)->runDispatchRace($sideA, $sideB);

        $winners = collect([$report['a'], $report['b']])->where('outcome', 'success');
        $this->assertTrue($report['different_connections']);
        $this->assertTrue($report['true_overlap']);
        $this->assertSame(1, $winners->count(), 'exactly one assignment must win; the loser sees courier_id set');
        $this->assertCount(1, collect([$report['a'], $report['b']])->where('outcome', 'failed'));
        $this->assertContains((int) Shipment::withoutGlobalScopes()->find($shipment->id)->courier_id, [(int) $f['kurirA']->id, (int) $f['kurirB']->id]);
    }

    public function test_assignment_of_fully_accepted_demand_wins_and_a_proposal_finds_nothing_outstanding(): void
    {
        // REM-001/003: once the Koordinator accepted all demand, nothing is outstanding for Gudang,
        // so a proposal racing the assignment is refused in EITHER lock order and persists nothing,
        // while the assignment lands exactly once. Neither side may deadlock.
        $f = $this->fixture();
        $requestItem = StockRequestItem::where('stock_request_id', $f['request']->id)->first();

        $report = (new \Tests\Support\ConcurrencyHarness)->runDispatchRace(
            ['op' => 'assign', 'actor_id' => $f['koordinator']->id, 'order_id' => $f['order']->id, 'courier_id' => $f['kurirA']->id],
            ['op' => 'propose', 'actor_id' => $f['gudang']->id, 'order_id' => $f['order']->id, 'request_id' => $f['request']->id, 'item_id' => $requestItem->id, 'quantity' => 1],
        );

        foreach (['a', 'b'] as $side) {
            $this->assertNotContains((string) ($report[$side]['error_code'] ?? ''), ['1213', '1205'], json_encode($report[$side]));
        }
        $this->assertSame('success', $report['a']['outcome'], json_encode($report));
        $this->assertSame('failed', $report['b']['outcome'], 'nothing outstanding: the proposal must be refused');
        $this->assertSame(0, StockRequestProposal::withoutGlobalScopes()->where('stock_request_id', $f['request']->id)->count());
        $this->assertSame($f['kurirA']->id, (int) Shipment::withoutGlobalScopes()->where('order_id', $f['order']->id)->value('courier_id'));
    }

    public function test_proposal_and_unaccepted_assignment_serialize_without_deadlock(): void
    {
        // Demand NOT yet accepted: Gudang's proposal succeeds (it is only "prepared"), the assignment
        // is refused 422 (no accepted receipt) — in either lock order, with no deadlock.
        $f = $this->fixture(false);
        $requestItem = StockRequestItem::where('stock_request_id', $f['request']->id)->first();

        $report = (new \Tests\Support\ConcurrencyHarness)->runDispatchRace(
            ['op' => 'propose', 'actor_id' => $f['gudang']->id, 'order_id' => $f['order']->id, 'request_id' => $f['request']->id, 'item_id' => $requestItem->id, 'quantity' => 1],
            ['op' => 'assign', 'actor_id' => $f['koordinator']->id, 'order_id' => $f['order']->id, 'courier_id' => $f['kurirA']->id],
        );

        foreach (['a', 'b'] as $side) {
            $this->assertNotContains((string) ($report[$side]['error_code'] ?? ''), ['1213', '1205'], json_encode($report[$side]));
        }
        $this->assertSame('success', $report['a']['outcome'], json_encode($report));
        $this->assertSame('failed', $report['b']['outcome'], json_encode($report));
        $this->assertSame(422, $report['b']['business_status'], json_encode($report));
        $this->assertSame(1, StockRequestProposal::withoutGlobalScopes()->where('stock_request_id', $f['request']->id)->count());
        $this->assertNull(Shipment::withoutGlobalScopes()->where('order_id', $f['order']->id)->value('courier_id'));
    }

    /**
     * Owner decision (2026-11-08): a kurir is assignment-only — pickup never claims. The race between an
     * authorized dispatch assignment and a pickup attempt is therefore exercised in BOTH orders for:
     *  - the courier being assigned (pickup succeeds only AFTER the assignment commits; a pickup that
     *    wins the Order lock first is refused 403 and rolls back, then the assignment lands), and
     *  - a courier who is NOT the assignee (pickup is refused 403 in both orders and can never
     *    overwrite or take over the executor).
     */
    public function test_assignment_and_pickup_serialize_in_both_orders_without_executor_overwrite(): void
    {
        foreach ([true, false] as $assignmentFirst) {
            foreach ([true, false] as $pickerIsAssignee) {
                $f = $this->fixture();
                // Assignee is kurirB; the picker is kurirB (authorized) or kurirA (never assigned).
                $assign = ['op' => 'assign', 'actor_id' => $f['koordinator']->id, 'order_id' => $f['order']->id, 'courier_id' => $f['kurirB']->id];
                $picker = $pickerIsAssignee ? $f['kurirB'] : $f['kurirA'];
                $pickup = ['op' => 'pickup', 'actor_id' => $picker->user_id, 'order_id' => $f['order']->id];
                $ctx = json_encode(['assignmentFirst' => $assignmentFirst, 'pickerIsAssignee' => $pickerIsAssignee]);

                $report = (new \Tests\Support\ConcurrencyHarness)->runDispatchRace($assignmentFirst ? $assign : $pickup, $assignmentFirst ? $pickup : $assign);
                $this->assertTrue($report['different_connections'], $ctx);
                $this->assertTrue($report['true_overlap'], $ctx.json_encode($report));
                foreach (['a', 'b'] as $side) {
                    $this->assertNotContains((int) ($report[$side]['error_code'] ?? 0), [1213, 1205], $ctx.json_encode($report));
                }

                $assignSide = $assignmentFirst ? 'a' : 'b';
                $pickupSide = $assignmentFirst ? 'b' : 'a';
                $shipment = Shipment::withoutGlobalScopes()->where('order_id', $f['order']->id)->firstOrFail();
                $item = OrderItem::where('order_id', $f['order']->id)->firstOrFail();

                // The dispatch assignment always wins and the executor is always kurirB — never overwritten.
                $this->assertSame('success', $report[$assignSide]['outcome'], $ctx.json_encode($report));
                $this->assertSame($f['kurirB']->id, $shipment->courier_id, $ctx);

                if ($pickerIsAssignee && $assignmentFirst) {
                    // Assignment committed first → the now-authorized executor's pickup succeeds.
                    $this->assertSame('success', $report[$pickupSide]['outcome'], $ctx.json_encode($report));
                    $this->assertSame('dikirim', $item->status, $ctx);
                    $this->assertSame('in_transit', $shipment->status, $ctx);
                } else {
                    // Pickup won the Order lock before any assignment (or the picker is not the assignee):
                    // refused 403, rolled back, nothing moved.
                    $this->assertSame('failed', $report[$pickupSide]['outcome'], $ctx.json_encode($report));
                    $this->assertSame(403, $report[$pickupSide]['business_status'], $ctx.json_encode($report));
                    $this->assertNotSame('dikirim', $item->status, $ctx);
                    $this->assertSame('pending', $shipment->status, $ctx);
                }
            }
        }
    }

    /**
     * Delivery pickup (by the ASSIGNED courier) and an admin quantity adjustment serialize on the Order boundary
     * in both orders. Negative: a courier who is not the assignee can never pick up in either order, and the
     * adjustment still lands cleanly.
     */
    public function test_delivery_and_quantity_adjustment_share_order_boundary_in_both_orders(): void
    {
        foreach ([true, false] as $pickupFirst) {
            foreach ([true, false] as $pickerIsAssignee) {
                $f = $this->fixture();
                // Pre-recorded authorized assignment (kurirA) — the only way a kurir may pick up.
                Shipment::withoutGlobalScopes()->where('order_id', $f['order']->id)->update(['courier_id' => $f['kurirA']->id]);
                $picker = $pickerIsAssignee ? $f['kurirA'] : $f['kurirB'];
                $pickup = ['op' => 'pickup', 'actor_id' => $picker->user_id, 'order_id' => $f['order']->id];
                $adjust = ['op' => 'adjust', 'actor_id' => $f['admin']->id, 'order_id' => $f['order']->id, 'quantity' => 1];
                $ctx = json_encode(['pickupFirst' => $pickupFirst, 'pickerIsAssignee' => $pickerIsAssignee]);

                $report = (new \Tests\Support\ConcurrencyHarness)->runDispatchRace($pickupFirst ? $pickup : $adjust, $pickupFirst ? $adjust : $pickup);
                $this->assertTrue($report['true_overlap'], $ctx.json_encode($report));
                foreach (['a', 'b'] as $side) {
                    $this->assertNotContains((int) ($report[$side]['error_code'] ?? 0), [1213, 1205], $ctx.json_encode($report));
                }
                $pickupSide = $pickupFirst ? 'a' : 'b';
                $adjustSide = $pickupFirst ? 'b' : 'a';
                $item = OrderItem::where('order_id', $f['order']->id)->firstOrFail();
                $shipment = Shipment::withoutGlobalScopes()->where('order_id', $f['order']->id)->firstOrFail();
                $this->assertSame($f['kurirA']->id, $shipment->courier_id, $ctx.' executor must not change');

                if ($pickerIsAssignee) {
                    $this->assertSame('success', $report[$pickupSide]['outcome'], $ctx.json_encode($report));
                    // Pickup first → the adjustment is then refused (already shipped) and quantity stays 2;
                    // adjustment first → both succeed and the shipped quantity is 1.
                    $this->assertSame($pickupFirst ? 'failed' : 'success', $report[$adjustSide]['outcome'], $ctx.json_encode($report));
                    $this->assertSame($pickupFirst ? 2 : 1, $item->fulfilled_quantity, $ctx);
                    $this->assertSame('dikirim', $item->status, $ctx);
                } else {
                    // Not the assignee: pickup refused 403 in both orders; the adjustment always succeeds.
                    $this->assertSame('failed', $report[$pickupSide]['outcome'], $ctx.json_encode($report));
                    $this->assertSame(403, $report[$pickupSide]['business_status'], $ctx.json_encode($report));
                    $this->assertSame('success', $report[$adjustSide]['outcome'], $ctx.json_encode($report));
                    $this->assertSame(1, $item->fulfilled_quantity, $ctx);
                    $this->assertNotSame('dikirim', $item->status, $ctx);
                }
            }
        }
    }

    public function test_warehouse_approval_and_checkout_share_canonical_agent_capacity_lock_order(): void
    {
        $f = $this->fixture(false);
        $item = $f['request']->items()->firstOrFail();
        \App\Models\WarehouseStock::create(['agent_id' => $f['agen']->id, 'product_id' => $item->product_id, 'stock_type' => 'transit', 'quantity' => 50]);
        $proposal = app(\App\Services\Stock\StockRequestProposalService::class)->propose($f['gudang'], $f['request'], [['item_id' => $item->id, 'quantity' => 1, 'shortage_reason' => 'uji konkurensi: sisa menyusul']]);
        $report = (new \Tests\Support\ConcurrencyHarness)->runServiceRace(
            ['op' => 'proposal-approve', 'actor_id' => $f['admin']->id, 'subject_id' => $proposal->id, 'extra' => ['capacity_race_side' => 'a']],
            ['op' => 'checkout-order', 'actor_id' => $f['konsumen']->id, 'extra' => ['capacity_race_side' => 'b', 'buyer_id' => $f['konsumen']->id, 'lines' => [['product_id' => $item->product_id, 'quantity' => 1]], 'destination' => ['recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Capacity race', 'village_id' => null, 'latitude' => -6.2, 'longitude' => 106.8]]],
        );
        $this->assertTrue($report['different_connections']);
        foreach (['a', 'b'] as $side) {
            $this->assertNotContains((int) ($report[$side]['error_code'] ?? 0), [1213, 1205], json_encode($report));
            $this->assertSame('success', $report[$side]['outcome'], json_encode($report));
        }
        $stock = ProductStock::withoutGlobalScopes()->where('agent_id', $f['agen']->id)->where('product_id', $item->product_id)->firstOrFail();
        $this->assertSame(2, $stock->quantity_reserved);
        $this->assertSame(49, (int) \App\Models\WarehouseStock::where('agent_id', $f['agen']->id)->where('stock_type', 'transit')->value('quantity'));
        $this->assertSame(1, (int) \App\Models\WarehouseStock::where('agent_id', $f['agen']->id)->where('stock_type', 'shipping')->value('quantity'));
    }

    public function test_assignment_wins_then_sc03_rejects_without_partial_stock_demand(): void
    {
        $f = $this->fixture();
        $product = $f['order']->items()->firstOrFail()->product_id;
        $beforeReserved = ProductStock::where('agent_id', $f['agen']->id)->where('product_id', $product)->value('quantity_reserved');
        $report = (new \Tests\Support\ConcurrencyHarness)->runDispatchRace(
            ['op' => 'assign', 'actor_id' => $f['koordinator']->id, 'order_id' => $f['order']->id, 'courier_id' => $f['kurirA']->id],
            ['op' => 'add-line', 'actor_id' => $f['admin']->id, 'order_id' => $f['order']->id, 'item_id' => $product],
        );
        $this->assertTrue($report['different_connections']);
        $this->assertTrue($report['true_overlap']);
        $this->assertSame('success', $report['a']['outcome'], json_encode($report));
        $this->assertSame('failed', $report['b']['outcome'], json_encode($report));
        $this->assertSame(422, $report['b']['business_status']);
        $this->assertSame(__('messages.order.line_addition_executor_assigned'), $report['b']['message']);
        foreach (['a', 'b'] as $side) $this->assertNotContains((int) ($report[$side]['error_code'] ?? 0), [1213, 1205]);
        $this->assertSame(1, OrderItem::where('order_id', $f['order']->id)->count());
        $this->assertSame(1, Shipment::where('order_id', $f['order']->id)->count());
        $this->assertSame(1, $f['request']->items()->count());
        $this->assertSame($beforeReserved, ProductStock::where('agent_id', $f['agen']->id)->where('product_id', $product)->value('quantity_reserved'));
    }

    /**
     * Z.11 — two CONCURRENT compatible reschedules into the same target date must serialize on
     * the canonical Order lock and leave exactly ONE canonical shipment for that date — never a
     * racy duplicate second unit, and never a deadlock.
     */
    public function test_concurrent_compatible_reschedules_into_one_date_leave_a_single_canonical_shipment(): void
    {
        $f = $this->fixture(false);
        $agen = $f['agen'];
        $admin = $f['admin'];
        $konsumen = $f['konsumen'];

        $productB = Product::create([
            'sku' => 'RACE-B1-'.Str::uuid(), 'name' => 'Race Product B1', 'slug' => 'race-b1-'.uniqid(),
            'has_variations' => false, 'base_price' => 20000, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $productB->id, 'quantity_on_hand' => 50, 'quantity_reserved' => 0]);

        // One order, two lines on one date: they start on a single canonical shipment.
        $created = $this->actingAs($agen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'konsumen_id' => $konsumen->id,
            'payment_method_code' => 'cod',
            'items' => [
                ['product_id' => $f['order']->items()->firstOrFail()->product_id, 'quantity' => 1],
                ['product_id' => $productB->id, 'quantity' => 1],
            ],
            'recipient_name' => 'Didi', 'recipient_phone' => '0813', 'address_line' => 'Jl. Asia',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);
        $created->assertCreated();

        $raceOrder = Order::withoutGlobalScopes()->findOrFail($created->json('data.id'));
        Shipment::where('order_id', $raceOrder->id)->update(['shipping_provider_code' => 'openroute']);
        $raceItems = OrderItem::query()->where('order_id', $raceOrder->id)->orderBy('id')->get();
        $this->assertCount(2, $raceItems);
        $this->assertSame($raceItems[0]->shipment_id, $raceItems[1]->shipment_id, 'same date starts grouped');

        // Split them first (sequentially) so the race has two separate units to collapse.
        app(\App\Services\Order\OrderFulfillmentService::class)->rescheduleItemDeliveryDate(
            $raceItems[0], now()->addDays(7)->toDateString(), $admin, 'Pre-race split',
        );
        $raceItems = OrderItem::query()->where('order_id', $raceOrder->id)->orderBy('id')->get();
        $this->assertNotSame($raceItems[0]->shipment_id, $raceItems[1]->shipment_id);

        $report = (new \Tests\Support\ConcurrencyHarness)->runDispatchRace(
            ['op' => 'reschedule', 'actor_id' => $admin->id, 'order_id' => $raceOrder->id, 'item_id' => $raceItems[0]->id, 'date' => now()->addDays(10)->toDateString()],
            ['op' => 'reschedule', 'actor_id' => $admin->id, 'order_id' => $raceOrder->id, 'item_id' => $raceItems[1]->id, 'date' => now()->addDays(10)->toDateString()],
        );
        $this->assertTrue($report['different_connections']);
        $this->assertTrue($report['true_overlap']);
        $this->assertSame('success', $report['a']['outcome'], json_encode($report));
        $this->assertSame('success', $report['b']['outcome'], json_encode($report));
        foreach (['a', 'b'] as $side) {
            $this->assertNotContains((int) ($report[$side]['error_code'] ?? 0), [1213, 1205]);
        }

        // Exactly one canonical 2026-10-20 shipment holds both items; the old units are gone.
        $final = OrderItem::query()->where('order_id', $raceOrder->id)->orderBy('id')->get();
        $this->assertSame(now()->addDays(10)->toDateString(), $final[0]->requested_delivery_date?->toDateString());
        $this->assertSame(now()->addDays(10)->toDateString(), $final[1]->requested_delivery_date?->toDateString());
        $this->assertSame($final[0]->shipment_id, $final[1]->shipment_id, 'one canonical target unit after the race');
        $this->assertSame(1, Shipment::where('order_id', $raceOrder->id)->count());
    }

    public function test_sc03_wins_then_assignment_waits_for_added_demand_fulfillment(): void
    {
        $f = $this->fixture(false);
        $product = $f['order']->items()->firstOrFail()->product_id;
        \App\Models\WarehouseStock::create(['agent_id' => $f['agen']->id, 'product_id' => $product, 'stock_type' => 'transit', 'quantity' => 50]);
        $report = (new \Tests\Support\ConcurrencyHarness)->runDispatchRace(
            ['op' => 'add-line', 'actor_id' => $f['admin']->id, 'order_id' => $f['order']->id, 'item_id' => $product, 'quantity' => 2],
            ['op' => 'assign', 'actor_id' => $f['koordinator']->id, 'order_id' => $f['order']->id, 'courier_id' => $f['kurirA']->id],
        );
        $this->assertTrue($report['different_connections']);
        $this->assertTrue($report['true_overlap']);
        $this->assertSame('success', $report['a']['outcome'], json_encode($report));
        $this->assertSame('failed', $report['b']['outcome'], json_encode($report));
        $this->assertSame(422, $report['b']['business_status']);
        $this->assertSame(__('messages.courier.sc03_fulfillment_pending'), $report['b']['message']);
        foreach (['a', 'b'] as $side) $this->assertNotContains((int) ($report[$side]['error_code'] ?? 0), [1213, 1205]);
        $this->assertSame(2, OrderItem::where('order_id', $f['order']->id)->count());
        $this->assertSame(2, Shipment::where('order_id', $f['order']->id)->count());
        $this->assertSame(2, $f['request']->items()->count());
        $this->assertSame(0, Shipment::where('order_id', $f['order']->id)->whereNotNull('courier_id')->count());
        // Ordinary couriers cannot claim unassigned work, even with pending demand.
        $shipment = Shipment::where('order_id', $f['order']->id)->firstOrFail();
        $this->actingAs(User::findOrFail($f['kurirA']->user_id))->patchJson('/api/v1/shipments/'.$shipment->id.'/status', ['status' => 'dikirim'])
            ->assertForbidden();
        // UAT-006: the Koordinator's "Ambil Pengiriman" (courier_id: 0 self-executor
        // sentinel) must obey the SAME gate — an explicit self-take may never be a
        // way around the fulfilment-before-dispatch sequence, and must leave no
        // partially-assigned executor behind.
        $this->actingAs($f['koordinator'])->patchJson('/api/v1/shipments/'.$shipment->id.'/courier', ['courier_id' => 0])
            ->assertUnprocessable()->assertJsonPath('message', __('messages.courier.sc03_fulfillment_pending'));
        $this->assertNull($shipment->fresh()->courier_id, 'a rejected self-take must not assign an executor');
        // Gudang retains whole-order queue/detail/proposal access while fulfilment is pending.
        $this->actingAs($f['gudang'])->getJson('/api/v1/warehouse/orders/diproses')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($f['gudang'])->getJson('/api/v1/orders/'.$f['order']->id)->assertOk();
        // Added-line split descendants must not escape the fulfilment gate.
        // Splitting is the reschedule service, which is LOCKED to Kurir Online (Human 2026-10-07),
        // so this order's shipments are put in that canonical state first.
        Shipment::where('order_id', $f['order']->id)->update(['shipping_provider_code' => 'openroute']);
        $added = OrderItem::findOrFail($report['a']['created_item_id']);
        $child = app(\App\Services\Order\OrderFulfillmentService::class)->rescheduleItemDeliveryDate(
            $added, now()->addDays(3)->toDateString(), $f['admin'], 'Split added demand', 1,
        );
        $this->assertNull($child->idempotency_key);
        $this->assertSame($added->id, $child->split_from_order_item_id);
        $firstItems = $f['request']->items()->where('order_item_id', '!=', $child->id)->get()
            ->map(fn ($item) => ['item_id' => $item->id, 'quantity' => $item->remaining_qty])->all();
        $proposal = app(\App\Services\Stock\StockRequestProposalService::class)->propose($f['gudang'], $f['request']->fresh(), $firstItems);
        app(\App\Services\Stock\StockRequestProposalService::class)->approve($f['admin'], $proposal);
        try {
            app(\App\Services\Order\CourierService::class)->assignCourier($shipment->fresh(), $f['kurirA'], $f['koordinator']);
            $this->fail('Pending added-line descendant must block assignment');
        } catch (\App\Exceptions\ApiException $e) {
            $this->assertSame(422, $e->status());
            $this->assertSame(__('messages.courier.sc03_fulfillment_pending'), $e->getMessage());
        }
        $childDemand = $f['request']->items()->where('order_item_id', $child->id)->firstOrFail();
        $proposal = app(\App\Services\Stock\StockRequestProposalService::class)->propose($f['gudang'], $f['request']->fresh(), [['item_id' => $childDemand->id, 'quantity' => 1, 'shortage_reason' => 'uji konkurensi: sisa menyusul']]);
        app(\App\Services\Stock\StockRequestProposalService::class)->approve($f['admin'], $proposal);
        app(\App\Services\Order\CourierService::class)->assignCourier($shipment->fresh(), $f['kurirA'], $f['koordinator']);
        $this->assertSame($f['kurirA']->id, $shipment->fresh()->courier_id);
        // UAT-006: once the added demand is fulfilled the gate lifts and the
        // Koordinator may self-take a shipment through the existing courier_id: 0
        // contract — resolving to THEIR OWN executor profile, never another
        // courier's, and never leaving a second claimable executor behind.
        $sibling = Shipment::where('order_id', $f['order']->id)->where('id', '!=', $shipment->id)->firstOrFail();
        $this->assertNull($sibling->courier_id);
        $this->actingAs($f['koordinator'])->patchJson('/api/v1/shipments/'.$sibling->id.'/courier', ['courier_id' => 0])->assertOk();
        $selfProfile = Courier::whereKey($sibling->fresh()->courier_id)->firstOrFail();
        $this->assertSame($f['koordinator']->id, $selfProfile->user_id);
        $this->assertSame($f['agen']->id, $selfProfile->agent_id);
        // A foreign coordinator may not steal that just-claimed shipment.
        $foreign = User::factory()->koordinatorKurir()->create(['agent_id' => $f['agen']->id, 'parent_id' => $f['agen']->id]);
        $this->actingAs($foreign)->patchJson('/api/v1/shipments/'.$sibling->id.'/courier', ['courier_id' => 0])
            ->assertUnprocessable()->assertJsonPath('message', __('messages.courier.already_assigned'));
        $this->assertSame($selfProfile->id, $sibling->fresh()->courier_id);
        $this->actingAs($f['gudang'])->getJson('/api/v1/warehouse/orders/diproses')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($f['gudang'])->getJson('/api/v1/orders/'.$f['order']->id)->assertForbidden();
    }

}