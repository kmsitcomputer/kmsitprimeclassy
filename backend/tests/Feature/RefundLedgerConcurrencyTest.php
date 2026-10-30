<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderFinancialSnapshot;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\RefundAttempt;
use App\Models\RefundObligation;
use App\Models\RefundPayout;
use App\Models\ShippingConfiguration;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\Order\OrderFulfillmentService;
use App\Services\Refund\OrderFinancialSnapshotService;
use App\Services\Refund\RefundLedgerService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\Support\ConcurrencyHarness;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

/**
 * TRUE two-connection concurrency (separate PHP processes, separate MySQL connections):
 * dispatch/snapshot, attempt reservation, duplicate payout finalisation and deferred
 * materialization. No sleeps, no mocks, deterministic assertions on the invariant only.
 */
class RefundLedgerConcurrencyTest extends TestCase
{
    use HasTestRegion;
    use RestoresIsolatedTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_concurrent_dispatch_creates_exactly_one_snapshot(): void
    {
        $f = $this->fixture();

        $report = (new ConcurrencyHarness)->runServiceRace(
            ['op' => 'order-dispatch', 'actor_id' => $f['admin']->id, 'subject_id' => $f['order']->id],
            ['op' => 'order-dispatch', 'actor_id' => $f['admin']->id, 'subject_id' => $f['order']->id],
        );

        $this->assertTrue($report['different_connections']);
        $this->assertTrue($report['true_overlap']);
        $this->assertNoDeadlock($report);

        $this->assertSame(1, OrderFinancialSnapshot::query()->where('order_id', $f['order']->id)->count());
        $snapshot = OrderFinancialSnapshot::query()->where('order_id', $f['order']->id)->firstOrFail();
        $this->assertSame(3, $snapshot->units()->count());
        app(OrderFinancialSnapshotService::class)->validateIntegrity($snapshot);

        $checksums = collect([$report['a']['checksum'] ?? null, $report['b']['checksum'] ?? null])->filter()->unique();
        $this->assertLessThanOrEqual(1, $checksums->count());
    }

    public function test_concurrent_attempts_never_exceed_the_obligation_balance(): void
    {
        $f = $this->fixture();
        $this->setPaid($f['order'], 300000);
        app(OrderFulfillmentService::class)->adjustItemQuantity($f['item']->fresh(), 2, $f['admin'], 'race-base');

        $obligation = RefundObligation::query()
            ->where('order_id', $f['order']->id)
            ->where('source_type', 'fulfillment_shortfall')
            ->firstOrFail();
        $this->assertSame(10000000, (int) round((float) $obligation->amount * 100));

        $report = (new ConcurrencyHarness)->runServiceRace(
            ['op' => 'refund-attempt-create', 'actor_id' => $f['admin']->id, 'subject_id' => $obligation->id,
             'extra' => ['amount_cents' => 6000000, 'key' => 'race-a']],
            ['op' => 'refund-attempt-create', 'actor_id' => $f['admin']->id, 'subject_id' => $obligation->id,
             'extra' => ['amount_cents' => 6000000, 'key' => 'race-b']],
        );

        $this->assertTrue($report['different_connections']);
        $this->assertTrue($report['true_overlap']);
        $this->assertNoDeadlock($report);

        $winners = collect(['a', 'b'])->filter(fn ($s) => ($report[$s]['outcome'] ?? null) === 'success');
        $this->assertCount(1, $winners, 'exactly one 6.000.000 reservation fits the 10.000.000 obligation');

        $reserved = (int) round((float) RefundAttempt::query()
            ->where('obligation_id', $obligation->id)
            ->whereIn('status', RefundAttempt::IN_FLIGHT_STATUSES)
            ->sum('amount') * 100);
        $paid = (int) round((float) RefundPayout::query()->where('obligation_id', $obligation->id)->sum('amount') * 100);

        $this->assertLessThanOrEqual(10000000, $reserved + $paid);
        $this->assertGreaterThanOrEqual(0, 10000000 - $reserved - $paid);
    }

