<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\AgentProfile;
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
use App\Services\Shipping\ShippingMethodClassifier;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * Package C — PRODUCTION UAT round 5 (bounded remediation of ea5913c).
 *
 *  - R5-CLASS-01: ONE canonical shipping classifier, null/empty/unknown/mixed -> UNKNOWN -> deny.
 *  - R5-F02A: a nonzero RajaOngkir quote needs a COMPLETE courier+service identity to be equivalent.
 *  - R5-F02B: OpenRoute quote PROVENANCE (fee excluded) is proven consistent across date groups BEFORE an
 *    equal allocation overwrites any snapshot; a previous canonical allocation is not a new quote.
 *  - Money: exact integer minor-unit arithmetic, never float.
 */
class PackageCProductionUatRound5Test extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    /** @return array{agen:User, admin:User, konsumen:User} */
    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'UAT5', 'address' => 'x', 'latitude' => -6.2, 'longitude' => 106.8166]);

        return [
            'agen' => $agen,
            'admin' => User::factory()->admin()->create(['agent_id' => $agen->id]),
            'konsumen' => User::factory()->konsumen()->create(['agent_id' => $agen->id]),
        ];
    }

    private function product(User $agen, string $name, int $stock = 50): Product
    {
        $product = Product::create(['sku' => 'UAT5-'.Str::uuid(), 'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(), 'has_variations' => false, 'base_price' => 10000, 'weight_grams' => 500, 'status' => 'active']);
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

    /** Realistic OpenRoute provider_meta (OpenRouteProvider::quote(), distance_rate_applied rule). */
    private function openRouteMeta(array $overrides = []): array
    {
        return array_replace([
            'api_version' => 'v2',
            'routing_profile' => 'driving-car',
            'distance_meters' => 15000,
            'pricing' => [
                'price_per_km' => 2000.0,
                'minimum_distance_km' => 0.0,
                'minimum_charge' => 0.0,
                'free_shipping_enabled' => false,
                'free_shipping_min_amount' => null,
            ],
            'chargeable_distance_km' => 15.0,
            'rule' => 'distance_rate_applied',
        ], $overrides);
    }

    private function asKurirOnline(Order $order, float $fee): void
    {
        Shipment::where('order_id', $order->id)->update([
            'shipping_provider_code' => 'openroute',
            'shipping_fee_snapshot' => $fee,
            'rate_per_km' => 2000,
            'distance_km' => 15,
            'provider_meta' => $this->openRouteMeta(),
        ]);
        Order::withoutGlobalScopes()->whereKey($order->id)->update(['shipping_fee_amount' => $fee]);
    }

    private function setProviderCode(Order $order, ?string $code): void
    {
        Shipment::where('order_id', $order->id)->update(['shipping_provider_code' => $code]);
    }

    private function shell(Order $order, string $providerCode, float $fee, ?float $rate, array $meta): Shipment
    {
        $base = $this->firstShipment($order);
        $shell = $base->replicate(['shipping_fee_snapshot', 'rate_per_km', 'provider_meta']);
        $shell->save();
        $shell->fresh()->update(['shipping_provider_code' => $providerCode, 'shipping_fee_snapshot' => $fee, 'rate_per_km' => $rate, 'provider_meta' => $meta]);

        return $shell->fresh();
    }

    private function reschedule(int $orderId, int $itemId, string $date, ?int $quantity = null)
    {
        return $this->patchJson("/api/v1/orders/{$orderId}/items/{$itemId}/reschedule", array_filter([
            'requested_delivery_date' => $date, 'reason' => 'x', 'quantity' => $quantity,
        ], fn ($v) => $v !== null));
    }

    /** Exact decimal string -> integer minor units, test-side (no float). */
    private function exactMinor(mixed $value): int
    {
        $string = trim((string) $value);
        if ($string === '') {
            return 0;
        }
        $negative = str_starts_with($string, '-');
        $string = ltrim($string, '+-');
        $parts = explode('.', $string, 2);
        $whole = $parts[0] === '' ? '0' : $parts[0];
        $fraction = str_pad(substr($parts[1] ?? '0', 0, 2), 2, '0');

        $minor = ((int) $whole) * 100 + (int) $fraction;

        return $negative ? -$minor : $minor;
    }

    /** @return array<string, int> active delivery date => exact minor snapshot, date-ordered */
    private function feeMinorByDate(Order $order): array
    {
        $out = [];
        foreach (Shipment::where('order_id', $order->id)->orderBy('id')->get() as $shipment) {
            $dates = OrderItem::query()->where('shipment_id', $shipment->id)
                ->where('status', '!=', 'dibatalkan')->where('fulfilled_quantity', '>', 0)
                ->get(['requested_delivery_date'])->map(fn ($i) => $i->requested_delivery_date?->toDateString())->unique()->values();
            if ($dates->count() === 1) {
                $out[$dates[0]] = $this->exactMinor($shipment->shipping_fee_snapshot);
            }
        }
        ksort($out);

        return $out;
    }

    private function feeTotalMinor(Order $order): int
    {
        return Shipment::where('order_id', $order->id)->get()->sum(fn (Shipment $s) => $this->exactMinor($s->shipping_fee_snapshot));
    }

    private function orderFeeMinor(Order $order): int
    {
        return $this->exactMinor(Order::withoutGlobalScopes()->whereKey($order->id)->value('shipping_fee_amount'));
    }

    /** @return array<string, mixed> */
    private function snapshot(Order $order): array
    {
        return [
            'shipments' => Shipment::where('order_id', $order->id)->orderBy('id')->get()->map(fn (Shipment $s) => [
                'id' => $s->id, 'status' => $s->status, 'courier_id' => $s->courier_id, 'tracking' => $s->tracking_number,
                'fee' => (string) $s->shipping_fee_snapshot, 'proof' => $s->proof_media_id, 'code' => $s->shipping_provider_code,
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

    // ============================== R5-CLASS-01 — ONE CANONICAL CLASSIFIER ==============================

    public function test_classifier_maps_only_canonical_codes_with_no_fallback(): void
    {
        $this->assertSame(ShippingMethodClassifier::EKSPEDISI, ShippingMethodClassifier::classify('rajaongkir'));
        $this->assertSame(ShippingMethodClassifier::KURIR_ONLINE, ShippingMethodClassifier::classify('openroute'));
        $this->assertSame(ShippingMethodClassifier::FREE, ShippingMethodClassifier::classify('free'));
        $this->assertSame(ShippingMethodClassifier::PICKUP, ShippingMethodClassifier::classify('pickup'));
        $this->assertSame(ShippingMethodClassifier::EKSPEDISI, ShippingMethodClassifier::classify(' RajaOngkir '));

        foreach ([null, '', '   ', 'jne', 'legacy_code', 'unknown'] as $code) {
            $this->assertSame(ShippingMethodClassifier::UNKNOWN, ShippingMethodClassifier::classify($code));
        }

        $this->assertFalse(ShippingMethodClassifier::allowsDeliveryDateChange(ShippingMethodClassifier::EKSPEDISI));
        $this->assertFalse(ShippingMethodClassifier::allowsDeliveryDateChange(ShippingMethodClassifier::PICKUP));
        $this->assertFalse(ShippingMethodClassifier::allowsDeliveryDateChange(ShippingMethodClassifier::UNKNOWN));
        $this->assertTrue(ShippingMethodClassifier::allowsDeliveryDateChange(ShippingMethodClassifier::KURIR_ONLINE));
        $this->assertTrue(ShippingMethodClassifier::allowsDeliveryDateChange(ShippingMethodClassifier::FREE));
    }

    public function test_null_provider_is_unknown(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        $order = $this->order($konsumen, [[$this->product($agen, 'A'), 2]], now()->addDays(3)->toDateString());
        $this->setProviderCode($order, null);

        $this->assertSame(ShippingMethodClassifier::UNKNOWN, ShipmentGroupingService::shippingClassification($order));
    }

    public function test_mixed_provider_codes_are_unknown(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b] = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $order = $this->order($konsumen, [[$a, 1], [$b, 1]], now()->addDays(3)->toDateString());

        $base = $this->firstShipment($order);
        $shell = $this->shell($order, 'free', 0.0, null, []);
        $this->item($order, $b)->update(['shipment_id' => $shell->id]);
        $base->update(['shipping_provider_code' => 'openroute']);

        $this->assertSame(ShippingMethodClassifier::UNKNOWN, ShipmentGroupingService::shippingClassification($order));
    }

    /** @return array<string, array{0:?string,1:?string}> */
    public static function deniedProviderPairs(): array
    {
        return [
            'openroute + free' => ['openroute', 'free'],
            'openroute + unknown' => ['openroute', 'legacy_x'],
            'openroute + rajaongkir' => ['openroute', 'rajaongkir'],
            'free + pickup' => ['free', 'pickup'],
        ];
    }

    #[DataProvider('deniedProviderPairs')]
    public function test_mixed_provider_codes_deny_reschedule_without_mutation(?string $codeA, ?string $codeB): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b] = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $d1 = now()->addDays(3)->toDateString();
        $order = $this->order($konsumen, [[$a, 1], [$b, 1]], $d1);

        $base = $this->firstShipment($order);
        $shell = $this->shell($order, 'free', 0.0, null, []);
        $this->item($order, $b)->update(['shipment_id' => $shell->id]);
        $base->update(['shipping_provider_code' => $codeA]);
        $shell->update(['shipping_provider_code' => $codeB]);

        $before = $this->snapshot($order);
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$this->item($order, $a)->id}/reschedule", ['requested_delivery_date' => now()->addDays(9)->toDateString(), 'reason' => 'x'])->assertStatus(422);
        $this->assertSame($before, $this->snapshot($order));
    }

    /** @return array<string, array{0:?string}> */
    public static function deniedProviders(): array
    {
        return ['pickup' => ['pickup'], 'null' => [null], 'unknown' => ['legacy_code']];
    }

    #[DataProvider('deniedProviders')]
    public function test_denied_provider_full_reschedule_and_partial_split_leave_state_untouched(?string $code): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        $d1 = now()->addDays(3)->toDateString();
        $order = $this->order($konsumen, [[$a, 3]], $d1);
        $this->setProviderCode($order, $code);
        $item = $this->item($order, $a);
        $before = $this->snapshot($order);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/reschedule", ['requested_delivery_date' => now()->addDays(9)->toDateString(), 'reason' => 'x'])->assertStatus(422);
        $this->assertSame($before, $this->snapshot($order));

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/reschedule", ['requested_delivery_date' => now()->addDays(9)->toDateString(), 'reason' => 'x', 'quantity' => 1])->assertStatus(422);
        $this->assertSame($before, $this->snapshot($order));
        $this->assertSame(1, OrderItem::where('order_id', $order->id)->count());
    }

    // ============================== BUSINESS RULE — OPENROUTE / FREE / EKSPEDISI ==============================

    public function test_rajaongkir_full_and_partial_denied_unchanged(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        $d1 = now()->addDays(3)->toDateString();
        $order = $this->order($konsumen, [[$a, 3]], $d1);
        $this->setProviderCode($order, 'rajaongkir');
        Order::withoutGlobalScopes()->whereKey($order->id)->update(['shipping_fee_amount' => 20000]);
        $base = $this->firstShipment($order);
        $base->update(['shipping_fee_snapshot' => 20000, 'provider_meta' => ['courier' => 'jne', 'service' => 'REG']]);
        $item = $this->item($order, $a);
        $before = $this->snapshot($order);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/reschedule", ['requested_delivery_date' => now()->addDays(9)->toDateString(), 'reason' => 'x'])->assertStatus(422);
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/reschedule", ['requested_delivery_date' => now()->addDays(9)->toDateString(), 'reason' => 'x', 'quantity' => 1])->assertStatus(422);

        $this->assertSame($before, $this->snapshot($order));
        $this->assertSame($d1, $item->fresh()->requested_delivery_date?->toDateString());
    }

    public function test_openroute_paid_reschedule_allocates_evenly_and_stays_valid(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        $d1 = now()->addDays(3)->toDateString();
        $d2 = now()->addDays(9)->toDateString();
        $order = $this->order($konsumen, [[$a, 2]], $d1);
        $this->asKurirOnline($order, 30000.0);
        $this->actingAs($admin);

        $this->reschedule($order->id, $this->item($order, $a)->id, $d2, 1)->assertOk();

        $this->assertSame([$d1 => 1500000, $d2 => 1500000], $this->feeMinorByDate($order));
        $this->assertSame(3000000, $this->feeTotalMinor($order));
        $this->assertSame(3000000, $this->orderFeeMinor($order));
    }

    public function test_openroute_previous_allocation_can_be_rescheduled_again_without_false_conflict(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        $d1 = now()->addDays(3)->toDateString();
        $d2 = now()->addDays(6)->toDateString();
        $d3 = now()->addDays(9)->toDateString();
        $order = $this->order($konsumen, [[$a, 3]], $d1);
        $this->asKurirOnline($order, 30000.0);
        $this->actingAs($admin);
        $parent = $this->item($order, $a);

        $this->reschedule($order->id, $parent->id, $d2, 1)->assertOk();
        $this->reschedule($order->id, $parent->id, $d3, 1)->assertOk();
        $this->assertSame([$d1 => 1000000, $d2 => 1000000, $d3 => 1000000], $this->feeMinorByDate($order));

        // Move both children back to d1: allocation-derived fees must not be misread as conflicting quotes.
        $children = OrderItem::where('order_id', $order->id)->whereKeyNot($parent->id)->orderBy('id')->get();
        foreach ($children as $child) {
            $this->reschedule($order->id, $child->id, $d1)->assertOk();
        }

        $this->assertSame([$d1 => 3000000], $this->feeMinorByDate($order));
        $this->assertSame(3000000, $this->feeTotalMinor($order));
    }

    public function test_openroute_allocation_is_idempotent(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        $d1 = now()->addDays(3)->toDateString();
        $d2 = now()->addDays(9)->toDateString();
        $order = $this->order($konsumen, [[$a, 2]], $d1);
        $this->asKurirOnline($order, 30001.0);
        $this->actingAs($admin);
        $this->reschedule($order->id, $this->item($order, $a)->id, $d2, 1)->assertOk();

        $first = $this->feeMinorByDate($order);
        $order = Order::withoutGlobalScopes()->findOrFail($order->id);
        app(ShipmentGroupingService::class)->redistributeKurirOnlineShippingFee($order);
        app(ShipmentGroupingService::class)->redistributeKurirOnlineShippingFee($order);

        $this->assertSame($first, $this->feeMinorByDate($order));
        $this->assertSame(3000100, $this->feeTotalMinor($order));
    }

    public function test_free_reschedule_one_to_two_to_three_and_back_stays_zero(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        $dates = [now()->addDays(3)->toDateString(), now()->addDays(6)->toDateString(), now()->addDays(9)->toDateString()];
        $order = $this->order($konsumen, [[$a, 3]], $dates[0]);
        $parent = $this->item($order, $a);
        $this->assertSame(ShippingMethodClassifier::FREE, ShipmentGroupingService::shippingClassification($order));
        $this->actingAs($admin);

        $this->reschedule($order->id, $parent->id, $dates[1], 1)->assertOk();
        $this->reschedule($order->id, $parent->id, $dates[2], 1)->assertOk();
        $this->assertSame([$dates[0] => 0, $dates[1] => 0, $dates[2] => 0], $this->feeMinorByDate($order));
        $this->assertSame(0, $this->orderFeeMinor($order));

        $children = OrderItem::where('order_id', $order->id)->whereKeyNot($parent->id)->orderBy('id')->get();
        foreach ($children as $child) {
            $this->reschedule($order->id, $child->id, $dates[0])->assertOk();
        }
        $this->assertSame([$dates[0] => 0], $this->feeMinorByDate($order));
        $this->assertSame(0, $this->feeTotalMinor($order));
    }

    public function test_free_anomalous_nonzero_order_fee_fails_closed_unchanged(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        $d1 = now()->addDays(3)->toDateString();
        $order = $this->order($konsumen, [[$a, 2]], $d1);
        Order::withoutGlobalScopes()->whereKey($order->id)->update(['shipping_fee_amount' => 5000]);
        $before = $this->snapshot($order);
        $this->actingAs($admin);

        $this->reschedule($order->id, $this->item($order, $a)->id, now()->addDays(9)->toDateString(), 1)->assertStatus(422);
        $this->assertSame($before, $this->snapshot($order));
    }

    public function test_free_anomalous_nonzero_snapshot_fails_closed_unchanged(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        $order = $this->order($konsumen, [[$a, 2]], now()->addDays(3)->toDateString());
        $this->firstShipment($order)->update(['shipping_fee_snapshot' => 7000]);
        $before = $this->snapshot($order);
        $this->actingAs($admin);

        $this->reschedule($order->id, $this->item($order, $a)->id, now()->addDays(9)->toDateString(), 1)->assertStatus(422);
        $this->assertSame($before, $this->snapshot($order));
    }

    public function test_committed_nonzero_fee_group_is_protected_from_redistribution(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b] = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $d1 = now()->addDays(3)->toDateString();
        $d2 = now()->addDays(6)->toDateString();
        $d3 = now()->addDays(9)->toDateString();
        $order = $this->order($konsumen, [[$a, 1], [$b, 1]], $d1);
        $this->asKurirOnline($order, 30000.0);
        $this->actingAs($admin);

        $this->reschedule($order->id, $this->item($order, $b)->id, $d2)->assertOk();
        $committed = Shipment::where('order_id', $order->id)->whereKeyNot($this->firstShipment($order)->id)->firstOrFail();
        $committed->update(['courier_id' => $this->courier($agen)->id]);
        $before = $this->snapshot($order);

        $this->reschedule($order->id, $this->item($order, $a)->id, $d3)->assertStatus(422);
        $this->assertSame($before, $this->snapshot($order));
        $this->assertSame(3000000, $this->feeTotalMinor($order));
    }

    private function courier(User $agen): \App\Models\Courier
    {
        return \App\Models\Courier::create(['type' => 'internal', 'user_id' => User::factory()->kurir()->create(['agent_id' => $agen->id])->id, 'agent_id' => $agen->id, 'name' => 'K', 'is_active' => true]);
    }

    // ============================== R5-F02A — COMPLETE RAJAONGKIR IDENTITY ==============================

    /** @return array{0:Order,1:int,2:int} order, lowId, highId */
    private function twoCarrierOrder(float $fee, array $metaA, array $metaB, bool $swap = false): array
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b] = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $order = $this->order($konsumen, [[$a, 1], [$b, 1]], now()->addDays(3)->toDateString());

        $base = $this->firstShipment($order);
        $base->update(['shipping_provider_code' => 'rajaongkir', 'shipping_fee_snapshot' => $fee, 'rate_per_km' => 5000, 'provider_meta' => $swap ? $metaB : $metaA]);
        $shell = $this->shell($order, 'rajaongkir', $fee, 5000, $swap ? $metaA : $metaB);

        $this->item($order, $a)->update(['shipment_id' => $base->id]);
        $this->item($order, $b)->update(['shipment_id' => $shell->id]);
        $ids = [$base->id, $shell->id];
        sort($ids);

        return [$order, $ids[0], $ids[1]];
    }

    /**
     * @return array<string, array{0:array<string,string>,1:array<string,string>,2:bool}>
     */
    public static function rajaongkirIdentityCases(): array
    {
        return [
            'same complete identity (normalised)' => [['courier' => 'jne', 'service' => 'REG'], ['courier' => 'JNE', 'service' => ' reg '], true],
            'different courier' => [['courier' => 'jne', 'service' => 'REG'], ['courier' => 'jnt', 'service' => 'EZ'], false],
            'same courier different service' => [['courier' => 'jne', 'service' => 'REG'], ['courier' => 'jne', 'service' => 'YES'], false],
            'courier missing on both' => [['service' => 'REG'], ['service' => 'REG'], false],
            'service missing on both' => [['courier' => 'jne'], ['courier' => 'jne'], false],
            'courier missing on one only' => [['courier' => 'jne', 'service' => 'REG'], ['service' => 'REG'], false],
            'service missing on one only' => [['courier' => 'jne', 'service' => 'REG'], ['courier' => 'jne'], false],
            'empty whitespace identity' => [['courier' => '   ', 'service' => 'REG'], ['courier' => 'jne', 'service' => 'REG'], false],
        ];
    }

    #[DataProvider('rajaongkirIdentityCases')]
    public function test_rajaongkir_quote_equivalence_requires_complete_identity(array $metaA, array $metaB, bool $equivalent): void
    {
        foreach ([false, true] as $swap) {
            [$order, $lowId, $highId] = $this->twoCarrierOrder(25000, $metaA, $metaB, $swap);
            $before = $this->snapshot($order);

            if ($equivalent) {
                $result = app(ShipmentGroupingService::class)->reconcileOrder($order, null);
                $this->assertSame(1, $result['merged'], "expected dedupe swap=".json_encode($swap));
                $this->assertSame(1, Shipment::where('order_id', $order->id)->count());
                $this->assertSame(2500000, $this->feeTotalMinor($order));
            } else {
                try {
                    app(ShipmentGroupingService::class)->reconcileOrder($order, null);
                    $this->fail('incomplete/conflicting RajaOngkir identity must fail closed (swap='.json_encode($swap).')');
                } catch (ApiException $e) {
                    $this->assertSame(422, $e->status());
                }
                $this->assertSame($before, $this->snapshot($order));
                $this->assertSame(2, Shipment::where('order_id', $order->id)->count());
            }

            // The id ordering must never decide the outcome.
            $this->assertLessThan($highId, $lowId);
        }
    }

    public function test_regroup_command_conflict_exits_nonzero_and_preserves_state(): void
    {
        [$order] = $this->twoCarrierOrder(25000, ['service' => 'REG'], ['courier' => 'jne'], false);
        $before = $this->snapshot($order);

        $this->artisan('shipments:regroup', ['--apply' => true])->assertExitCode(1);

        $this->assertSame($before, $this->snapshot($order));
        $this->assertSame(2, Shipment::where('order_id', $order->id)->count());
    }

    // ============================== R5-F02B — CROSS-DATE OPENROUTE PROVENANCE ==============================

    /**
     * Two OPENROUTE groups on different dates, each with a nonzero snapshot, and the given provenance.
     *
     * @return array{0:Order,1:OrderItem,2:OrderItem,3:User}
     */
    private function openRouteTwoGroupOrder(array $metaA, array $metaB, bool $swap = false): array
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b] = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $d1 = now()->addDays(3)->toDateString();
        $d2 = now()->addDays(6)->toDateString();
        $order = $this->order($konsumen, [[$a, 2], [$b, 1]], $d1);

        $base = $this->firstShipment($order);
        $base->update(['shipping_provider_code' => 'openroute', 'shipping_fee_snapshot' => 15000, 'rate_per_km' => 2000, 'distance_km' => 15, 'provider_meta' => $swap ? $metaB : $metaA]);
        $shell = $this->shell($order, 'openroute', 15000, 2000, $swap ? $metaA : $metaB);

        $itemA = $this->item($order, $a);
        $itemB = $this->item($order, $b);
        $itemB->update(['requested_delivery_date' => $d2, 'shipment_id' => $shell->id]);
        Order::withoutGlobalScopes()->whereKey($order->id)->update(['shipping_fee_amount' => 30000]);

        return [$order, $itemA->fresh(), $itemB->fresh(), $admin];
    }

    public function test_cross_date_provenance_conflict_denies_full_reschedule_both_orderings(): void
    {
        $metaA = $this->openRouteMeta();
        $metaB = $this->openRouteMeta(['chargeable_distance_km' => 40.0, 'distance_meters' => 40000]);

        foreach ([false, true] as $swap) {
            [$order, $itemA, , $admin] = $this->openRouteTwoGroupOrder($metaA, $metaB, $swap);
            $before = $this->snapshot($order);

            $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$itemA->id}/reschedule", ['requested_delivery_date' => now()->addDays(12)->toDateString(), 'reason' => 'x'])->assertStatus(422);

            $this->assertSame($before, $this->snapshot($order));
            $this->assertSame(3000000, $this->feeTotalMinor($order));
        }
    }

    public function test_cross_date_provenance_conflict_denies_partial_split(): void
    {
        $metaA = $this->openRouteMeta();
        $metaB = $this->openRouteMeta(['pricing' => array_replace($this->openRouteMeta()['pricing'], ['price_per_km' => 3500.0])]);
        [$order, $itemA, , $admin] = $this->openRouteTwoGroupOrder($metaA, $metaB);
        $before = $this->snapshot($order);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$itemA->id}/reschedule", ['requested_delivery_date' => now()->addDays(12)->toDateString(), 'reason' => 'x', 'quantity' => 1])->assertStatus(422);

        $this->assertSame($before, $this->snapshot($order));
        $this->assertSame(2, OrderItem::where('order_id', $order->id)->count());
    }

    public function test_cross_date_incomplete_provenance_fails_closed(): void
    {
        // Two nonzero OpenRoute groups that both lack the required pricing provenance cannot be proven equal.
        [$order, $itemA, , $admin] = $this->openRouteTwoGroupOrder(['reason' => 'legacy'], ['reason' => 'legacy']);
        $before = $this->snapshot($order);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$itemA->id}/reschedule", ['requested_delivery_date' => now()->addDays(12)->toDateString(), 'reason' => 'x', 'quantity' => 1])->assertStatus(422);

        $this->assertSame($before, $this->snapshot($order));
    }

    public function test_cross_date_identical_provenance_allows_allocation(): void
    {
        // Identical provenance on both groups -> a valid redistribution must proceed (no false conflict).
        $meta = $this->openRouteMeta();
        [$order, $itemA, , $admin] = $this->openRouteTwoGroupOrder($meta, $meta);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$itemA->id}/reschedule", ['requested_delivery_date' => now()->addDays(12)->toDateString(), 'reason' => 'x', 'quantity' => 1])->assertOk();

        $this->assertSame(3000000, $this->feeTotalMinor($order));
        $this->assertCount(3, $this->feeMinorByDate($order));
        $this->assertSame([1000000, 1000000, 1000000], array_values($this->feeMinorByDate($order)));
    }

    // ============================== MONEY — EXACT INTEGER MINOR UNITS ==============================

    /**
     * @return array<string, array{0:int,1:list<int>}>
     */
    public static function moneyCases(): array
    {
        return [
            '30000 / 3' => [3000000, [1000000, 1000000, 1000000]],
            '30001 / 3' => [3000100, [1000100, 1000000, 1000000]],
            '30002 / 3' => [3000200, [1000100, 1000100, 1000000]],
            '1 / 3' => [100, [100, 0, 0]],
            '1.01 / 3' => [101, [101, 0, 0]],
            '0.01 / 3' => [1, [1, 0, 0]],
            '30000.01 / 3' => [3000001, [1000001, 1000000, 1000000]],
            'boundary 9999999999.99 / 3' => [999999999999, [333333333399, 333333333300, 333333333300]],
        ];
    }

    #[DataProvider('moneyCases')]
    public function test_exact_money_conversion_and_conservation(int $totalMinor, array $expected): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        $dates = [now()->addDays(3)->toDateString(), now()->addDays(6)->toDateString(), now()->addDays(9)->toDateString()];
        $order = $this->order($konsumen, [[$a, 3]], $dates[0]);
        $this->asKurirOnline($order, 0.0);
        Shipment::where('order_id', $order->id)->update(['shipping_fee_snapshot' => 0]);
        Order::withoutGlobalScopes()->whereKey($order->id)->update(['shipping_fee_amount' => $this->minorToString($totalMinor)]);
        $this->actingAs($admin);
        $parent = $this->item($order, $a);

        $this->reschedule($order->id, $parent->id, $dates[1], 1)->assertOk();
        $this->reschedule($order->id, $parent->id, $dates[2], 1)->assertOk();

        $this->assertSame($expected, array_values($this->feeMinorByDate($order)));
        $this->assertSame($totalMinor, array_sum($this->feeMinorByDate($order)), 'exact conservation, no minor unit lost or created');
        $this->assertSame($totalMinor, $this->orderFeeMinor($order), 'Order shipping total unchanged');
    }

    private function minorToString(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return sprintf('%s%d.%02d', $sign, intdiv($minor, 100), $minor % 100);
    }
}
