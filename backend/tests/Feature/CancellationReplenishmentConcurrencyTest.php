<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
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
 * MAJOR B — cancellation vs Sub replenishment lock inversion.
 *
 * A cancellation reversal releases Agent reservations in order-item order, while a Transit -> Sub
 * execution locks the same Agent commitment/Transit rows in canonical order. For an order listed
 * [B, A] and a replenishment [A, B] this is a concrete cycle. The reversal now prelocks its Agent
 * capacity targets canonically (InventoryCancellationService::lockAgentCapacityForReversal) before
 * the per-item loop, while still processing business effects in item order.
 */
class CancellationReplenishmentConcurrencyTest extends TestCase
{
    use RestoresIsolatedTestDatabase;

    private const DESTINATION = [
        'recipient_name' => 'Cancellation Buyer',
        'recipient_phone' => '0811000000',
        'address_line' => 'Cancellation Race Address',
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
    private function fixture(): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        AgentProfile::create(['user_id' => $agent->id, 'store_name' => 'Cancellation Branch', 'address' => 'Cancellation Address', 'latitude' => -6.2, 'longitude' => 106.8]);
        $admin = User::factory()->admin()->create(['agent_id' => $agent->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $sub = User::factory()->salesKurirSub()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $location = WarehouseSubLocation::create(['agent_id' => $agent->id, 'code' => 'CR-'.Str::random(6), 'name' => 'Cancellation Race', 'created_by' => $agent->id]);
        $location->forceFill(['owner_user_id' => $sub->id])->save();

        $products = [];
        foreach (['a', 'b'] as $key) {
            $product = Product::create(['sku' => 'CR-'.strtoupper($key).'-'.Str::uuid(), 'name' => 'CR '.$key, 'slug' => 'cr-'.$key.'-'.Str::uuid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
            ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
            WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 20]);
            $products[$key] = $product;
        }

        $buyer = User::factory()->konsumen()->create(['agent_id' => $agent->id]);

        // Items created in [B, A] order — the reversal's pre-fix lock order.
        $order = app(OrderService::class)->createOrder($buyer, [
            ['product_id' => $products['b']->id, 'quantity' => 5],
            ['product_id' => $products['a']->id, 'quantity' => 5],
        ], self::DESTINATION, $buyer, 'cod', null, 'cr-'.Str::uuid(), null, null, null, null, null);

        $service = app(SubStockRequestService::class);
        $request = $service->create($sub, 'replenish', [
            ['product_id' => $products['a']->id, 'quantity' => 10],
            ['product_id' => $products['b']->id, 'quantity' => 10],
        ]);
        $service->approve($admin, $request);

        return compact('agent', 'admin', 'gudang', 'sub', 'location', 'products', 'buyer', 'order', 'request');
    }

    public function test_cancellation_and_sub_replenishment_never_deadlock_and_keep_invariants(): void
    {
        for ($iteration = 1; $iteration <= 3; $iteration++) {
            $f = $this->fixture();

            $report = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'cancel-order', 'actor_id' => $f['admin']->id, 'extra' => ['order_id' => $f['order']->id, 'reason' => 'concurrency cancellation race']],
                ['op' => 'sub-request-execute', 'actor_id' => $f['gudang']->id, 'subject_id' => $f['request']->id],
            );

            $this->assertTrue($report['different_connections']);
            $this->assertTrue($report['true_overlap']);
            $this->assertSame(0, $this->deadlockCount($report), 'no deadlock may cause an application failure: '.json_encode(['a' => $report['a'], 'b' => $report['b']]));
            $this->assertSame(2, collect([$report['a'], $report['b']])->where('outcome', 'success')->count(), 'both sides have legitimate capacity and must succeed: '.json_encode(['a' => $report['a'], 'b' => $report['b']]));

            foreach (['a', 'b'] as $key) {
                $productId = $f['products'][$key]->id;
                $reserved = (int) ProductStock::withoutGlobalScopes()->where('agent_id', $f['agent']->id)->where('product_id', $productId)->value('quantity_reserved');
                $transit = (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $f['agent']->id)->where('product_id', $productId)->where('stock_type', 'transit')->value('quantity');
                $sub = (int) WarehouseStock::withoutGlobalScopes()->where('sub_location_id', $f['location']->id)->where('product_id', $productId)->where('stock_type', 'sub')->value('quantity');

                $this->assertSame(0, $reserved, "target {$key}: cancellation must release the reservation");
                $this->assertGreaterThanOrEqual($reserved, $transit, "target {$key}: Transit below committed reservation");
                $this->assertSame(10, $transit, "target {$key}: replenishment moved 10");
                $this->assertSame(10, $sub, "target {$key}: Sub received 10");
            }

            $this->assertSame('dibatalkan', Order::withoutGlobalScopes()->find($f['order']->id)->status);
            $this->assertSame('executed', SubStockRequest::withoutGlobalScopes()->find($f['request']->id)->status);

            // No duplicate movement: exactly one transfer (4 movements) and one release per item.
            $this->assertSame(4, \App\Models\StockMovement::withoutGlobalScopes()->whereIn('type', ['transfer_in', 'transfer_out'])->where('agent_id', $f['agent']->id)->count());
            $this->assertSame(2, \App\Models\StockMovement::withoutGlobalScopes()->where('type', 'release')->where('agent_id', $f['agent']->id)->count());

            fwrite(STDOUT, 'CANCELLATION_REPLENISH_RACE '.json_encode(['iteration' => $iteration, 'cancel' => $report['a']['outcome'], 'replenish' => $report['b']['outcome']]).PHP_EOL);
        }
    }

    private function deadlockCount(array $report): int
    {
        return collect([$report['a'], $report['b']])->filter(function ($actor) {
            $sqlState = (string) ($actor['sql_state'] ?? '');
            $message = (string) ($actor['message'] ?? '');

            return $sqlState === '40001' || stripos($message, 'deadlock') !== false || stripos($message, 'lock wait timeout') !== false;
        })->count();
    }
}
