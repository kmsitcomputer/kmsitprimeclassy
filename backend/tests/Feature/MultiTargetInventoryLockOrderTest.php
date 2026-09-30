<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Exceptions\InsufficientStockException;
use App\Models\AgentProfile;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariation;
use App\Models\ProductVariationStock;
use App\Models\SubStockRequest;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use App\Services\Stock\SubStockRequestService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;
use Tests\Support\ConcurrencyHarness;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

/**
 * Finding 1 — multi-target deadlock inversion.
 *
 * A checkout that reserves several Agent targets and a Transit -> Sub execution that moves several
 * targets must acquire their targets in ONE canonical order (StockService::canonicalTargetKey), or
 * two concurrent transactions that list the same targets in opposite orders can each hold one
 * target and wait on the other. These real two-connection regressions use committed fixtures and
 * drive the ACTUAL production entry points (OrderService::createOrder and SubStockRequestService::
 * execute), with a deterministic file-barrier variant that proves the ordering contract has teeth.
 */
class MultiTargetInventoryLockOrderTest extends TestCase
{
    use RestoresIsolatedTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private const DESTINATION = [
        'recipient_name' => 'Concurrent Buyer',
        'recipient_phone' => '0811000000',
        'address_line' => 'Multi Target Lock Order Test Address',
        'village_id' => null,
        'latitude' => -6.2,
        'longitude' => 106.8,
    ];

    public function test_inverted_multi_target_order_deadlocks_while_canonical_order_does_not(): void
    {
        $f = $this->productFixture(transit: 10, requestQty: 8, checkoutQty: 8);
        $aKey = 'p:'.$f['products']['a']->id;
        $bKey = 'p:'.$f['products']['b']->id;
        $barrier = $this->createBarrierDir();

        try {
            $inverted = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'agent-lock-multi', 'actor_id' => $f['agent']->id, 'extra' => [
                    'agent_id' => $f['agent']->id, 'canonicalize' => false, 'side' => 'a', 'peer_first_key' => $aKey,
                    'barrier_dir' => $barrier, 'targets' => [['product_id' => $f['products']['b']->id], ['product_id' => $f['products']['a']->id]],
                ]],
                ['op' => 'agent-lock-multi', 'actor_id' => $f['agent']->id, 'extra' => [
                    'agent_id' => $f['agent']->id, 'canonicalize' => false, 'side' => 'b', 'peer_first_key' => $bKey,
                    'barrier_dir' => $barrier, 'targets' => [['product_id' => $f['products']['a']->id], ['product_id' => $f['products']['b']->id]],
                ]],
            );
            $this->clearBarrierDir($barrier);
            $this->assertTrue($inverted['different_connections']);
            $this->assertSame(1, $this->deadlockCount($inverted), 'opposite target orders must deadlock: '.json_encode(['a' => $inverted['a'], 'b' => $inverted['b']]));

