<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\SubStockReservation;
use App\Models\SubStockRequest;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use App\Services\Order\OrderService;
use App\Services\Stock\SubStockRequestService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;
use Tests\Support\ConcurrencyHarness;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

/**
 * MAJOR C — concurrent multi-target Sub checkout.
 *
 * SubStockService::reserve() has a correct PER-TARGET order (Sub WarehouseStock row, then Sub
 * reservation rows) but a multi-line Sub checkout used to reach its targets in incoming line order,
 * so [A, B] vs [B, A] could each hold one Sub row and wait on the other. OrderService now prelocks the
 * Sub targets canonically (SubStockService::lockReservationTargets) before the per-line loop.
 *
 * The two-connection races below drive the REAL Sub reservation sequence (production prelock +
 * production reserve) so they are deterministic and have teeth: with the prelock skipped the same
 * race deadlocks (positive control). A separate functional test proves OrderService wires the prelock
 * in through the real checkout — two REAL checkouts cannot race here because they serialize on the
 * orders.order_no 'TEMP' placeholder, which is unrelated to Sub row locking.
 */
class SubCheckoutConcurrencyTest extends TestCase
{
    use RestoresIsolatedTestDatabase;

    private const DESTINATION = [
        'recipient_name' => 'Sub Checkout Buyer',
        'recipient_phone' => '0811000000',
        'address_line' => 'Sub Checkout Race Address',
        'village_id' => null,
        'latitude' => -6.2,
        'longitude' => 106.8,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    /** @return array<string, mixed> */
    private function subFixture(string $kind, int $stock = 10, int $quantity = 8): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        AgentProfile::create(['user_id' => $agent->id, 'store_name' => 'Sub Race Branch', 'address' => 'Sub Race Address', 'latitude' => -6.2, 'longitude' => 106.8]);
        $sub = User::factory()->salesKurirSub()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $location = WarehouseSubLocation::create(['agent_id' => $agent->id, 'code' => 'SC-'.Str::random(6), 'name' => 'Sub Checkout Race', 'created_by' => $agent->id]);
        $location->forceFill(['owner_user_id' => $sub->id])->save();

        $targets = [];
        if ($kind === 'product') {
            foreach (['a', 'b'] as $key) {
                $product = Product::create(['sku' => 'SC-'.strtoupper($key).'-'.Str::uuid(), 'name' => 'SC '.$key, 'slug' => 'sc-'.$key.'-'.Str::uuid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
                $targets[$key] = ['product_id' => $product->id, 'variation_id' => null];
            }
        } elseif ($kind === 'variation') {
            $product = Product::create(['sku' => null, 'name' => 'SC Variation', 'slug' => 'sc-variation-'.Str::uuid(), 'has_variations' => true, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
            foreach (['v1', 'v2'] as $key) {
                $variation = ProductVariation::create(['product_id' => $product->id, 'sku' => 'SC-V-'.Str::uuid(), 'price' => 1000, 'weight_grams' => 100, 'is_active' => true, 'sort_order' => 0]);
                $targets[$key] = ['product_id' => $product->id, 'variation_id' => $variation->id];
            }
        } else {
            $simple = Product::create(['sku' => 'SC-MIX-'.Str::uuid(), 'name' => 'SC Mixed Simple', 'slug' => 'sc-mixed-'.Str::uuid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
            $targets['product'] = ['product_id' => $simple->id, 'variation_id' => null];
            $variationProduct = Product::create(['sku' => null, 'name' => 'SC Mixed Variation', 'slug' => 'sc-mixed-v-'.Str::uuid(), 'has_variations' => true, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
            $variation = ProductVariation::create(['product_id' => $variationProduct->id, 'sku' => 'SC-MIXV-'.Str::uuid(), 'price' => 1000, 'weight_grams' => 100, 'is_active' => true, 'sort_order' => 0]);
            $targets['variation'] = ['product_id' => $variationProduct->id, 'variation_id' => $variation->id];
        }

        foreach ($targets as $target) {
            WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $target['variation_id'] ? null : $target['product_id'], 'product_variation_id' => $target['variation_id'], 'stock_type' => 'sub', 'sub_location_id' => $location->id, 'quantity' => $stock]);
        }

        return compact('agent', 'sub', 'location', 'targets') + ['stock' => $stock, 'quantity' => $quantity];
    }

    /** Creates a committed order with one item per target, in the given target-key order. @return array<int, int> item ids in that order */
    private function orderItemIds(array $f, array $targetKeys): array
    {
        $order = Order::create([
            'order_no' => 'SC-'.strtoupper(Str::random(12)), 'konsumen_id' => $f['sub']->id, 'agent_id' => $f['agent']->id,
            'payment_method_id' => PaymentMethod::query()->where('code', 'cod')->value('id'), 'status' => 'diproses', 'payment_status' => 'unpaid',
            'subtotal_amount' => 0, 'shipping_fee_amount' => 0, 'admin_fee_amount' => 0, 'total_amount' => 0,
            'recipient_name_snapshot' => 'R', 'recipient_phone_snapshot' => '0', 'address_snapshot' => 'R',
        ]);

        $ids = [];
        foreach ($targetKeys as $key) {
            $target = $f['targets'][$key];
            $ids[] = OrderItem::create([
                'order_id' => $order->id, 'product_id' => $target['product_id'],
                'product_variation_id' => $target['variation_id'], 'stock_source' => 'sub', 'sub_location_id' => $f['location']->id,
                'product_name_snapshot' => 'SC', 'sku_snapshot' => 'SC', 'unit_price_snapshot' => 1000, 'subtotal_snapshot' => 1000 * $f['quantity'],
                'original_quantity' => $f['quantity'], 'fulfilled_quantity' => $f['quantity'], 'status' => 'diproses',
            ])->id;
        }

        return $ids;
    }

    public function test_two_multi_target_sub_reservations_in_opposite_orders_never_deadlock_or_oversell(): void
    {
        foreach (['product', 'variation', 'mixed'] as $kind) {
            for ($iteration = 1; $iteration <= 2; $iteration++) {
                $f = $this->subFixture($kind);
                $keys = array_keys($f['targets']);
                $itemsA = $this->orderItemIds($f, [$keys[0], $keys[1]]);
                $itemsB = $this->orderItemIds($f, [$keys[1], $keys[0]]);

                $report = (new ConcurrencyHarness)->runServiceRace(
                    ['op' => 'sub-reserve-order', 'actor_id' => $f['sub']->id, 'extra' => ['sub_location_id' => $f['location']->id, 'order_item_ids' => $itemsA, 'canonicalize' => true]],
                    ['op' => 'sub-reserve-order', 'actor_id' => $f['sub']->id, 'extra' => ['sub_location_id' => $f['location']->id, 'order_item_ids' => $itemsB, 'canonicalize' => true]],
                );

                $this->assertTrue($report['different_connections'], $kind);
                $this->assertTrue($report['true_overlap'], $kind);
                $this->assertSame(0, $this->deadlockCount($report), "{$kind}: no deadlock may cause a checkout failure: ".json_encode(['a' => $report['a'], 'b' => $report['b']]));
                $this->assertSame(1, collect([$report['a'], $report['b']])->where('outcome', 'success')->count(), "{$kind}: constrained stock allows exactly one winner");
                $this->assertSame(InsufficientStockException::class, $this->loser($report)['exception'] ?? null, "{$kind}: the loser must fail on insufficient stock, not lock order");

                foreach ($f['targets'] as $label => $target) {
                    $physical = $this->physical($f, $target);
                    $reserved = $this->activeReserved($f, $target);

                    $this->assertSame($f['stock'], $physical, "{$kind}/{$label}: reservation must not change physical stock");
                    $this->assertSame($f['quantity'], $reserved, "{$kind}/{$label}: exactly the winner's reservation");
                    $this->assertLessThanOrEqual($physical, $reserved, "{$kind}/{$label}: reservations must never exceed physical");
                }

                $this->assertSame(2, SubStockReservation::query()->where('sub_location_id', $f['location']->id)->where('status', 'active')->count(), "{$kind}: no duplicate reservation");

                fwrite(STDOUT, 'SUB_CHECKOUT_RACE '.json_encode(['kind' => $kind, 'iteration' => $iteration, 'a' => $report['a']['outcome'], 'b' => $report['b']['outcome']]).PHP_EOL);
            }
        }
    }

    public function test_inverted_order_without_canonical_sub_prelock_deadlocks(): void
    {
        $f = $this->subFixture('product');
        $keys = array_keys($f['targets']);
        $itemsA = $this->orderItemIds($f, [$keys[0], $keys[1]]);
        $itemsB = $this->orderItemIds($f, [$keys[1], $keys[0]]);

        $aFirstKey = 'p:'.$f['targets'][$keys[1]]['product_id']; // side A pauses on target B
        $bFirstKey = 'p:'.$f['targets'][$keys[0]]['product_id']; // side B pauses on target A
        $barrier = $this->createBarrierDir();

        try {
            $report = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'sub-reserve-order', 'actor_id' => $f['sub']->id, 'extra' => ['sub_location_id' => $f['location']->id, 'order_item_ids' => $itemsA, 'canonicalize' => false, 'barrier_dir' => $barrier, 'side' => 'a', 'peer_first_key' => $aFirstKey]],
                ['op' => 'sub-reserve-order', 'actor_id' => $f['sub']->id, 'extra' => ['sub_location_id' => $f['location']->id, 'order_item_ids' => $itemsB, 'canonicalize' => false, 'barrier_dir' => $barrier, 'side' => 'b', 'peer_first_key' => $bFirstKey]],
            );

            $this->assertSame(1, $this->deadlockCount($report), 'skipping the canonical Sub prelock must deadlock (positive control): '.json_encode(['a' => $report['a'], 'b' => $report['b']]));
        } finally {
            $this->removeDir($barrier);
        }
    }

    public function test_real_multi_line_sub_checkout_reserves_both_targets(): void
    {
        $f = $this->subFixture('mixed', stock: 10, quantity: 8);

        // The real checkout path: OrderService resolves the lines, prelocks the Sub targets canonically
        // and only then reserves each target.
        $order = app(OrderService::class)->createOrder($f['sub'], [
            ['product_id' => $f['targets']['product']['product_id'], 'quantity' => 8],
            ['product_id' => $f['targets']['variation']['product_id'], 'product_variation_id' => $f['targets']['variation']['variation_id'], 'quantity' => 8],
        ], self::DESTINATION, $f['sub'], 'cod', null, 'sc-'.Str::uuid(), null, null, null, 'sub', $f['location']->id);

        $this->assertSame(2, $order->items()->count());
        foreach ($f['targets'] as $label => $target) {
            $this->assertSame(8, $this->activeReserved($f, $target), "{$label}: reserved");
            $this->assertSame(10, $this->physical($f, $target), "{$label}: physical unchanged by reservation");
        }
    }

    public function test_real_sub_checkout_and_sub_execution_never_deadlock_and_keep_invariants(): void
    {
        for ($iteration = 1; $iteration <= 3; $iteration++) {
            $f = $this->subFixture('product', stock: 10, quantity: 5);
            $target = $f['targets']['a'];
            WarehouseStock::create(['agent_id' => $f['agent']->id, 'product_id' => $target['product_id'], 'stock_type' => 'transit', 'quantity' => 10]);
            $admin = User::factory()->admin()->create(['agent_id' => $f['agent']->id]);
            $gudang = User::factory()->gudang()->create(['agent_id' => $f['agent']->id, 'parent_id' => $f['agent']->id]);
            $service = app(SubStockRequestService::class);
            $request = $service->create($f['sub'], 'replenish', [['product_id' => $target['product_id'], 'quantity' => 5]]);
            $service->approve($admin, $request);

            $report = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'checkout-order', 'actor_id' => $f['sub']->id, 'extra' => [
                    'buyer_id' => $f['sub']->id, 'destination' => self::DESTINATION, 'stock_source' => 'sub', 'sub_location_id' => $f['location']->id,
                    'lines' => [['product_id' => $target['product_id'], 'quantity' => 5]],
                ]],
                ['op' => 'sub-request-execute', 'actor_id' => $gudang->id, 'subject_id' => $request->id],
            );

            $this->assertTrue($report['different_connections']);
            $this->assertTrue($report['true_overlap']);
            $this->assertSame(0, $this->deadlockCount($report), 'checkout vs execution must never deadlock: '.json_encode(['a' => $report['a'], 'b' => $report['b']]));
            $this->assertSame(2, collect([$report['a'], $report['b']])->where('outcome', 'success')->count(), 'both operations have legitimate capacity and must succeed');

            $physical = $this->physical($f, $target);
            $reserved = $this->activeReserved($f, $target);

            // Reservation never changes physical; only the executed replenishment does (10 -> 15).
            $this->assertSame(15, $physical, 'physical only changes for the operation that moves stock');
            $this->assertSame(5, $reserved, 'checkout reservation');
            $this->assertLessThanOrEqual($physical, $reserved, 'reservation must never exceed physical');

            $transit = (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $f['agent']->id)->where('product_id', $target['product_id'])->where('stock_type', 'transit')->value('quantity');
            $this->assertSame(5, $transit, 'replenishment moved 5 out of Transit exactly once');

            $this->assertSame('executed', SubStockRequest::withoutGlobalScopes()->find($request->id)->status);
            $this->assertSame(1, Order::withoutGlobalScopes()->where('konsumen_id', $f['sub']->id)->count());
            $this->assertSame(2, \App\Models\StockMovement::withoutGlobalScopes()->whereIn('type', ['transfer_in', 'transfer_out'])->where('agent_id', $f['agent']->id)->count(), 'one transfer moves stock at most once');

            fwrite(STDOUT, 'SUB_CHECKOUT_EXECUTE_RACE '.json_encode(['iteration' => $iteration, 'checkout' => $report['a']['outcome'], 'execute' => $report['b']['outcome']]).PHP_EOL);
        }
    }

    public function test_parent_location_lock_precedes_stock_targets_and_inverted_order_deadlocks(): void
    {
        $f = $this->subFixture('product');
        $keys = array_keys($f['targets']);
        $aTarget = ['product_id' => $f['targets'][$keys[0]]['product_id']];
        $bTarget = ['product_id' => $f['targets'][$keys[1]]['product_id']];
        $locationId = $f['location']->id;
        $locKey = 'loc:'.$locationId;
        $aKey = 'p:'.$f['targets'][$keys[0]]['product_id'];
        $barrier = $this->createBarrierDir();

        try {
            // Positive control: the pre-fix checkout order (stock targets first, parent row only at the
            // FK insert) deadlocks against the executor's parent-first order.
            $inverted = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'sub-location-stock-lock', 'actor_id' => $f['sub']->id, 'extra' => ['location_id' => $locationId, 'location_mode' => 'share', 'location_first' => false, 'canonicalize' => true, 'targets' => [$aTarget, $bTarget], 'barrier_dir' => $barrier, 'side' => 'a', 'peer_first_key' => $locKey]],
                ['op' => 'sub-location-stock-lock', 'actor_id' => $f['sub']->id, 'extra' => ['location_id' => $locationId, 'location_mode' => 'exclusive', 'location_first' => true, 'canonicalize' => true, 'targets' => [$aTarget, $bTarget], 'barrier_dir' => $barrier, 'side' => 'b', 'peer_first_key' => $aKey]],
            );
            $this->clearBarrierDir($barrier);
            $this->assertSame(1, $this->deadlockCount($inverted), 'stock-before-parent order must deadlock (positive control)');

            // Fixed: the checkout takes the parent (shared) first, so both sides serialize at the
            // location boundary and no cycle can form.
            $fixed = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'sub-location-stock-lock', 'actor_id' => $f['sub']->id, 'extra' => ['location_id' => $locationId, 'location_mode' => 'share', 'location_first' => true, 'canonicalize' => true, 'targets' => [$aTarget, $bTarget], 'barrier_dir' => $barrier, 'side' => 'a', 'peer_first_key' => $locKey]],
                ['op' => 'sub-location-stock-lock', 'actor_id' => $f['sub']->id, 'extra' => ['location_id' => $locationId, 'location_mode' => 'exclusive', 'location_first' => true, 'canonicalize' => true, 'targets' => [$aTarget, $bTarget], 'barrier_dir' => $barrier, 'side' => 'b', 'peer_first_key' => $locKey]],
            );

