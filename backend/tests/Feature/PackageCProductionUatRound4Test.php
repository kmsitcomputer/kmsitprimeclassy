<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\AgentProfile;
use App\Models\Courier;
use App\Models\DeliveryVerification;
use App\Models\Media;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Services\Order\ShipmentGroupingService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * Package C — PRODUCTION UAT round-4 (FINAL) — Human-approved delivery-date rule + Codex F02/F04.
 *
 * Business rule: Ekspedisi (rajaongkir) dates are fixed; Kurir Online (openroute) paid fees are redistributed
 * evenly across active delivery-date groups (deterministic integer remainder). F02: complete quote signature
 * (courier + service). F04: quantity adjustment cannot mutate historical shipment contents.
 */
class PackageCProductionUatRound4Test extends TestCase
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
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'UAT4', 'address' => 'x', 'latitude' => -6.2, 'longitude' => 106.8166]);

        return [
            'agen' => $agen,
            'admin' => User::factory()->admin()->create(['agent_id' => $agen->id]),
            'gudang' => User::factory()->gudang()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]),
            'konsumen' => User::factory()->konsumen()->create(['agent_id' => $agen->id]),
        ];
    }

    private function product(User $agen, string $name, int $stock = 50): Product
    {
        $product = Product::create(['sku' => 'UAT4-'.Str::uuid(), 'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(), 'has_variations' => false, 'base_price' => 10000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => $stock, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => $stock]);
        WarehouseSetting::firstOrCreate(['agent_id' => $agen->id], ['factory_plan_enabled' => false]);

        return $product;
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

    private function firstShipment(Order $order): Shipment
    {
        return Shipment::where('order_id', $order->id)->orderBy('id')->firstOrFail();
    }

    /** Mark the order as an Ekspedisi (RajaOngkir) order using the canonical server-side snapshot. */
    private function asEkspedisi(Order $order, float $fee): void
    {
        Shipment::where('order_id', $order->id)->update(['shipping_provider_code' => 'rajaongkir']);
        Order::withoutGlobalScopes()->whereKey($order->id)->update(['shipping_fee_amount' => $fee]);
    }

    /** Mark the order as a Kurir Online (OpenRoute) order with the given total fee on its single group. */
    private function asKurirOnline(Order $order, float $fee): void
    {
        Shipment::where('order_id', $order->id)->update(['shipping_provider_code' => 'openroute', 'shipping_fee_snapshot' => $fee]);
        Order::withoutGlobalScopes()->whereKey($order->id)->update(['shipping_fee_amount' => $fee]);
    }

    /** @return array<string, float> active delivery date => surviving shipment snapshot, ordered by date */
    private function feeByDate(Order $order): array
    {
        $out = [];
        foreach (Shipment::where('order_id', $order->id)->orderBy('id')->get() as $shipment) {
            $dates = OrderItem::query()->where('shipment_id', $shipment->id)
                ->where('status', '!=', 'dibatalkan')->where('fulfilled_quantity', '>', 0)
                ->get(['requested_delivery_date'])->map(fn ($i) => $i->requested_delivery_date?->toDateString())->unique()->values();
            if ($dates->count() === 1) {
                $out[$dates[0]] = (float) $shipment->shipping_fee_snapshot;
            }
        }
        ksort($out);

        return $out;
    }

    private function feeTotal(Order $order): float
    {
        return (float) Shipment::where('order_id', $order->id)->sum('shipping_fee_snapshot');
    }

    private function orderFee(Order $order): float
    {
        return (float) Order::withoutGlobalScopes()->whereKey($order->id)->value('shipping_fee_amount');
    }

    private function courier(User $agen): Courier
    {
        return Courier::create(['type' => 'internal', 'user_id' => User::factory()->kurir()->create(['agent_id' => $agen->id])->id, 'agent_id' => $agen->id, 'name' => 'K', 'is_active' => true]);
    }

    private function reschedule(int $orderId, int $itemId, string $date, ?int $quantity = null)
    {
        return $this->patchJson("/api/v1/orders/{$orderId}/items/{$itemId}/reschedule", array_filter([
            'requested_delivery_date' => $date, 'reason' => 'x', 'quantity' => $quantity,
        ], fn ($v) => $v !== null));
    }

    /** @return array<string, mixed> */
    private function snapshot(Order $order): array
    {
        return [
            'shipments' => Shipment::where('order_id', $order->id)->orderBy('id')->get()->map(fn (Shipment $s) => [
                'id' => $s->id, 'status' => $s->status, 'courier_id' => $s->courier_id, 'tracking' => $s->tracking_number,
                'fee' => (float) $s->shipping_fee_snapshot, 'proof' => $s->proof_media_id, 'code' => $s->shipping_provider_code,
                'shipped_at' => (string) $s->shipped_at, 'delivered_at' => (string) $s->delivered_at,
            ])->all(),
            'items' => OrderItem::where('order_id', $order->id)->orderBy('id')->get()->map(fn (OrderItem $i) => [
                'id' => $i->id, 'shipment_id' => $i->shipment_id, 'date' => $i->requested_delivery_date?->toDateString(),
                'fulfilled' => $i->fulfilled_quantity, 'original' => $i->original_quantity, 'status' => $i->status,
            ])->all(),
            'request_items' => StockRequestItem::whereIn('stock_request_id', StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->pluck('id'))
                ->orderBy('id')->get()->map(fn (StockRequestItem $r) => [$r->order_item_id, $r->requested_qty, $r->fulfilled_qty, $r->remaining_qty])->all(),
            'order' => Order::withoutGlobalScopes()->find($order->id)->only(['total_amount', 'shipping_fee_amount', 'paid_amount', 'remaining_amount', 'payment_status']),
        ];
    }

    // ============================== BUSINESS RULE — EKSPEDISI ==============================

    public function test_ekspedisi_full_reschedule_is_rejected_without_any_mutation(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        $d1 = now()->addDays(3)->toDateString();
        $order = $this->order($konsumen, [[$a, 2]], $d1);
        $this->asEkspedisi($order, 20000);
        $item = $this->item($order, $a);
        $before = $this->snapshot($order);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/reschedule", ['requested_delivery_date' => now()->addDays(9)->toDateString(), 'reason' => 'x'])->assertStatus(422);

        $this->assertSame($before, $this->snapshot($order));
        $this->assertSame($d1, $item->fresh()->requested_delivery_date?->toDateString());
    }

    public function test_ekspedisi_partial_split_is_rejected_without_any_mutation(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        $order = $this->order($konsumen, [[$a, 3]], now()->addDays(3)->toDateString());
        $this->asEkspedisi($order, 20000);
        $item = $this->item($order, $a);
        $before = $this->snapshot($order);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/reschedule", ['requested_delivery_date' => now()->addDays(9)->toDateString(), 'reason' => 'pecah', 'quantity' => 1])->assertStatus(422);

        $this->assertSame($before, $this->snapshot($order));
        $this->assertSame(1, OrderItem::where('order_id', $order->id)->count());
    }

    // ============================== BUSINESS RULE — KURIR ONLINE ==============================

    public function test_kurir_online_free_reschedule_keeps_all_group_fees_zero(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        $d1 = now()->addDays(3)->toDateString();
        $d2 = now()->addDays(9)->toDateString();
        $order = $this->order($konsumen, [[$a, 2]], $d1);
        $this->asKurirOnline($order, 0.0);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$this->item($order, $a)->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'pecah', 'quantity' => 1])->assertOk();

        $this->assertSame(0.0, $this->orderFee($order));
        $this->assertSame([$d1 => 0.0, $d2 => 0.0], $this->feeByDate($order));
        $this->assertSame(0.0, $this->feeTotal($order));
    }

    public function test_kurir_online_paid_one_to_two_groups_splits_evenly(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        $d1 = now()->addDays(3)->toDateString();
        $d2 = now()->addDays(9)->toDateString();
        $order = $this->order($konsumen, [[$a, 2]], $d1);
        $this->asKurirOnline($order, 30000.0);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$this->item($order, $a)->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'pecah', 'quantity' => 1])->assertOk();

        $this->assertSame([$d1 => 15000.0, $d2 => 15000.0], $this->feeByDate($order));
        $this->assertSame(30000.0, $this->orderFee($order));
        $this->assertSame(30000.0, $this->feeTotal($order));
    }

    public function test_kurir_online_paid_one_to_three_groups_splits_evenly_with_remainder(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        $d1 = now()->addDays(3)->toDateString();
        $d2 = now()->addDays(6)->toDateString();
        $d3 = now()->addDays(9)->toDateString();
        $order = $this->order($konsumen, [[$a, 3]], $d1);
        $this->asKurirOnline($order, 30001.0);
        $parent = $this->item($order, $a);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$parent->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'pecah', 'quantity' => 1])->assertOk();
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$parent->id}/reschedule", ['requested_delivery_date' => $d3, 'reason' => 'pecah', 'quantity' => 1])->assertOk();

        // 30001 / 3 = 10000 base + remainder 1 -> first date gets +1.
        $this->assertSame([$d1 => 10001.0, $d2 => 10000.0, $d3 => 10000.0], $this->feeByDate($order));
        $this->assertSame(30001.0, $this->orderFee($order));
        $this->assertSame(30001.0, $this->feeTotal($order));
    }

    /** @return array<string, array{0:float,1:array<string,float>}> */
    public static function distributionCases(): array
    {
        return [
            'divisible 30000/3' => [30000.0, ['d1' => 10000.0, 'd2' => 10000.0, 'd3' => 10000.0]],
            'remainder1 30001/3' => [30001.0, ['d1' => 10001.0, 'd2' => 10000.0, 'd3' => 10000.0]],
            'remainder2 30002/3' => [30002.0, ['d1' => 10001.0, 'd2' => 10001.0, 'd3' => 10000.0]],
            'tiny 1/3' => [1.0, ['d1' => 1.0, 'd2' => 0.0, 'd3' => 0.0]],
        ];
    }

    #[DataProvider('distributionCases')]
    public function test_kurir_online_paid_three_group_integer_distribution(float $total, array $expected): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        $dates = [now()->addDays(3)->toDateString(), now()->addDays(6)->toDateString(), now()->addDays(9)->toDateString()];
        $order = $this->order($konsumen, [[$a, 3]], $dates[0]);
        $this->asKurirOnline($order, $total);
        $parent = $this->item($order, $a);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$parent->id}/reschedule", ['requested_delivery_date' => $dates[1], 'reason' => 'x', 'quantity' => 1])->assertOk();
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$parent->id}/reschedule", ['requested_delivery_date' => $dates[2], 'reason' => 'x', 'quantity' => 1])->assertOk();

        $expectedByDate = [$dates[0] => $expected['d1'], $dates[1] => $expected['d2'], $dates[2] => $expected['d3']];
        $this->assertSame($expectedByDate, $this->feeByDate($order));
        $this->assertSame($total, $this->feeTotal($order), 'exact conservation, no Rp1 lost or created');
        $this->assertSame($total, $this->orderFee($order), 'Order shipping total unchanged');
    }

    public function test_kurir_online_paid_four_groups_conserve_exactly(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        $dates = [now()->addDays(3)->toDateString(), now()->addDays(6)->toDateString(), now()->addDays(9)->toDateString(), now()->addDays(12)->toDateString()];
        $order = $this->order($konsumen, [[$a, 4]], $dates[0]);
        $this->asKurirOnline($order, 30000.0);
        $parent = $this->item($order, $a);

        foreach ([1, 2, 3] as $i) {
            $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$parent->id}/reschedule", ['requested_delivery_date' => $dates[$i], 'reason' => 'x', 'quantity' => 1])->assertOk();
        }

        $this->assertSame(30000.0, $this->feeTotal($order));
        $this->assertSame([7500.0, 7500.0, 7500.0, 7500.0], array_values($this->feeByDate($order)));
        $this->assertCount(4, $this->feeByDate($order));
    }

    public function test_kurir_online_reschedule_back_to_one_group_restores_full_fee(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        $d1 = now()->addDays(3)->toDateString();
        $d2 = now()->addDays(9)->toDateString();
        $order = $this->order($konsumen, [[$a, 2]], $d1);
        $this->asKurirOnline($order, 30000.0);
        $parent = $this->item($order, $a);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$parent->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'x', 'quantity' => 1])->assertOk();
        $this->assertSame([$d1 => 15000.0, $d2 => 15000.0], $this->feeByDate($order));

        // Move the split child back to d1: one active group again -> full fee, deterministic, no drift.
        $child = OrderItem::where('order_id', $order->id)->whereKeyNot($parent->id)->firstOrFail();
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$child->id}/reschedule", ['requested_delivery_date' => $d1, 'reason' => 'x'])->assertOk();

        $this->assertSame([$d1 => 30000.0], $this->feeByDate($order));
        $this->assertSame(30000.0, $this->feeTotal($order));
    }

    public function test_kurir_online_redistribution_refuses_when_a_group_is_committed(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b] = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $d1 = now()->addDays(3)->toDateString();
        $d2 = now()->addDays(6)->toDateString();
        $d3 = now()->addDays(9)->toDateString();
        $order = $this->order($konsumen, [[$a, 1], [$b, 1]], $d1);
        $this->asKurirOnline($order, 30000.0);

        // Move B to its own d2 group, then commit it (courier assigned) with a nonzero fee snapshot.
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$this->item($order, $b)->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'x'])->assertOk();
        $committed = Shipment::where('order_id', $order->id)->whereKeyNot($this->firstShipment($order)->id)->firstOrFail();
        $committed->update(['courier_id' => $this->courier($agen)->id]);
        $before = $this->snapshot($order);

        // Rescheduling A to a new d3 would require redistributing over the committed group -> refuse.
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$this->item($order, $a)->id}/reschedule", ['requested_delivery_date' => $d3, 'reason' => 'x'])->assertStatus(422);

        $this->assertSame($before, $this->snapshot($order));
        $this->assertSame($d1, $this->item($order, $a)->fresh()->requested_delivery_date?->toDateString());
    }

    // ============================== F02 — COMPLETE QUOTE SIGNATURE ==============================

    private function shell(Order $order, float $fee, ?float $rate, array $meta): Shipment
    {
        $base = $this->firstShipment($order);
        $shell = $base->replicate(['shipping_fee_snapshot', 'rate_per_km', 'provider_meta']);
        $shell->save();
        $shell->fresh()->update(['shipping_fee_snapshot' => $fee, 'rate_per_km' => $rate, 'provider_meta' => $meta]);

        return $shell->fresh();
    }

    /**
     * An order with two mutable same-date fee carriers. `$swap` flips which row (lower vs higher id) carries
     * meta A, so both id orderings can be exercised — the signature comparison is order-independent.
     *
     * @return array{0:Order,1:User}
     */
    private function twoCarrierOrder(float $fee, array $metaA, array $metaB, bool $swap = false): array
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b] = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $order = $this->order($konsumen, [[$a, 1], [$b, 1]], now()->addDays(3)->toDateString());

        $base = $this->firstShipment($order);
        $base->update(['shipping_fee_snapshot' => $fee, 'rate_per_km' => 5000, 'provider_meta' => $swap ? $metaB : $metaA]);
        $shell = $this->shell($order, $fee, 5000, $swap ? $metaA : $metaB);

        $this->item($order, $a)->update(['shipment_id' => $base->id]);
        $this->item($order, $b)->update(['shipment_id' => $shell->id]);

        return [$order, $agen];
    }

    public function test_same_fee_same_courier_same_service_is_equivalent_and_dedupes(): void
    {
        [$order, $agen] = $this->twoCarrierOrder(25000, ['courier' => 'jne', 'service' => 'REG'], ['courier' => 'JNE', 'service' => ' reg ']);

        $result = app(ShipmentGroupingService::class)->reconcileOrder($order, null);

        $this->assertSame(1, $result['merged']);
        $this->assertSame(1, Shipment::where('order_id', $order->id)->count());
        $this->assertSame(25000.0, $this->feeTotal($order));
    }

    public function test_same_fee_different_courier_fails_closed(): void
    {
        [$order] = $this->twoCarrierOrder(25000, ['courier' => 'jne', 'service' => 'REG'], ['courier' => 'jnt', 'service' => 'EZ']);
        $before = $this->snapshot($order);

        try {
            app(ShipmentGroupingService::class)->reconcileOrder($order, null);
            $this->fail('different couriers must not be treated as equivalent');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status());
        }

        $this->assertSame($before, $this->snapshot($order));
        $this->assertSame(2, Shipment::where('order_id', $order->id)->count());
    }

    public function test_same_fee_same_courier_different_service_fails_closed(): void
    {
        [$order] = $this->twoCarrierOrder(25000, ['courier' => 'jne', 'service' => 'REG'], ['courier' => 'jne', 'service' => 'YES']);
        $before = $this->snapshot($order);

        try {
            app(ShipmentGroupingService::class)->reconcileOrder($order, null);
            $this->fail('different services must not be treated as equivalent');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status());
        }

        $this->assertSame($before, $this->snapshot($order));
    }

    public function test_different_fee_conflicts_and_both_id_orderings_conflict(): void
    {
        // Same two quotes, with the lower-id row carrying the JNE vs J&T meta in turn.
        [$orderLow] = $this->twoCarrierOrder(25000, ['courier' => 'jne', 'service' => 'REG'], ['courier' => 'jnt', 'service' => 'EZ'], false);
        [$orderHigh] = $this->twoCarrierOrder(25000, ['courier' => 'jne', 'service' => 'REG'], ['courier' => 'jnt', 'service' => 'EZ'], true);

        foreach ([$orderLow, $orderHigh] as $order) {
            $before = $this->snapshot($order);
            try {
                app(ShipmentGroupingService::class)->reconcileOrder($order, null);
                $this->fail('conflicting carriers must fail closed regardless of id order');
            } catch (ApiException $e) {
                $this->assertSame(422, $e->status());
            }
            $this->assertSame($before, $this->snapshot($order));
        }

        [$orderDifferentFee] = $this->twoCarrierOrder(25000, ['courier' => 'jne', 'service' => 'REG'], ['courier' => 'jne', 'service' => 'REG'], true);
        // Make the two fees differ.
        $shells = Shipment::where('order_id', $orderDifferentFee->id)->orderBy('id')->get();
        $shells[1]->update(['shipping_fee_snapshot' => 30000]);
        $before = $this->snapshot($orderDifferentFee);
        try {
            app(ShipmentGroupingService::class)->reconcileOrder($orderDifferentFee, null);
            $this->fail('different fees must fail closed');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status());
        }
        $this->assertSame($before, $this->snapshot($orderDifferentFee));
    }

    public function test_harmless_incidental_metadata_difference_is_still_equivalent(): void
    {
        // Same courier + service + fee, but incidental etd/description/weight differ -> must dedupe.
        [$order] = $this->twoCarrierOrder(
            25000,
            ['courier' => 'jne', 'service' => 'REG', 'etd' => '2-3', 'description' => 'Layanan Reguler', 'weight_grams' => 500],
            ['courier' => 'jne', 'service' => 'REG', 'etd' => '3-4', 'description' => 'REG', 'weight_grams' => 800],
        );

        $result = app(ShipmentGroupingService::class)->reconcileOrder($order, null);

        $this->assertSame(1, $result['merged']);
        $this->assertSame(25000.0, $this->feeTotal($order));
    }

    public function test_interactive_release_if_empty_quote_conflict_rolls_back(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b] = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $d1 = now()->addDays(3)->toDateString();
        $d2 = now()->addDays(9)->toDateString();
        $order = $this->order($konsumen, [[$a, 1], [$b, 1]], $d1);

        $s1 = $this->firstShipment($order);
        $s1->update(['shipping_fee_snapshot' => 25000, 'provider_meta' => ['courier' => 'jne', 'service' => 'REG']]);
        $s2 = $this->shell($order, 25000, 5000, ['courier' => 'jnt', 'service' => 'EZ']);
        $this->item($order, $b)->update(['requested_delivery_date' => $d2, 'shipment_id' => $s2->id]);
        $before = $this->snapshot($order);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$this->item($order, $a)->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'x'])->assertStatus(422);

        $this->assertSame($before, $this->snapshot($order));
    }

    public function test_regroup_apply_quote_conflict_fails_order_and_exits_nonzero(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b] = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $order = $this->order($konsumen, [[$a, 1], [$b, 1]], now()->addDays(3)->toDateString());
        $base = $this->firstShipment($order);
        $base->update(['shipping_fee_snapshot' => 25000, 'provider_meta' => ['courier' => 'jne', 'service' => 'REG']]);
        $shell = $this->shell($order, 25000, 5000, ['courier' => 'jnt', 'service' => 'EZ']);
        $this->item($order, $b)->update(['shipment_id' => $shell->id]);
        $before = $this->snapshot($order);

        $this->artisan('shipments:regroup', ['--apply' => true])->assertExitCode(1);

        $this->assertSame($before, $this->snapshot($order));
        $this->assertSame(2, Shipment::where('order_id', $order->id)->count());
    }

    // ============================== F04 — HISTORICAL QUANTITY IMMUTABILITY ==============================

    /** @return array<string, array{0:string}> */
    public static function historicalKinds(): array
    {
        return ['delivered' => ['delivered'], 'in_transit' => ['in_transit'], 'proof' => ['proof'], 'verification' => ['verification']];
    }

    private function makeHistorical(Shipment $shipment, string $kind, int $adminId): void
    {
        match ($kind) {
            'delivered' => $shipment->update(['status' => 'delivered', 'delivered_at' => now()]),
            'in_transit' => $shipment->update(['status' => 'in_transit', 'shipped_at' => now()]),
            'proof' => $shipment->update(['proof_media_id' => Media::create([
                'disk' => 'public', 'path' => 'shipment_proof/'.Str::uuid().'.jpg', 'collection' => 'shipment_proof',
                'original_filename' => 'proof.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size' => 10, 'created_by' => $adminId,
            ])->id]),
            'verification' => DeliveryVerification::create(['shipment_id' => $shipment->id, 'outcome' => 'received', 'verified_by' => $adminId, 'verified_at' => now(), 'idempotency_key' => (string) Str::uuid()]),
        };
    }

    #[DataProvider('historicalKinds')]
    public function test_quantity_increase_and_decrease_are_rejected_on_a_historical_shipment(string $kind): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        $order = $this->order($konsumen, [[$a, 3]], now()->addDays(3)->toDateString());
        // Stale/inconsistent item status: order + item stay 'diproses' while shipment evidence is historical.
        $this->makeHistorical($this->firstShipment($order), $kind, $admin->id);
        $item = $this->item($order, $a);
        $before = $this->snapshot($order);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/fulfillment", ['fulfilled_quantity' => 4, 'reason' => 'x'])->assertStatus(422);
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/fulfillment", ['fulfilled_quantity' => 2, 'reason' => 'x'])->assertStatus(422);

        $this->assertSame($before, $this->snapshot($order));
        $this->assertSame(3, (int) $item->fresh()->fulfilled_quantity);
    }

    public function test_quantity_adjustment_still_works_on_a_mutable_shipment(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        $order = $this->order($konsumen, [[$a, 3]], now()->addDays(3)->toDateString());
        $item = $this->item($order, $a);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/fulfillment", ['fulfilled_quantity' => 4, 'reason' => 'x'])->assertOk();
        $this->assertSame(4, (int) $item->fresh()->fulfilled_quantity);
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/fulfillment", ['fulfilled_quantity' => 2, 'reason' => 'x'])->assertOk();
        $this->assertSame(2, (int) $item->fresh()->fulfilled_quantity);
    }
}