            $canonical = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'agent-lock-multi', 'actor_id' => $f['agent']->id, 'extra' => [
                    'agent_id' => $f['agent']->id, 'canonicalize' => true, 'side' => 'a', 'peer_first_key' => $aKey,
                    'barrier_dir' => $barrier, 'targets' => [['product_id' => $f['products']['b']->id], ['product_id' => $f['products']['a']->id]],
                ]],
                ['op' => 'agent-lock-multi', 'actor_id' => $f['agent']->id, 'extra' => [
                    'agent_id' => $f['agent']->id, 'canonicalize' => true, 'side' => 'b', 'peer_first_key' => $aKey,
                    'barrier_dir' => $barrier, 'targets' => [['product_id' => $f['products']['a']->id], ['product_id' => $f['products']['b']->id]],
                ]],
            );

            $this->assertSame(0, $this->deadlockCount($canonical), 'canonical order must never deadlock: '.json_encode(['a' => $canonical['a'], 'b' => $canonical['b']]));
            $this->assertSame(2, collect([$canonical['a'], $canonical['b']])->where('outcome', 'success')->count());
        } finally {
            $this->removeDir($barrier);
        }
    }

    public function test_reversed_product_checkout_and_replenishment_cannot_deadlock_or_overcommit(): void
    {
        for ($iteration = 1; $iteration <= 3; $iteration++) {
            $f = $this->productFixture(transit: 10, requestQty: 10, checkoutQty: 10);

            $report = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'checkout-order', 'actor_id' => $f['buyer']->id, 'extra' => [
                    'buyer_id' => $f['buyer']->id, 'destination' => self::DESTINATION,
                    'lines' => [
                        ['product_id' => $f['products']['b']->id, 'quantity' => 10],
                        ['product_id' => $f['products']['a']->id, 'quantity' => 10],
                    ],
                ]],
                ['op' => 'sub-request-execute', 'actor_id' => $f['gudang']->id, 'subject_id' => $f['request']->id],
            );

            $this->assertTrue($report['different_connections']);
            $this->assertTrue($report['true_overlap']);
            $this->assertSame(0, $this->deadlockCount($report), 'no deadlock may cause an application failure: '.json_encode(['a' => $report['a'], 'b' => $report['b']]));
            $this->assertSame(1, collect([$report['a'], $report['b']])->where('outcome', 'success')->count(), 'combined demand exceeds capacity, so exactly one side may win: '.json_encode(['a' => $report['a'], 'b' => $report['b']]));
            $this->assertCapacityLoser($report);

            $targets = [
                'a' => [$f['products']['a']->id, null],
                'b' => [$f['products']['b']->id, null],
            ];
            $this->assertScenarioInvariants($f, $report['a']['outcome'] === 'success', $targets, 10);
            fwrite(STDOUT, 'MULTI_TARGET_LOCK_PRODUCT '.json_encode(['iteration' => $iteration, 'checkout' => $report['a']['outcome'], 'replenish' => $report['b']['outcome'], 'checkout_message' => $report['a']['message'] ?? null, 'replenish_message' => $report['b']['message'] ?? null]).PHP_EOL);
        }
    }

    public function test_reversed_variation_checkout_and_replenishment_cannot_deadlock_or_overcommit(): void
    {
        for ($iteration = 1; $iteration <= 3; $iteration++) {
            $f = $this->variationFixture(transit: 10, requestQty: 10, checkoutQty: 10);

            $report = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'checkout-order', 'actor_id' => $f['buyer']->id, 'extra' => [
                    'buyer_id' => $f['buyer']->id, 'destination' => self::DESTINATION,
                    'lines' => [
                        ['product_id' => $f['product']->id, 'product_variation_id' => $f['variations']['v2']->id, 'quantity' => 10],
                        ['product_id' => $f['product']->id, 'product_variation_id' => $f['variations']['v1']->id, 'quantity' => 10],
                    ],
                ]],
                ['op' => 'sub-request-execute', 'actor_id' => $f['gudang']->id, 'subject_id' => $f['request']->id],
            );

            $this->assertTrue($report['different_connections']);
            $this->assertTrue($report['true_overlap']);
            $this->assertSame(0, $this->deadlockCount($report), 'no deadlock may cause an application failure: '.json_encode(['a' => $report['a'], 'b' => $report['b']]));
            $this->assertSame(1, collect([$report['a'], $report['b']])->where('outcome', 'success')->count(), 'exactly one side may win: '.json_encode(['a' => $report['a'], 'b' => $report['b']]));
            $this->assertCapacityLoser($report);

            $targets = [
                'v1' => [null, $f['variations']['v1']->id],
                'v2' => [null, $f['variations']['v2']->id],
            ];
            $this->assertScenarioInvariants($f, $report['a']['outcome'] === 'success', $targets, 10);
            fwrite(STDOUT, 'MULTI_TARGET_LOCK_VARIATION '.json_encode(['iteration' => $iteration, 'checkout' => $report['a']['outcome'], 'replenish' => $report['b']['outcome'], 'checkout_message' => $report['a']['message'] ?? null, 'replenish_message' => $report['b']['message'] ?? null]).PHP_EOL);
        }
    }

    public function test_reversed_mixed_product_and_variation_checkout_and_replenishment_cannot_deadlock_or_overcommit(): void
    {
        for ($iteration = 1; $iteration <= 3; $iteration++) {
            $f = $this->mixedFixture(transit: 10, requestQty: 10, checkoutQty: 10);

            $report = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'checkout-order', 'actor_id' => $f['buyer']->id, 'extra' => [
                    'buyer_id' => $f['buyer']->id, 'destination' => self::DESTINATION,
                    'lines' => [
                        ['product_id' => $f['variationProduct']->id, 'product_variation_id' => $f['variation']->id, 'quantity' => 10],
                        ['product_id' => $f['simpleProduct']->id, 'quantity' => 10],
                    ],
                ]],
                ['op' => 'sub-request-execute', 'actor_id' => $f['gudang']->id, 'subject_id' => $f['request']->id],
            );

            $this->assertTrue($report['different_connections']);
            $this->assertTrue($report['true_overlap']);
            $this->assertSame(0, $this->deadlockCount($report), 'no deadlock may cause an application failure: '.json_encode(['a' => $report['a'], 'b' => $report['b']]));
            $this->assertSame(1, collect([$report['a'], $report['b']])->where('outcome', 'success')->count(), 'exactly one side may win: '.json_encode(['a' => $report['a'], 'b' => $report['b']]));
            $this->assertCapacityLoser($report);

            $targets = [
                'product' => [$f['simpleProduct']->id, null],
                'variation' => [null, $f['variation']->id],
            ];
            $this->assertScenarioInvariants($f, $report['a']['outcome'] === 'success', $targets, 10);
            fwrite(STDOUT, 'MULTI_TARGET_LOCK_MIXED '.json_encode(['iteration' => $iteration, 'checkout' => $report['a']['outcome'], 'replenish' => $report['b']['outcome'], 'checkout_message' => $report['a']['message'] ?? null, 'replenish_message' => $report['b']['message'] ?? null]).PHP_EOL);
        }
    }

    /**
     * @param  array<string, array{0:?int, 1:?int}>  $targets  keyed label => [product_id, variation_id]
     */
    private function assertScenarioInvariants(array $f, bool $checkoutWon, array $targets, int $capacity): void
    {
        foreach ($targets as $label => [$productId, $variationId]) {
            $reserved = $this->agentReserved($f['agent']->id, $productId, $variationId);
            $transit = $this->transit($f['agent']->id, $productId, $variationId);
            $sub = $this->subQuantity($f['location']->id, $productId, $variationId);

            // Transit may never fall below the committed Agent reservation (the 5d545ec invariant).
            $this->assertGreaterThanOrEqual($reserved, $transit, "target {$label}: Transit below committed reservation");

            if ($checkoutWon) {
                $this->assertSame($capacity, $reserved, "target {$label}: checkout reservation");
                $this->assertSame($capacity, $transit, "target {$label}: Transit untouched by a losing replenishment");
                $this->assertSame(0, $sub, "target {$label}: no Transit moved to Sub");
            } else {
                $this->assertSame(0, $reserved, "target {$label}: no Agent reservation from the losing checkout");
                $this->assertSame(0, $transit, "target {$label}: replenishment moved Transit to Sub");
                $this->assertSame($capacity, $sub, "target {$label}: Sub received the replenishment");
            }
        }

        $this->assertSame($checkoutWon ? 'approved' : 'executed', SubStockRequest::withoutGlobalScopes()->find($f['request']->id)->status);
    }

    private function agentReserved(int $agentId, ?int $productId, ?int $variationId): int
    {
        return $variationId !== null
            ? (int) ProductVariationStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('product_variation_id', $variationId)->value('quantity_reserved')
            : (int) ProductStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('product_id', $productId)->value('quantity_reserved');
    }

    private function transit(int $agentId, ?int $productId, ?int $variationId): int
    {
        $query = WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('stock_type', 'transit')->whereNull('sub_location_id');

        return (int) ($variationId !== null
            ? $query->where('product_variation_id', $variationId)->whereNull('product_id')->value('quantity')
            : $query->where('product_id', $productId)->whereNull('product_variation_id')->value('quantity'));
    }

    private function subQuantity(int $locationId, ?int $productId, ?int $variationId): int
    {
        $query = WarehouseStock::withoutGlobalScopes()->where('stock_type', 'sub')->where('sub_location_id', $locationId);

        return (int) ($variationId !== null
            ? $query->where('product_variation_id', $variationId)->whereNull('product_id')->value('quantity')
            : $query->where('product_id', $productId)->whereNull('product_variation_id')->value('quantity'));
    }

    private function deadlockCount(array $report): int
    {
        return collect([$report['a'], $report['b']])->filter(function ($actor) {
            $sqlState = (string) ($actor['sql_state'] ?? '');
            $message = (string) ($actor['message'] ?? '');

            return $sqlState === '40001' || stripos($message, 'deadlock') !== false || stripos($message, 'lock wait timeout') !== false;
        })->count();
    }

    /** The side that lost may only fail on capacity (InsufficientStock or a 422 ApiException) — never on lock order. */
    private function assertCapacityLoser(array $report): void
    {
        $loser = $report['a']['outcome'] === 'success' ? $report['b'] : $report['a'];
        $this->assertContains(
            $loser['exception'] ?? null,
            [InsufficientStockException::class, ApiException::class],
            'the losing side must fail on insufficient capacity, not on lock order: '.json_encode($loser),
        );
    }

    /** @return array<string, mixed> */
    private function branch(): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        AgentProfile::create(['user_id' => $agent->id, 'store_name' => 'Multi Target Branch', 'address' => 'Multi Target Address', 'latitude' => -6.2, 'longitude' => 106.8]);

        $admin = User::factory()->admin()->create(['agent_id' => $agent->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $sub = User::factory()->salesKurirSub()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $location = WarehouseSubLocation::create(['agent_id' => $agent->id, 'code' => 'MT-'.Str::random(6), 'name' => 'Multi Target', 'created_by' => $agent->id]);
        $location->forceFill(['owner_user_id' => $sub->id])->save();
        $buyer = User::factory()->konsumen()->create(['agent_id' => $agent->id]);

        return compact('agent', 'admin', 'gudang', 'sub', 'location', 'buyer');
    }

    private function approveReplenishment(User $sub, User $admin, array $items): SubStockRequest
    {
        $service = app(SubStockRequestService::class);
        $request = $service->create($sub, 'replenish', $items);
        $service->approve($admin, $request);

        return $request;
    }

    private function productFixture(int $transit, int $requestQty, int $checkoutQty): array
    {
        $b = $this->branch();
        $products = [];
        foreach (['a', 'b'] as $key) {
            $product = Product::create(['sku' => 'MT-'.strtoupper($key).'-'.Str::uuid(), 'name' => 'Multi '.$key, 'slug' => 'multi-'.$key.'-'.Str::uuid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
            ProductStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
            WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => $transit]);
            $products[$key] = $product;
        }

        $request = $this->approveReplenishment($b['sub'], $b['admin'], [
            ['product_id' => $products['a']->id, 'quantity' => $requestQty],
            ['product_id' => $products['b']->id, 'quantity' => $requestQty],
        ]);

        return $b + ['products' => $products, 'request' => $request];
    }

    private function variationFixture(int $transit, int $requestQty, int $checkoutQty): array
    {
        $b = $this->branch();
        $product = Product::create(['sku' => null, 'name' => 'Multi Variation', 'slug' => 'multi-variation-'.Str::uuid(), 'has_variations' => true, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);

        $variations = [];
        foreach (['v1', 'v2'] as $key) {
            $variation = ProductVariation::create(['product_id' => $product->id, 'sku' => 'MT-V-'.Str::uuid(), 'price' => 1000, 'weight_grams' => 100, 'is_active' => true, 'sort_order' => 0]);
            ProductVariationStock::create(['agent_id' => $b['agent']->id, 'product_variation_id' => $variation->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
            WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_variation_id' => $variation->id, 'stock_type' => 'transit', 'quantity' => $transit]);
            $variations[$key] = $variation;
        }

        $request = $this->approveReplenishment($b['sub'], $b['admin'], [
            ['product_variation_id' => $variations['v1']->id, 'quantity' => $requestQty],
            ['product_variation_id' => $variations['v2']->id, 'quantity' => $requestQty],
        ]);

        return $b + ['product' => $product, 'variations' => $variations, 'request' => $request];
    }

    private function mixedFixture(int $transit, int $requestQty, int $checkoutQty): array
    {
        $b = $this->branch();
        $simpleProduct = Product::create(['sku' => 'MT-SIMPLE-'.Str::uuid(), 'name' => 'Multi Simple', 'slug' => 'multi-simple-'.Str::uuid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
        ProductStock::create(['agent_id' => $b['agent']->id, 'product_id' => $simpleProduct->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_id' => $simpleProduct->id, 'stock_type' => 'transit', 'quantity' => $transit]);

        $variationProduct = Product::create(['sku' => null, 'name' => 'Multi Mixed Variation', 'slug' => 'multi-mixed-'.Str::uuid(), 'has_variations' => true, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
        $variation = ProductVariation::create(['product_id' => $variationProduct->id, 'sku' => 'MT-MIX-'.Str::uuid(), 'price' => 1000, 'weight_grams' => 100, 'is_active' => true, 'sort_order' => 0]);
        ProductVariationStock::create(['agent_id' => $b['agent']->id, 'product_variation_id' => $variation->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $b['agent']->id, 'product_variation_id' => $variation->id, 'stock_type' => 'transit', 'quantity' => $transit]);

        $request = $this->approveReplenishment($b['sub'], $b['admin'], [
            ['product_id' => $simpleProduct->id, 'quantity' => $requestQty],
            ['product_variation_id' => $variation->id, 'quantity' => $requestQty],
        ]);

        return $b + ['simpleProduct' => $simpleProduct, 'variationProduct' => $variationProduct, 'variation' => $variation, 'request' => $request];
    }

    private function createBarrierDir(): string
    {
        $dir = storage_path('framework/testing/concurrency/lockorder-'.bin2hex(random_bytes(6)));
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