            $this->assertSame(0, $this->deadlockCount($fixed), 'parent-first order must never deadlock: '.json_encode(['a' => $fixed['a'], 'b' => $fixed['b']]));
            $this->assertSame(2, collect([$fixed['a'], $fixed['b']])->where('outcome', 'success')->count());
        } finally {
            $this->removeDir($barrier);
        }
    }

    private function physical(array $f, array $target): int
    {
        return (int) WarehouseStock::withoutGlobalScopes()->where('sub_location_id', $f['location']->id)->where('stock_type', 'sub')
            ->when($target['variation_id'], fn ($q) => $q->where('product_variation_id', $target['variation_id'])->whereNull('product_id'), fn ($q) => $q->where('product_id', $target['product_id'])->whereNull('product_variation_id'))
            ->value('quantity');
    }

    private function activeReserved(array $f, array $target): int
    {
        return (int) SubStockReservation::query()->where('sub_location_id', $f['location']->id)->where('status', 'active')
            ->when($target['variation_id'], fn ($q) => $q->where('product_variation_id', $target['variation_id'])->whereNull('product_id'), fn ($q) => $q->where('product_id', $target['product_id'])->whereNull('product_variation_id'))
            ->sum('quantity');
    }

    private function loser(array $report): array
    {
        return $report['a']['outcome'] === 'success' ? $report['b'] : $report['a'];
    }

    private function deadlockCount(array $report): int
    {
        return collect([$report['a'], $report['b']])->filter(function ($actor) {
            $sqlState = (string) ($actor['sql_state'] ?? '');
            $message = (string) ($actor['message'] ?? '');

            return $sqlState === '40001' || stripos($message, 'deadlock') !== false || stripos($message, 'lock wait timeout') !== false;
        })->count();
    }

    private function createBarrierDir(): string
    {
        $dir = storage_path('framework/testing/concurrency/sublockorder-'.bin2hex(random_bytes(6)));
        mkdir($dir, 0775, true);

        return $dir;
    }

    private function clearBarrierDir(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function removeDir(string $dir): void
    {
        $this->clearBarrierDir($dir);
        @rmdir($dir);
    }
}