    public function test_duplicate_payout_finalisation_writes_one_payout_once(): void
    {
        $f = $this->fixture();
        $this->setPaid($f['order'], 300000);
        app(OrderFulfillmentService::class)->adjustItemQuantity($f['item']->fresh(), 2, $f['admin'], 'race-payout');

        $obligation = RefundObligation::query()
            ->where('order_id', $f['order']->id)
            ->where('source_type', 'fulfillment_shortfall')
            ->firstOrFail();
        $ledger = app(RefundLedgerService::class);
        $attempt = $ledger->createAttempt($f['order']->fresh(), $obligation, [
            'amount_cents' => 10000000, 'method' => 'bank_transfer', 'idempotency_key' => 'race-payout-1',
        ], $f['admin']);
        $ledger->recordInitiation($attempt, $f['admin'], 'RACE-TRX');

        $report = (new ConcurrencyHarness)->runServiceRace(
            ['op' => 'refund-attempt-complete', 'actor_id' => $f['admin']->id, 'subject_id' => $attempt->id],
            ['op' => 'refund-attempt-complete', 'actor_id' => $f['admin']->id, 'subject_id' => $attempt->id],
        );

        $this->assertTrue($report['different_connections']);
        $this->assertTrue($report['true_overlap']);
        $this->assertNoDeadlock($report);

        $this->assertSame(1, RefundPayout::query()->where('obligation_id', $obligation->id)->count());
        $this->assertSame(20000000, (int) round((float) $f['order']->fresh()->paid_amount * 100));

        $payoutIds = collect([$report['a']['payout_id'] ?? null, $report['b']['payout_id'] ?? null])->filter()->unique();
        $this->assertCount(1, $payoutIds, 'both finalisations observe the same payout');
        $this->assertSame('completed', $obligation->fresh()->status);
    }

    public function test_concurrent_deferred_materialisation_consumes_the_cap_once(): void
    {
        $f = $this->fixture();
        $this->setPaid($f['order'], 50000);
        app(OrderFulfillmentService::class)->adjustItemQuantity($f['item']->fresh(), 2, $f['admin'], 'race-deferred');

        $origin = RefundObligation::query()
            ->where('order_id', $f['order']->id)
            ->where('source_type', 'fulfillment_shortfall')
            ->firstOrFail();
        $this->assertSame(10000000, (int) round((float) $origin->deferred_remaining * 100));

        // P = 250000, B = 200000 -> C = 50000 (5.000.000 cents).
        $this->setPaid($f['order']->fresh(), 250000);

        $report = (new ConcurrencyHarness)->runServiceRace(
            ['op' => 'refund-materialize', 'actor_id' => $f['agen']->id, 'subject_id' => $origin->id,
             'extra' => ['amount_cents' => 5000000, 'key' => 'race-mat-a']],
            ['op' => 'refund-materialize', 'actor_id' => $f['agen']->id, 'subject_id' => $origin->id,
             'extra' => ['amount_cents' => 5000000, 'key' => 'race-mat-b']],
        );

        $this->assertTrue($report['different_connections']);
        $this->assertTrue($report['true_overlap']);
        $this->assertNoDeadlock($report);

        $children = RefundObligation::query()->where('parent_obligation_id', $origin->id)->count();
        $this->assertSame(1, $children, 'the 5.000.000 cap can only be consumed once');
        $this->assertSame(5000000, (int) round((float) $origin->fresh()->deferred_remaining * 100));

        $winners = collect(['a', 'b'])->filter(fn ($s) => ($report[$s]['outcome'] ?? null) === 'success');
        $this->assertCount(1, $winners);
    }

    private function assertNoDeadlock(array $report): void
    {
        foreach (['a', 'b'] as $side) {
            $payload = $report[$side] ?? [];
            $message = strtolower((string) ($payload['message'] ?? ''));
            $this->assertStringNotContainsString('deadlock', $message);
            $this->assertStringNotContainsString('lock wait timeout', $message);
            $this->assertNotSame(1213, (int) ($payload['error_code'] ?? 0));
            $this->assertNotSame(1205, (int) ($payload['error_code'] ?? 0));
        }
    }

    private function fixture(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Ledger Race Branch', 'address' => 'Test', 'latitude' => -6.2, 'longitude' => 106.8]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 0, 'free_shipping_enabled' => true, 'is_active' => true]);

        $product = Product::create(['sku' => 'RC-'.Str::uuid(), 'name' => 'Ledger Race Cake', 'slug' => 'rc-'.uniqid(), 'has_variations' => false, 'base_price' => 100000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 30]);

        $orderId = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $product->id, 'quantity' => 3]],
                'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated()->json('data.id');

        $order = Order::withoutGlobalScopes()->findOrFail($orderId);
        $item = OrderItem::query()->where('order_id', $orderId)->firstOrFail();

        return compact('agen', 'admin', 'konsumen', 'product', 'order', 'item');
    }

    private function setPaid(Order $order, int $amount): void
    {
        $order->update([
            'paid_amount' => $amount,
            'gross_received_amount' => $amount,
            'remaining_amount' => 0,
            'payment_status' => $amount > 0 ? 'paid' : 'unpaid',
        ]);
    }
}
