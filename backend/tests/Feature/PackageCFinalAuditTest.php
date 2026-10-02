<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
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
use ReflectionMethod;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * Package C — PRODUCTION UAT FINAL AUDIT (independent audit of c351ef6).
 *
 *  - FA-01 provenance vs allocation: a canonical equal allocation must never be re-read as independent
 *    conflicting quotes — INCLUDING legacy OpenRoute quotes whose persisted provenance is incomplete —
 *    while genuinely conflicting provenance stays fail-closed across repeated operations.
 *  - FA-02 the classifier has NO case/whitespace leniency (only the exact persisted canonical code).
 *  - FA-03 exact money: strict decimal parsing (no truncation, no negatives); exact meta comparison.
 *  - FA-04 malformed provider metadata never 500s.
 */
class PackageCFinalAuditTest extends TestCase
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
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'FA', 'address' => 'x', 'latitude' => -6.2, 'longitude' => 106.8166]);

        return [
            'agen' => $agen,
            'admin' => User::factory()->admin()->create(['agent_id' => $agen->id]),
            'konsumen' => User::factory()->konsumen()->create(['agent_id' => $agen->id]),
        ];
    }

    private function product(User $agen, string $name, int $stock = 50): Product
    {
        $product = Product::create(['sku' => 'FA-'.Str::uuid(), 'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(), 'has_variations' => false, 'base_price' => 10000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => $stock, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => $stock]);
        WarehouseSetting::firstOrCreate(['agent_id' => $agen->id], ['factory_plan_enabled' => false]);

        return $product;
    }

    private function order(User $konsumen, array $lines, string $date): Order
    {
        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => array_map(fn ($l) => ['product_id' => $l[0]->id, 'quantity' => $l[1]], $lines),
            'delivery_date' => $date,
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);
        $response->assertCreated();

        return Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
    }

    private function item(Order $order, Product $product): OrderItem
    {
        return OrderItem::where('order_id', $order->id)->where('product_id', $product->id)->orderBy('id')->firstOrFail();
    }

    private function openRouteMeta(array $overrides = []): array
    {
        return array_replace([
            'api_version' => 'v2', 'routing_profile' => 'driving-car', 'distance_meters' => 15000,
            'pricing' => ['price_per_km' => 2000.0, 'minimum_distance_km' => 0.0, 'minimum_charge' => 0.0, 'free_shipping_enabled' => false, 'free_shipping_min_amount' => null],
            'chargeable_distance_km' => 15.0, 'rule' => 'distance_rate_applied',
        ], $overrides);
    }

    /** @param array<string,mixed>|null $meta */
    private function asKurirOnline(Order $order, string $fee, ?array $meta): void
    {
        Shipment::where('order_id', $order->id)->update([
            'shipping_provider_code' => 'openroute', 'shipping_fee_snapshot' => $fee,
            'rate_per_km' => 2000, 'distance_km' => 15, 'provider_meta' => $meta === null ? null : json_encode($meta),
        ]);
        Order::withoutGlobalScopes()->whereKey($order->id)->update(['shipping_fee_amount' => $fee]);
    }

    private function reschedule(int $orderId, int $itemId, string $date, ?int $quantity = null)
    {
        return $this->patchJson("/api/v1/orders/{$orderId}/items/{$itemId}/reschedule", array_filter([
            'requested_delivery_date' => $date, 'reason' => 'x', 'quantity' => $quantity,
        ], fn ($v) => $v !== null));
    }

    private function minor(mixed $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim((string) $value), 2), 2, '0');

        return ((int) $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    /** @return array<string,int> active date => exact minor snapshot of its group */
    private function feeByDate(Order $order): array
    {
        $out = [];
        foreach (Shipment::where('order_id', $order->id)->orderBy('id')->get() as $shipment) {
            $dates = OrderItem::where('shipment_id', $shipment->id)->where('status', '!=', 'dibatalkan')->where('fulfilled_quantity', '>', 0)
                ->get(['requested_delivery_date'])->map(fn ($i) => $i->requested_delivery_date?->toDateString())->unique()->values();
            if ($dates->count() === 1) {
                $out[$dates[0]] = $this->minor($shipment->shipping_fee_snapshot);
            }
        }
        ksort($out);

        return $out;
    }

    private function assertAllocationConserved(Order $order, int $groups, int $totalMinor): void
    {
        $byDate = $this->feeByDate($order);
        $this->assertCount($groups, $byDate);
        $this->assertSame($totalMinor, array_sum($byDate));
        $this->assertSame($totalMinor, Shipment::where('order_id', $order->id)->get()->sum(fn ($s) => $this->minor($s->shipping_fee_snapshot)), 'no stray fee evidence outside the active groups');
        $this->assertSame($totalMinor, $this->minor(Order::withoutGlobalScopes()->whereKey($order->id)->value('shipping_fee_amount')));

        // Deterministic even allocation: whole-rupiah shares differ by at most one.
        $rupiah = array_map(fn ($m) => intdiv($m, 100), array_values($byDate));
        $this->assertLessThanOrEqual(1, max($rupiah) - min($rupiah));
    }

    /** @return array<string, array{0:?array}> */
    public static function provenanceShapes(): array
    {
        return [
            'complete provenance' => [[]],
            'legacy incomplete provenance (no pricing)' => [['legacy' => true]],
            'legacy null provenance' => [null],
        ];
    }

    private function metaFor(?array $shape): ?array
    {
        if ($shape === null) {
            return null;
        }

        return $shape === [] ? $this->openRouteMeta() : ['reason' => 'legacy_quote'];
    }

    // ===================== FA-01 — PROVENANCE vs CANONICAL ALLOCATION, repeated operations =====================

    #[DataProvider('provenanceShapes')]
    public function test_repeated_reschedules_1_3_4_2_1_2_conserve_fee_and_never_misread_allocation_as_conflict(?array $shape): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        $d = [1 => now()->addDays(3)->toDateString(), 2 => now()->addDays(6)->toDateString(), 3 => now()->addDays(9)->toDateString(), 4 => now()->addDays(12)->toDateString(), 5 => now()->addDays(15)->toDateString()];
        $order = $this->order($konsumen, [[$a, 4]], $d[1]);
        $this->asKurirOnline($order, '30001.00', $this->metaFor($shape));
        $total = 3000100;
        $this->actingAs($admin);
        $parent = $this->item($order, $a);

        // 1 -> 3
        $this->reschedule($order->id, $parent->id, $d[2], 1)->assertOk();
        $this->reschedule($order->id, $parent->id, $d[3], 1)->assertOk();
        $this->assertAllocationConserved($order, 3, $total);

        // 3 -> 4
        $this->reschedule($order->id, $parent->id, $d[4], 1)->assertOk();
        $this->assertAllocationConserved($order, 4, $total);

        $children = OrderItem::where('order_id', $order->id)->whereKeyNot($parent->id)->orderBy('id')->get()->values();
        [$c2, $c3, $c4] = [$children[0], $children[1], $children[2]];

        // 4 -> 2 (c3 -> d1 ; c4 -> d2)
        $this->reschedule($order->id, $c3->id, $d[1])->assertOk();
        $this->assertAllocationConserved($order, 3, $total);
        $this->reschedule($order->id, $c4->id, $d[2])->assertOk();
        $this->assertAllocationConserved($order, 2, $total);

        // 2 -> 1
        $this->reschedule($order->id, $c2->id, $d[1])->assertOk();
        $this->reschedule($order->id, $c4->id, $d[1])->assertOk();
        $this->assertAllocationConserved($order, 1, $total);

        // 1 -> 2 again
        $this->reschedule($order->id, $c2->id, $d[5])->assertOk();
        $this->assertAllocationConserved($order, 2, $total);
    }

    public function test_genuinely_conflicting_provenance_is_still_detected_after_an_allocation(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        [$d1, $d2, $d3, $d4] = [now()->addDays(3)->toDateString(), now()->addDays(6)->toDateString(), now()->addDays(9)->toDateString(), now()->addDays(12)->toDateString()];
        $order = $this->order($konsumen, [[$a, 4]], $d1);
        $this->asKurirOnline($order, '30000.00', $this->openRouteMeta());
        $this->actingAs($admin);
        $parent = $this->item($order, $a);
        $this->reschedule($order->id, $parent->id, $d2, 1)->assertOk();
        $this->reschedule($order->id, $parent->id, $d3, 1)->assertOk();

        // Evidence tampering/conflict AFTER the allocation: one carrier now claims a different pricing rule.
        $carrier = Shipment::where('order_id', $order->id)->orderByDesc('id')->firstOrFail();
        $carrier->update(['provider_meta' => $this->openRouteMeta(['chargeable_distance_km' => 99.0])]);
        $before = Shipment::where('order_id', $order->id)->orderBy('id')->get()->map(fn ($s) => [$s->id, (string) $s->shipping_fee_snapshot, json_encode($s->provider_meta)])->all();
        $itemsBefore = OrderItem::where('order_id', $order->id)->orderBy('id')->get()->map(fn ($i) => [$i->id, $i->shipment_id, $i->requested_delivery_date?->toDateString(), $i->fulfilled_quantity])->all();

        $this->reschedule($order->id, $parent->id, $d4, 1)->assertStatus(422);

        $this->assertSame($before, Shipment::where('order_id', $order->id)->orderBy('id')->get()->map(fn ($s) => [$s->id, (string) $s->shipping_fee_snapshot, json_encode($s->provider_meta)])->all());
        $this->assertSame($itemsBefore, OrderItem::where('order_id', $order->id)->orderBy('id')->get()->map(fn ($i) => [$i->id, $i->shipment_id, $i->requested_delivery_date?->toDateString(), $i->fulfilled_quantity])->all());
    }

    public function test_two_independent_legacy_incomplete_carriers_still_fail_closed(): void
    {
        // Two nonzero carriers with incomplete provenance that were NOT produced by a canonical allocation.
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b] = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        [$d1, $d2, $d3] = [now()->addDays(3)->toDateString(), now()->addDays(6)->toDateString(), now()->addDays(9)->toDateString()];
        $order = $this->order($konsumen, [[$a, 2], [$b, 1]], $d1);
        $this->asKurirOnline($order, '30000.00', ['reason' => 'legacy_quote']);
        $base = Shipment::where('order_id', $order->id)->firstOrFail();
        $base->update(['shipping_fee_snapshot' => '15000.00']);
        $shell = $base->replicate();
        $shell->shipping_fee_snapshot = '15000.00';
        $shell->save();
        $this->item($order, $b)->update(['requested_delivery_date' => $d2, 'shipment_id' => $shell->id]);

        $this->actingAs($admin);
        $this->reschedule($order->id, $this->item($order, $a)->id, $d3, 1)->assertStatus(422);
        $this->assertSame(2, Shipment::where('order_id', $order->id)->count());
    }

    // ===================== FA-02 — CLASSIFIER HAS NO CASE / WHITESPACE LENIENCY =====================

    public function test_classifier_only_accepts_the_exact_persisted_canonical_code(): void
    {
        foreach (['rajaongkir' => ShippingMethodClassifier::EKSPEDISI, 'openroute' => ShippingMethodClassifier::KURIR_ONLINE, 'free' => ShippingMethodClassifier::FREE, 'pickup' => ShippingMethodClassifier::PICKUP] as $code => $expected) {
            $this->assertSame($expected, ShippingMethodClassifier::classify($code));
        }
        foreach ([' openroute', 'OpenRoute', 'OPENROUTE', 'openroute ', 'Free', 'PICKUP', " rajaongkir\n", null, '', ' '] as $code) {
            $this->assertSame(ShippingMethodClassifier::UNKNOWN, ShippingMethodClassifier::classify($code), 'non-canonical code '.var_export($code, true));
        }
    }

    public function test_noncanonical_cased_openroute_code_cannot_reschedule(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        $order = $this->order($konsumen, [[$a, 2]], now()->addDays(3)->toDateString());
        $this->asKurirOnline($order, '30000.00', $this->openRouteMeta());
        Shipment::where('order_id', $order->id)->update(['shipping_provider_code' => 'OpenRoute']);

        $this->actingAs($admin);
        $this->reschedule($order->id, $this->item($order, $a)->id, now()->addDays(9)->toDateString(), 1)->assertStatus(422);
        $this->assertSame(1, OrderItem::where('order_id', $order->id)->count());
    }

    // ===================== FA-03 — EXACT MONEY / EXACT META COMPARISON =====================

    private function toMinor(mixed $value): int
    {
        $method = new ReflectionMethod(ShipmentGroupingService::class, 'toMinorUnits');

        return $method->invoke(null, $value);
    }

    /** @return array<string, array{0:string|int,1:int}> */
    public static function validMoney(): array
    {
        return [
            '0' => ['0', 0], '0.00' => ['0.00', 0], '0.01' => ['0.01', 1], '0.99' => ['0.99', 99], '1' => ['1', 100], '1.00' => ['1.00', 100],
            '1.01' => ['1.01', 101], '30000.00' => ['30000.00', 3000000], '30000.01' => ['30000.01', 3000001],
            '9999999999.99' => ['9999999999.99', 999999999999], 'trailing zero fraction beyond 2dp' => ['12.500', 1250], 'single decimal' => ['0.5', 50], 'int' => [30000, 3000000],
        ];
    }

    #[DataProvider('validMoney')]
    public function test_money_conversion_is_exact(string|int $value, int $expected): void
    {
        $this->assertSame($expected, $this->toMinor($value));
    }

    /** @return array<string, array{0:string}> */
    public static function invalidMoney(): array
    {
        return [
            'negative' => ['-1.00'], 'negative zero fraction' => ['-0.01'], 'third decimal truncation' => ['10.005'], 'exponent' => ['1e3'],
            'letters' => ['12abc'], 'double dot' => ['1.5.2'], 'space inside' => ['1 000'], 'comma' => ['1,000.00'],
        ];
    }

    #[DataProvider('invalidMoney')]
    public function test_malformed_or_negative_money_fails_safely_never_silently_converted(string $value): void
    {
        $this->expectException(ApiException::class);
        $this->toMinor($value);
    }

    public function test_openroute_provenance_differences_below_two_decimals_are_still_conflicts(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b] = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        [$d1, $d2, $d3] = [now()->addDays(3)->toDateString(), now()->addDays(6)->toDateString(), now()->addDays(9)->toDateString()];
        $order = $this->order($konsumen, [[$a, 2], [$b, 1]], $d1);
        $metaA = $this->openRouteMeta(['chargeable_distance_km' => 7.121]);
        $metaB = $this->openRouteMeta(['chargeable_distance_km' => 7.129]);
        $this->asKurirOnline($order, '30000.00', $metaA);
        $base = Shipment::where('order_id', $order->id)->firstOrFail();
        $base->update(['shipping_fee_snapshot' => '15000.00']);
        $shell = $base->replicate();
        $shell->shipping_fee_snapshot = '15000.00';
        $shell->provider_meta = $metaB;
        $shell->save();
        $this->item($order, $b)->update(['requested_delivery_date' => $d2, 'shipment_id' => $shell->id]);

        $this->actingAs($admin);
        $this->reschedule($order->id, $this->item($order, $a)->id, $d3, 1)->assertStatus(422);
    }

    // ===================== FA-04 — MALFORMED METADATA NEVER 500s =====================

    public function test_malformed_nonscalar_provider_meta_fails_closed_without_server_error(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b] = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $order = $this->order($konsumen, [[$a, 1], [$b, 1]], now()->addDays(3)->toDateString());
        $base = Shipment::where('order_id', $order->id)->firstOrFail();
        $base->update(['shipping_provider_code' => 'rajaongkir', 'shipping_fee_snapshot' => '25000.00', 'provider_meta' => json_encode(['courier' => ['jne'], 'service' => ['REG']])]);
        $shell = $base->replicate();
        $shell->save();
        $this->item($order, $b)->update(['shipment_id' => $shell->id]);

        try {
            app(ShipmentGroupingService::class)->reconcileOrder($order, null);
            $this->fail('two carriers with malformed identity must fail closed');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status());
        }
        $this->assertSame(2, Shipment::where('order_id', $order->id)->count());
    }

    // ===================== FA-05 — NEW GROUPS CLONE THE RELEVANT PROVIDER SNAPSHOT; NO SILENT SKIP =====================

    public function test_new_group_clones_the_relevant_shipment_not_an_irrelevant_lower_id_remnant(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        [$d1, $d2] = [now()->addDays(3)->toDateString(), now()->addDays(9)->toDateString()];
        $order = $this->order($konsumen, [[$a, 2]], $d1);
        $this->asKurirOnline($order, '30000.00', $this->openRouteMeta());

        // Lowest-id shipment becomes an irrelevant remnant (no active item, zero fee, no provider code);
        // the real openroute quote lives on a later shipment that holds the items.
        $remnant = Shipment::where('order_id', $order->id)->firstOrFail();
        $live = $remnant->replicate();
        $live->save();
        OrderItem::where('order_id', $order->id)->update(['shipment_id' => $live->id]);
        $remnant->update(['shipping_provider_code' => null, 'shipping_fee_snapshot' => '0.00', 'provider_meta' => null]);

        $this->assertSame(ShippingMethodClassifier::KURIR_ONLINE, ShipmentGroupingService::shippingClassification($order));
        $this->actingAs($admin);
        $response = $this->reschedule($order->id, $this->item($order, $a)->id, $d2, 1);
        $this->assertSame(200, $response->status(), $response->getContent());

        $this->assertSame(ShippingMethodClassifier::KURIR_ONLINE, ShipmentGroupingService::shippingClassification($order));
        $this->assertSame(3000000, collect($this->feeByDate($order))->sum());
        $this->assertCount(2, $this->feeByDate($order));
        $this->assertSame(0, Shipment::where('order_id', $order->id)->whereIn('id', OrderItem::where('order_id', $order->id)->pluck('shipment_id'))->whereNull('shipping_provider_code')->count());
    }

    public function test_post_mutation_classification_drift_fails_closed_instead_of_skipping_allocation(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b] = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $order = $this->order($konsumen, [[$a, 1], [$b, 1]], now()->addDays(3)->toDateString());
        $this->asKurirOnline($order, '30000.00', $this->openRouteMeta());
        $shell = Shipment::where('order_id', $order->id)->firstOrFail()->replicate();
        $shell->shipping_provider_code = null;
        $shell->save();
        $this->item($order, $b)->update(['shipment_id' => $shell->id]);

        $this->expectException(ApiException::class);
        app(ShipmentGroupingService::class)->applyShippingFeeAfterReschedule($order, ShippingMethodClassifier::KURIR_ONLINE);
    }
}
