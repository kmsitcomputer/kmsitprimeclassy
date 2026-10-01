<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Models\StockRequestProposal;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Services\Stock\StockRequestProposalService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\Support\ConcurrencyHarness;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

/**
 * Package C — PRODUCTION UAT round-2 remediation concurrency suite (F01 + F05).
 *
 *  F01 — a concurrent quantity adjustment must never corrupt the Stock Request demand counters
 *        (requested/fulfilled/remaining) read by an approval. The approval is forced, DETERMINISTICALLY,
 *        to reach the exact "writer commits between my snapshot read and my mutation" interleaving.
 *  F05 — regroup / reschedule / split / quantity adjustment / courier lifecycle are normalised to one
 *        canonical Order-first lock order; real two-connection races must never deadlock (1213).
 *
 * Real separate PHP processes + MySQL connections (ConcurrencyHarness / .phpunit-concurrency-actor.php),
 * dedicated database rebuilt between tests (RestoresIsolatedTestDatabase).
 */
class PackageCShipmentConcurrencyTest extends TestCase
{
    use HasTestRegion;
    use RestoresIsolatedTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    /** @return array{agen:User, admin:User, gudang:User, konsumen:User, product:Product, order:Order, item:OrderItem, request:StockRequest, requestItem:StockRequestItem} */
    private function fixture(int $quantity = 10, int $stock = 50): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Race', 'address' => 'x', 'latitude' => -6.2, 'longitude' => 106.8166]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);

