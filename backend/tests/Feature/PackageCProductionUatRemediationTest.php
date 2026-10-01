<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Services\Order\CourierService;
use App\Services\Order\OrderFulfillmentService;
use App\Services\Order\ShipmentGroupingService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * Package C — PRODUCTION UAT round-2 remediation (independent Codex review F01–F06, non-concurrency
 * parts). Concurrency parts live in PackageCShipmentConcurrencyTest.
 *
 *  F02 — nonzero shipping-fee snapshot conservation (numeric) across merge/reschedule/split/regroup.
 *  F03 — an assigned tracking/resi identity makes a shipment non-mutable (never deleted/rewritten).
 *  F04 — a committed shipment's operational identity is never silently redated by reschedule.
 *  F05 — every order-scoped writer takes the Order row lock FIRST (deterministic lock-order assertion).
 *  F06 — shipments:regroup reports failures and returns a non-zero exit status.
 */
class PackageCProductionUatRemediationTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    /** @return array{agen:User, admin:User, gudang:User, konsumen:User} */
    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'UAT2', 'address' => 'x', 'latitude' => -6.2, 'longitude' => 106.8166]);

        return [
            'agen' => $agen,
            'admin' => User::factory()->admin()->create(['agent_id' => $agen->id]),
            'gudang' => User::factory()->gudang()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]),
            'konsumen' => User::factory()->konsumen()->create(['agent_id' => $agen->id]),
        ];
    }

    private function product(User $agen, string $name, int $price = 10000, int $stock = 50): Product
    {
        $product = Product::create(['sku' => 'UAT2-'.Str::uuid(), 'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(), 'has_variations' => false, 'base_price' => $price, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => $stock, 'quantity_reserved' => 0]);

        return $product;
    }

    private function transit(User $agen, Product $product, int $qty = 50): void
    {
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => $qty]);
        WarehouseSetting::firstOrCreate(['agent_id' => $agen->id], ['factory_plan_enabled' => false]);
    }

    /** @param array<int, array{0:Product,1:int}> $lines */
    private function order(User $konsumen, array $lines, ?string $date = null): Order
    {
        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', array_filter([
            'payment_method_code' => 'cod',
            'items' => array_map(fn ($l) => ['product_id' => $l[0]->id, 'quantity' => $l[1]], $lines),
            'delivery_date' => $date,
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ]));
        $response->assertCreated();

        return Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
    }

    private function item(Order $order, Product $product): OrderItem
    {
        return OrderItem::where('order_id', $order->id)->where('product_id', $product->id)->firstOrFail();
    }

    /**
     * Force the checkout shipping-fee snapshot onto a chosen carrier shipment and create one extra
     * mutable same-date shell (a legacy per-item shipment), so fee-conservation is deterministic and
     * independent of the region-configured quote.
     *
     * @return array{0:Shipment,1:Shipment} [carrier, empty shell]
     */
    private function seedLegacyPair(Order $order, float $fee = 25000.0): array
    {
        $base = Shipment::where('order_id', $order->id)->orderBy('id')->firstOrFail();
        Shipment::where('order_id', $order->id)->update(['shipping_fee_snapshot' => 0, 'rate_per_km' => null]);
        $base->update(['shipping_fee_snapshot' => $fee, 'rate_per_km' => 5000]);

        $shell = $base->fresh()->replicate(['shipping_fee_snapshot', 'rate_per_km']);
        $shell->save();

        return [$base->fresh(), $shell->fresh()];
    }

    private function courier(User $agen): Courier
    {
        return Courier::create(['type' => 'internal', 'user_id' => User::factory()->kurir()->create(['agent_id' => $agen->id])->id, 'agent_id' => $agen->id, 'name' => 'K', 'is_active' => true]);
    }

    private function shipmentFeeTotal(Order $order): float
    {
        return (float) Shipment::where('order_id', $order->id)->sum('shipping_fee_snapshot');
    }

    // ===================== F02 — shipping-fee conservation =====================

    public function test_regroup_keeps_the_nonzero_fee_regardless_of_source_target_id_order(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();

        // Scenario 1: carrier has the LOWER id (it is the target) — a higher-id zero source merges into it.
        [$a, $b] = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $order = $this->order($konsumen, [[$a, 1], [$b, 1]], now()->addDays(3)->toDateString());
        [$carrier, $shell] = $this->seedLegacyPair($order, 25000.0);
        $this->assertLessThan($shell->id, $carrier->id);
        $this->item($order, $b)->update(['shipment_id' => $shell->id]);

        $result = app(ShipmentGroupingService::class)->reconcileOrder($order, null);
        $this->assertSame(1, $result['merged']);
        $this->assertSame(1, Shipment::where('order_id', $order->id)->count());
        $this->assertSame(25000.0, (float) Shipment::where('order_id', $order->id)->value('shipping_fee_snapshot'));
        $this->assertSame(25000.0, $this->shipmentFeeTotal($order), 'no loss, no double count');

        // Scenario 2: carrier has the HIGHER id (the source) — the merge must still keep the fee.
        [$c, $d] = [$this->product($agen, 'C'), $this->product($agen, 'D')];
        $order2 = $this->order($konsumen, [[$c, 1], [$d, 1]], now()->addDays(3)->toDateString());
        $shipments = Shipment::where('order_id', $order2->id)->orderBy('id')->get();
        $low = $shipments->first();
        $high = $low->replicate(['shipping_fee_snapshot', 'rate_per_km']);
        $high->save();
        // the HIGHER-id shipment carries the fee
        $low->update(['shipping_fee_snapshot' => 0, 'rate_per_km' => null]);
        $high->fresh()->update(['shipping_fee_snapshot' => 25000, 'rate_per_km' => 5000]);
        $this->item($order2, $d)->update(['shipment_id' => $high->id]);
        $this->item($order2, $c)->update(['shipment_id' => $low->id]);

        app(ShipmentGroupingService::class)->reconcileOrder($order2, null);
        $this->assertSame(1, Shipment::where('order_id', $order2->id)->count());
        $this->assertSame(25000.0, (float) Shipment::where('order_id', $order2->id)->value('shipping_fee_snapshot'));
        $this->assertSame(25000.0, $this->shipmentFeeTotal($order2));
    }

    public function test_rescheduling_whole_group_moves_the_fee_and_never_loses_it(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b] = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $d1 = now()->addDays(3)->toDateString();
        $d2 = now()->addDays(6)->toDateString();
        $order = $this->order($konsumen, [[$a, 1], [$b, 1]], $d1);
        [, $shell] = $this->seedLegacyPair($order);
        $this->item($order, $b)->update(['shipment_id' => $shell->id]);
        $aItem = $this->item($order, $a);
        $bItem = $this->item($order, $b);

        $this->assertSame(25000.0, $this->shipmentFeeTotal($order));

        // Move BOTH items to d2: the original carrier and the shell are both emptied and released — the
        // fee must land on the single surviving shipment.
        $move = fn (OrderItem $i) => $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$i->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'x'])->assertOk();
        $move($aItem);
        $move($bItem);
        $this->assertSame(25000.0, $this->shipmentFeeTotal($order), 'fee conserved after whole-group move');
        $this->assertSame(1, Shipment::where('order_id', $order->id)->count(), 'emptied shells released');

        // Move both back to d1: still exactly one carrier at 25000.
        $moveBack = fn (OrderItem $i) => $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$i->id}/reschedule", ['requested_delivery_date' => $d1, 'reason' => 'x'])->assertOk();
        $moveBack($aItem);
        $moveBack($bItem);
        $this->assertSame(25000.0, $this->shipmentFeeTotal($order));
        $this->assertSame(1, Shipment::where('order_id', $order->id)->count());
    }

    public function test_fee_moves_from_a_higher_id_source_to_a_lower_id_target_without_double_count(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b] = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $d1 = now()->addDays(3)->toDateString();
        $d2 = now()->addDays(8)->toDateString();
        $order = $this->order($konsumen, [[$a, 1], [$b, 1]], $d1);

        // Build two mutable groups: S1 on d1 (fee 0) and a higher-id S2 on d2 carrying the fee.
        $s1 = Shipment::where('order_id', $order->id)->orderBy('id')->firstOrFail();
        $s1->update(['shipping_fee_snapshot' => 0, 'rate_per_km' => null]);
        $s2 = $s1->fresh()->replicate(['shipping_fee_snapshot', 'rate_per_km']);
        $s2->save();
        $s2->fresh()->update(['shipping_fee_snapshot' => 25000, 'rate_per_km' => 5000]);
        $this->item($order, $b)->update(['requested_delivery_date' => $d2, 'shipment_id' => $s2->id]);

        // Move B back to d1: it joins lower-id S1; S2 (higher id, the fee source) is released and its fee must move.
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$this->item($order, $b)->id}/reschedule", ['requested_delivery_date' => $d1, 'reason' => 'x'])->assertOk();

        $this->assertSame(1, Shipment::where('order_id', $order->id)->count());
        $this->assertSame(25000.0, (float) Shipment::where('order_id', $order->id)->value('shipping_fee_snapshot'));
        $this->assertSame(25000.0, $this->shipmentFeeTotal($order));
    }

    public function test_partial_split_conserves_the_fee_without_duplication(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        $d1 = now()->addDays(3)->toDateString();
        $d2 = now()->addDays(7)->toDateString();
        $order = $this->order($konsumen, [[$a, 3]], $d1);
        Shipment::where('order_id', $order->id)->update(['shipping_fee_snapshot' => 25000, 'rate_per_km' => 5000]);
        $item = $this->item($order, $a);

        // Split 1 of 3 to d2: the child joins a NEW d2 shipment (fee 0); the parent keeps d1 + the fee.
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'pecah', 'quantity' => 1])->assertOk();

        $this->assertSame(2, Shipment::where('order_id', $order->id)->count());
        $this->assertSame(25000.0, $this->shipmentFeeTotal($order), 'exactly one shipment still carries the fee');
        $this->assertSame(1, Shipment::where('order_id', $order->id)->where('shipping_fee_snapshot', '>', 0)->count(), 'never double-counted');
    }

    public function test_regroup_apply_is_idempotent_and_conserves_the_numeric_fee(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b, $c] = [$this->product($agen, 'A'), $this->product($agen, 'B'), $this->product($agen, 'C')];
        $order = $this->order($konsumen, [[$a, 1], [$b, 1], [$c, 1]], now()->addDays(3)->toDateString());
        [$carrier, $shell] = $this->seedLegacyPair($order);
        $shell2 = $carrier->fresh()->replicate(['shipping_fee_snapshot', 'rate_per_km']);
        $shell2->save();
        $items = OrderItem::where('order_id', $order->id)->orderBy('id')->get();
        $items[1]->update(['shipment_id' => $shell->id]);
        $items[2]->update(['shipment_id' => $shell2->id]);

        $this->artisan('shipments:regroup', ['--apply' => true])->assertExitCode(0);
        $this->assertSame(1, Shipment::where('order_id', $order->id)->count());
        $this->assertSame(25000.0, $this->shipmentFeeTotal($order));
        $this->assertSame(25000.0, (float) Shipment::where('order_id', $order->id)->value('shipping_fee_snapshot'));

        // Repeat: idempotent, fee unchanged.
        $this->artisan('shipments:regroup', ['--apply' => true])->assertExitCode(0);
        $this->assertSame(1, Shipment::where('order_id', $order->id)->count());
        $this->assertSame(25000.0, $this->shipmentFeeTotal($order));
    }

    // ===================== F03 — tracking/resi commitment =====================

    public function test_a_shipment_with_tracking_is_never_mutable_regrouped_or_rewritten(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b, $n] = [$this->product($agen, 'A'), $this->product($agen, 'B'), $this->product($agen, 'N')];
        $this->transit($agen, $n);
        $d1 = now()->addDays(3)->toDateString();
        $order = $this->order($konsumen, [[$a, 1], [$b, 1]], $d1);
        $s1 = Shipment::where('order_id', $order->id)->orderBy('id')->firstOrFail();
        $s1->update(['tracking_number' => 'RESI-123', 'shipping_fee_snapshot' => 25000, 'rate_per_km' => 5000]);
        $trackingShipmentId = $s1->id;
        $this->assertFalse(ShipmentGroupingService::isMutable($s1->fresh()));

        // regroup --apply must not merge/delete the tracking shipment.
        $this->artisan('shipments:regroup', ['--apply' => true])->assertExitCode(0);
        $this->assertNotNull(Shipment::find($trackingShipmentId));
        $this->assertSame('RESI-123', Shipment::find($trackingShipmentId)->tracking_number);

        // SC-03 Add Product on the same date must NOT join the committed tracking shipment.
        $this->actingAs($admin)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson("/api/v1/orders/{$order->id}/items", ['product_id' => $n->id, 'quantity' => 1, 'reason' => 'x', 'requested_delivery_date' => $d1])->assertCreated();
        $this->assertNotSame($trackingShipmentId, (int) $this->item($order, $n)->shipment_id, 'a new mutable item must not steal committed identity');
        $this->assertSame('RESI-123', Shipment::find($trackingShipmentId)->tracking_number);
        $this->assertSame(2, OrderItem::where('shipment_id', $trackingShipmentId)->count(), 'original membership preserved');

        // Reschedule one of the two committed siblings: allowed (it moves off), the committed shipment is untouched.
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$this->item($order, $a)->id}/reschedule", ['requested_delivery_date' => now()->addDays(9)->toDateString(), 'reason' => 'x'])->assertOk();
        $this->assertNotNull(Shipment::find($trackingShipmentId));
        $this->assertSame('RESI-123', Shipment::find($trackingShipmentId)->tracking_number);
        $this->assertSame($trackingShipmentId, (int) $this->item($order, $b)->fresh()->shipment_id, 'sibling keeps its committed identity');
    }

    // ===================== F04 — committed reschedule boundary =====================

    public function test_reschedule_rejects_redating_a_committed_single_item_shipment_atomically(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        $d1 = now()->addDays(3)->toDateString();
        $d2 = now()->addDays(9)->toDateString();
        $order = $this->order($konsumen, [[$a, 1]], $d1);
        $item = $this->item($order, $a);
        $shipment = Shipment::where('order_id', $order->id)->firstOrFail();
        $courier = $this->courier($agen);
        $shipment->update(['courier_id' => $courier->id, 'status' => 'pending']);
        $this->assertFalse(ShipmentGroupingService::isMutable($shipment->fresh()));

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'x'])->assertStatus(422);

        // No partial mutation: date, shipment identity, courier and shipment count all unchanged.
        $this->assertSame($d1, $item->fresh()->requested_delivery_date?->toDateString());
        $this->assertSame($shipment->id, (int) $item->fresh()->shipment_id);
        $this->assertSame($courier->id, (int) $shipment->fresh()->courier_id);
        $this->assertSame(1, Shipment::where('order_id', $order->id)->count());
    }

    public function test_reschedule_rejects_delivered_and_in_transit_and_tracking_committed_items(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $d2 = now()->addDays(9)->toDateString();

        // delivered
        $a = $this->product($agen, 'A');
        $order = $this->order($konsumen, [[$a, 1]]);
        $item = $this->item($order, $a);
        $item->update(['status' => 'terkirim']);
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'x'])->assertStatus(422);

        // in_transit
        $b = $this->product($agen, 'B');
        $order2 = $this->order($konsumen, [[$b, 1]]);
        $item2 = $this->item($order2, $b);
        $item2->update(['status' => 'dikirim']);
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order2->id}/items/{$item2->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'x'])->assertStatus(422);

        // tracking-committed pending shipment (item still diproses)
        $c = $this->product($agen, 'C');
        $order3 = $this->order($konsumen, [[$c, 1]]);
        $item3 = $this->item($order3, $c);
        Shipment::where('order_id', $order3->id)->firstOrFail()->update(['tracking_number' => 'RESI-9']);
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order3->id}/items/{$item3->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'x'])->assertStatus(422);
        $this->assertSame('RESI-9', Shipment::where('order_id', $order3->id)->value('tracking_number'));
    }

    public function test_reschedule_moves_one_item_off_a_committed_shipment_without_touching_it(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b] = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $d1 = now()->addDays(3)->toDateString();
        $d2 = now()->addDays(9)->toDateString();
        $order = $this->order($konsumen, [[$a, 1], [$b, 1]], $d1);
        $shipment = Shipment::where('order_id', $order->id)->firstOrFail();
        $courier = $this->courier($agen);
        $shipment->update(['courier_id' => $courier->id]);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$this->item($order, $a)->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'x'])->assertOk();

        // Committed shipment keeps its identity, courier and remaining item; the moved item is elsewhere.
        $this->assertSame($shipment->id, (int) $this->item($order, $b)->fresh()->shipment_id);
        $this->assertSame($courier->id, (int) $shipment->fresh()->courier_id);
        $this->assertNotSame($shipment->id, (int) $this->item($order, $a)->fresh()->shipment_id);
        $this->assertSame($d2, $this->item($order, $a)->fresh()->requested_delivery_date?->toDateString());
    }

    // ===================== F05 — canonical Order-first lock order =====================

    public function test_order_scoped_writers_take_the_orders_row_lock_first(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b, $c, $d] = [$this->product($agen, 'A'), $this->product($agen, 'B'), $this->product($agen, 'C'), $this->product($agen, 'D')];
        $this->transit($agen, $a);
        $this->transit($agen, $b);
        $orderA = $this->order($konsumen, [[$a, 1]], now()->addDays(3)->toDateString());
        $orderB = $this->order($konsumen, [[$b, 1]], now()->addDays(4)->toDateString());
        $orderC = $this->order($konsumen, [[$c, 1], [$d, 1]], now()->addDays(5)->toDateString());
        // Ensure orderC has two mutable same-date shipments so reconcileOrder actually locks shipments.
        $base = Shipment::where('order_id', $orderC->id)->firstOrFail();
        $shell = $base->replicate(['shipping_fee_snapshot', 'rate_per_km']);
        $shell->save();
        $this->item($orderC, $d)->update(['shipment_id' => $shell->id]);
        Shipment::where('order_id', $orderA->id)->update(['courier_id' => null]);

        $firstLockTable = function (callable $run): string {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $run();
            $first = collect(DB::getQueryLog())->pluck('query')->first(fn ($q) => stripos($q, 'for update') !== false);
            DB::disableQueryLog();
            $this->assertNotNull($first, 'a locking read must be issued');
            preg_match('/\bfrom `([^`]+)`/i', (string) $first, $m);

            return $m[1] ?? '';
        };

        $this->assertSame('orders', $firstLockTable(fn () => app(OrderFulfillmentService::class)->adjustItemQuantity($this->item($orderA, $a), 2, $admin, 'lock-order', 'cod')));
        $this->assertSame('orders', $firstLockTable(fn () => app(OrderFulfillmentService::class)->rescheduleItemDeliveryDate($this->item($orderB, $b), now()->addDays(6)->toDateString(), $admin, 'lock-order')));
        $this->assertSame('orders', $firstLockTable(fn () => app(ShipmentGroupingService::class)->reconcileOrder($orderC, $admin)));
        $this->assertSame('orders', $firstLockTable(fn () => app(CourierService::class)->updateShipmentStatus(Shipment::where('order_id', $orderA->id)->firstOrFail(), 'dikirim', $admin)));
    }

    // ===================== F06 — regroup command failure reporting =====================

    public function test_regroup_reports_failures_and_returns_nonzero_exit(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        $orders = [];
        foreach (['A', 'B', 'C'] as $name) {
            [$p, $q] = [$this->product($agen, $name.'1'), $this->product($agen, $name.'2')];
            $order = $this->order($konsumen, [[$p, 1], [$q, 1]], now()->addDays(3)->toDateString());
            $base = Shipment::where('order_id', $order->id)->firstOrFail();
            $shell = $base->replicate(['shipping_fee_snapshot', 'rate_per_km']);
            $shell->save();
            $this->item($order, $q)->update(['shipment_id' => $shell->id]);
            $orders[$name] = $order;
        }

        $failingOrderId = $orders['B']->id;
        $service = new class extends ShipmentGroupingService
        {
            public int $failOrderId = 0;

            public function reconcileOrder(Order $order, ?User $actor = null): array
            {
                $result = parent::reconcileOrder($order, $actor); // do real work first, then fail (proves rollback)
                if ($order->id === $this->failOrderId) {
                    throw new \RuntimeException('simulated per-order failure');
                }

                return $result;
            }
        };
        $service->failOrderId = $failingOrderId;
        $this->app->instance(ShipmentGroupingService::class, $service);

        $this->artisan('shipments:regroup', ['--apply' => true])->assertExitCode(1);

        // Failed order rolled back: still two shipments.
        $this->assertSame(2, Shipment::where('order_id', $failingOrderId)->count());
        // Successful orders committed: merged to one each.
        $this->assertSame(1, Shipment::where('order_id', $orders['A']->id)->count());
        $this->assertSame(1, Shipment::where('order_id', $orders['C']->id)->count());

        // Safe retry with the real service: the failed order merges, everything succeeds, idempotent for the rest.
        $this->app->forgetInstance(ShipmentGroupingService::class);
        $this->artisan('shipments:regroup', ['--apply' => true])->assertExitCode(0);
        $this->assertSame(1, Shipment::where('order_id', $failingOrderId)->count());
        $this->assertSame(1, Shipment::where('order_id', $orders['A']->id)->count());
        $this->assertSame(1, Shipment::where('order_id', $orders['C']->id)->count());
    }
}