        $product = $this->makeProduct($agen, 'Race', $quantity, $stock);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);
        $response->assertCreated();
        $order = Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();
        $request = StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->firstOrFail();
        $requestItem = StockRequestItem::where('stock_request_id', $request->id)->where('order_item_id', $item->id)->firstOrFail();

        return compact('agen', 'admin', 'gudang', 'konsumen', 'product', 'order', 'item', 'request', 'requestItem');
    }

    private function makeProduct(User $agen, string $name, int $qty, int $stock): Product
    {
        $product = Product::create(['sku' => 'CRC-'.Str::uuid(), 'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(), 'has_variations' => false, 'base_price' => 10000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => $stock, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => $stock]);
        WarehouseSetting::firstOrCreate(['agent_id' => $agen->id], ['factory_plan_enabled' => false]);

        return $product;
    }

    private function proposalFor(array $f, int $qty): StockRequestProposal
    {
        return app(StockRequestProposalService::class)->propose($f['gudang'], $f['request'], [['item_id' => $f['requestItem']->id, 'quantity' => $qty]]);
    }

    private function counted(StockRequestItem $item): array
    {
        $item->refresh();

        return [$item->requested_qty, $item->fulfilled_qty, $item->remaining_qty];
    }

    // ===================== F01 — deterministic forced interleaving =====================

    public function test_approval_never_corrupts_demand_against_a_concurrent_quantity_increase(): void
    {
        $f = $this->fixture(quantity: 10);
        $proposal = $this->proposalFor($f, 6);
        $proposalItem = $proposal->items->firstOrFail();
        $this->assertSame([10, 0, 10], $this->counted($f['requestItem']));

        $result = $this->heldLocksThen(
            [$f['order'], $f['request'], $f['requestItem']],
            fn () => $f['requestItem']->update(['requested_qty' => 12, 'remaining_qty' => 12]), // concurrent +2, about to commit
            ['op' => 'proposal-item-approve', 'actor_id' => $f['admin']->id, 'subject_id' => $proposal->id, 'extra' => ['item_id' => $proposalItem->id]],
        );

        $this->assertTrue($result['blocked'], 'the approval must provably block on a held row lock');
        $this->assertSame('success', $result['actor']['outcome'] ?? null, json_encode($result['actor']));

        // requested was updated to 12 BEFORE the approval committed -> the approval must have re-read it.
        $this->assertSame([12, 6, 6], $this->counted($f['requestItem']));
        $this->assertSame(6, $f['requestItem']->requested_qty - $f['requestItem']->fulfilled_qty);
        $this->assertSame(1, (int) $f['request']->fresh()->items()->where('product_id', $f['product']->id)->count());
        $this->assertSame('partial', $f['request']->fresh()->status);
    }

    public function test_approval_never_corrupts_demand_against_a_concurrent_quantity_reduction(): void
    {
        $f = $this->fixture(quantity: 10);
        $proposal = $this->proposalFor($f, 6);
        $proposalItem = $proposal->items->firstOrFail();

        $result = $this->heldLocksThen(
            [$f['order'], $f['request'], $f['requestItem']],
            fn () => $f['requestItem']->update(['requested_qty' => 6, 'remaining_qty' => 6]), // concurrent reduction, about to commit
            ['op' => 'proposal-item-approve', 'actor_id' => $f['admin']->id, 'subject_id' => $proposal->id, 'extra' => ['item_id' => $proposalItem->id]],
        );

        $this->assertTrue($result['blocked']);
        $this->assertSame('success', $result['actor']['outcome'] ?? null, json_encode($result['actor']));

        // requested=6 committed before approval; approval fulfils 6 -> remaining must be 0, never a stale -2/4.
        $this->assertSame([6, 6, 0], $this->counted($f['requestItem']));
        $this->assertSame('fulfilled', $f['request']->fresh()->status);
    }

    public function test_reduction_below_fulfilled_quantity_is_rejected_even_while_an_approval_is_committed(): void
    {
        $f = $this->fixture(quantity: 10);

        $result = $this->heldLocksThen(
            [$f['order'], $f['request'], $f['requestItem']],
            fn () => $f['requestItem']->update(['fulfilled_qty' => 6, 'remaining_qty' => 4]), // a committed approval leaves 4 unfulfilled
            ['op' => 'fulfillment-reduce', 'actor_id' => $f['admin']->id, 'subject_id' => $f['item']->id, 'extra' => ['quantity' => 3]],
        );

        $this->assertNotSame('success', $result['actor']['outcome'] ?? null, 'reducing below the fulfilled quantity must be rejected');
        // The committed approval stands untouched; no partial mutation.
        $this->assertSame([10, 6, 4], $this->counted($f['requestItem']));
        $this->assertSame(10, (int) $f['item']->fresh()->fulfilled_quantity);
    }

    /** Race the REAL approval against a REAL quantity change (simultaneous start); the invariant must hold. */
    public function test_real_approval_and_increase_race_conserves_the_demand_invariant(): void
    {
        for ($iteration = 1; $iteration <= 3; $iteration++) {
            $f = $this->fixture(quantity: 10, stock: 100);
            $proposal = $this->proposalFor($f, 6);
            $proposalItem = $proposal->items->firstOrFail();

            $report = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'proposal-item-approve', 'actor_id' => $f['admin']->id, 'subject_id' => $proposal->id, 'extra' => ['item_id' => $proposalItem->id]],
                ['op' => 'fulfillment-increase', 'actor_id' => $f['admin']->id, 'subject_id' => $f['item']->id, 'extra' => ['quantity' => 12]],
            );

            $this->assertTrue($report['true_overlap']);
            foreach (['a', 'b'] as $side) {
                $this->assertNotSame(1213, $report[$side]['error_code'] ?? null, 'no deadlock');
                $this->assertSame('success', $report[$side]['outcome'], json_encode($report[$side]));
            }
            [$requested, $fulfilled, $remaining] = $this->counted($f['requestItem']);
            $this->assertSame(12, $requested);
            $this->assertSame(6, $fulfilled);
            $this->assertSame($requested - $fulfilled, $remaining, 'remaining == requested - fulfilled in every interleaving');
        }
    }

    public function test_real_approval_and_reduction_race_conserves_the_demand_invariant(): void
    {
        for ($iteration = 1; $iteration <= 3; $iteration++) {
            $f = $this->fixture(quantity: 10, stock: 100);
            $proposal = $this->proposalFor($f, 6);
            $proposalItem = $proposal->items->firstOrFail();

            $report = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'proposal-item-approve', 'actor_id' => $f['admin']->id, 'subject_id' => $proposal->id, 'extra' => ['item_id' => $proposalItem->id]],
                ['op' => 'fulfillment-reduce', 'actor_id' => $f['admin']->id, 'subject_id' => $f['item']->id, 'extra' => ['quantity' => 6]],
            );

            $this->assertTrue($report['true_overlap']);
            foreach (['a', 'b'] as $side) {
                $this->assertNotSame(1213, $report[$side]['error_code'] ?? null, 'no deadlock');
                $this->assertSame('success', $report[$side]['outcome'], json_encode($report[$side]));
            }
            [$requested, $fulfilled, $remaining] = $this->counted($f['requestItem']);
            $this->assertSame(6, $requested);
            $this->assertSame(6, $fulfilled);
            $this->assertSame(0, $remaining, 'remaining == requested - fulfilled in every interleaving');
        }
    }

    // ===================== F05 — canonical Order-first races =====================

    /** Order with two items sharing a date, split across two mutable shipments (legacy per-item shape). */
    private function groupedFixture(): array
    {
        $f = $this->fixture(quantity: 3, stock: 100);
        $second = $this->makeProduct($f['agen'], 'Race2', 3, 100);

        // Add a second line on the same date through the real SC-03 path.
        app(\App\Services\Order\OrderLineAdditionService::class)->addLine(
            Order::withoutGlobalScopes()->findOrFail($f['order']->id),
            ['product_id' => $second->id, 'quantity' => 2],
            $f['admin'], null, 'setup', 'cod', (string) Str::uuid(),
        );

        // Split the two lines onto two mutable shipments (simulating legacy per-item rows).
        $items = OrderItem::where('order_id', $f['order']->id)->orderBy('id')->get();
        $base = Shipment::where('order_id', $f['order']->id)->orderBy('id')->firstOrFail();
        $shell = $base->replicate(['shipping_fee_snapshot', 'rate_per_km']);
        $shell->save();
        $items[1]->update(['shipment_id' => $shell->id]);

        return array_merge($f, ['second' => $second, 'items' => $items, 'shipments' => [$base, $shell]]);
    }

    private function assertNoDeadlock(array $report): void
    {
        $this->assertTrue($report['true_overlap']);
        foreach (['a', 'b'] as $side) {
            $this->assertNotSame(1213, $report[$side]['error_code'] ?? null, 'no deadlock: '.json_encode($report[$side]));
        }
    }

    public function test_real_regroup_and_reschedule_race_does_not_deadlock(): void
    {
        for ($iteration = 1; $iteration <= 3; $iteration++) {
            $f = $this->groupedFixture();
            $report = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'shipment-regroup', 'actor_id' => $f['admin']->id, 'subject_id' => $f['order']->id],
                ['op' => 'reschedule', 'actor_id' => $f['admin']->id, 'subject_id' => $f['items'][0]->id, 'extra' => ['date' => now()->addDays(9)->toDateString()]],
            );
            $this->assertNoDeadlock($report);
            // No item is ever lost; the order still has every line.
            $this->assertSame(2, OrderItem::where('order_id', $f['order']->id)->count());
            $this->assertGreaterThanOrEqual(1, Shipment::where('order_id', $f['order']->id)->count());
        }
    }

    public function test_real_regroup_and_split_race_does_not_deadlock(): void
    {
        for ($iteration = 1; $iteration <= 3; $iteration++) {
            $f = $this->groupedFixture();
            $report = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'shipment-regroup', 'actor_id' => $f['admin']->id, 'subject_id' => $f['order']->id],
                ['op' => 'reschedule', 'actor_id' => $f['admin']->id, 'subject_id' => $f['items'][1]->id, 'extra' => ['date' => now()->addDays(9)->toDateString(), 'quantity' => 1]],
            );
            $this->assertNoDeadlock($report);
            $this->assertGreaterThanOrEqual(2, OrderItem::where('order_id', $f['order']->id)->count());
        }
    }

    public function test_real_regroup_and_quantity_adjustment_race_does_not_deadlock(): void
    {
        for ($iteration = 1; $iteration <= 3; $iteration++) {
            $f = $this->groupedFixture();
            $report = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'shipment-regroup', 'actor_id' => $f['admin']->id, 'subject_id' => $f['order']->id],
                ['op' => 'fulfillment-increase', 'actor_id' => $f['admin']->id, 'subject_id' => $f['items'][1]->id, 'extra' => ['quantity' => 3]],
            );
            $this->assertNoDeadlock($report);
            [$requested, $fulfilled, $remaining] = $this->counted($f['requestItem']);
            $this->assertSame($requested - $fulfilled, $remaining, 'demand invariant holds');
        }
    }

    public function test_real_courier_lifecycle_and_reschedule_race_does_not_deadlock(): void
    {
        for ($iteration = 1; $iteration <= 3; $iteration++) {
            $f = $this->groupedFixture();
            $shipment = $f['shipments'][0];
            $report = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'courier-ship', 'actor_id' => $f['admin']->id, 'subject_id' => $shipment->id, 'extra' => ['status' => 'dikirim']],
                ['op' => 'reschedule', 'actor_id' => $f['admin']->id, 'subject_id' => $f['items'][0]->id, 'extra' => ['date' => now()->addDays(9)->toDateString()]],
            );
            $this->assertNoDeadlock($report);
            $this->assertSame(2, OrderItem::where('order_id', $f['order']->id)->count());
        }
    }

    // ===================== deterministic held-lock orchestration =====================

    /**
     * Lock the given rows on THIS process's connection inside one transaction, apply $mutate, start the
     * real actor, wait until it is provably blocked, then commit. If anything fails the transaction is
     * rolled back so the fixture database is never left locked.
     *
     * @param  array<int, \Illuminate\Database\Eloquent\Model>  $locks
     * @return array{actor: array, blocked: bool}
     */
    private function heldLocksThen(array $locks, callable $mutate, array $side): array
    {
        DB::beginTransaction();
        try {
            foreach ($locks as $model) {
                $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->first();
            }
            $mutate();

            return (new ConcurrencyHarness)->runServiceActorAgainstHeldLocks($side, fn () => DB::commit());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }
}
