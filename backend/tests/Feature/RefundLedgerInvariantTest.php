<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderFinancialSnapshot;
use App\Models\OrderFinancialSnapshotUnit;
use App\Models\OrderItem;
use App\Models\OrderSalesReduction;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\RefundAttempt;
use App\Models\RefundObligation;
use App\Models\RefundPayout;
use App\Models\ShippingConfiguration;
use App\Models\User;
use App\Models\Voucher;
use App\Models\WarehouseStock;
use App\Services\Order\OrderFulfillmentService;
use App\Services\Order\OrderLineAdditionService;
use App\Services\Refund\OrderFinancialSnapshotService;
use App\Services\Refund\RefundLedgerService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * Targeted refund-ledger regressions (design §9): cross-line voucher conservation (rows 27–29),
 * the forbidden row-26 reallocation, consumption/reservation exhaustion, deferred caps and
 * replay ordering, needs_verification exits and reinstatement bounds.
 *
 * Real database, real services — no mocked money.
 */
class RefundLedgerInvariantTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_cross_line_returns_conserve_voucher_rows_27_to_29(): void
    {
        [$order, $big, $small] = $this->crossLineOrder();

        $snapshots = app(OrderFinancialSnapshotService::class);
        $snapshot = $snapshots->ensure($order, OrderFinancialSnapshotService::TRIGGER_RETURN_APPROVAL);
        $snapshots->validateIntegrity($snapshot);

        $this->assertSame(4, $snapshot->units()->count());
        $this->assertSame(1875000, $this->unitDiscountCents($snapshot, $big->id));
        $this->assertSame(375000, $this->unitDiscountCents($snapshot, $small->id));
        $this->assertSame(3000000, (int) round((float) $snapshot->units()->sum('allocated_discount') * 100));

        // Row 28: L2 returns 2 units -> 2 x (20000 - 3750) = 32500.
        $l2 = $snapshots->consumeUnits($snapshot, $small->id, 2, 'return_approval', 1);
        $this->assertSame(3250000, $l2['reduction_cents']);
        $this->assertSame(1, $snapshots->availableUnits($snapshot, $small->id));

        // Row 29: a later L1 return of 1 unit -> 100000 - 18750 = 81250.
        $l1 = $snapshots->consumeUnits($snapshot, $big->id, 1, 'return_approval', 2);
        $this->assertSame(8125000, $l1['reduction_cents']);

        $consumedDiscount = (int) round((float) OrderFinancialSnapshotUnit::query()
            ->where('snapshot_id', $snapshot->id)
            ->whereNotNull('consumed_at')
            ->sum('allocated_discount') * 100);
        $remainingDiscount = 3000000 - $consumedDiscount;

        $this->assertSame(2625000, $consumedDiscount);
        $this->assertSame(375000, $remainingDiscount, 'voucher conservation 2.625.000 + 375.000 = 3.000.000');
        $this->assertSame(11375000, $l1['reduction_cents'] + $l2['reduction_cents']);
        $this->assertSame(0, $snapshots->availableUnits($snapshot, $big->id));
        $this->assertSame(1, $snapshots->availableUnits($snapshot, $small->id));

        // Consuming the same unit twice is impossible.
        try {
            $snapshots->consumeUnits($snapshot, $big->id, 1, 'return_approval', 3);
            $this->fail('A consumed unit must never be consumed again.');
        } catch (ApiException $e) {
            $this->assertSame('snapshot_units_exhausted', $e->errors()['code'] ?? null);
        }

        $snapshots->validateIntegrity($snapshot->fresh());
    }

    public function test_row_26_forbidden_reallocation_is_unreachable_after_snapshot(): void
    {
        [$order, $big, $small] = $this->crossLineOrder();
        $snapshots = app(OrderFinancialSnapshotService::class);
        $snapshot = $snapshots->ensure($order, OrderFinancialSnapshotService::TRIGGER_RETURN_APPROVAL);
        $before = $this->allocation($snapshot);

        // Row 26's defect was a post-freeze line change that let the voucher be re-allocated
        // across the new line set (Σ bases 34.33 != 60). Both public mutation paths must refuse.
        try {
            app(OrderFulfillmentService::class)->adjustItemQuantity($big->fresh(), 2, $this->owner, 'row26');
            $this->fail('Row 26 mutation must be refused.');
        } catch (ApiException $e) {
            $this->assertSame('order_financial_snapshot_frozen', $e->errors()['code'] ?? null);
        }

        try {
            app(OrderLineAdditionService::class)->addLine(
                $order->fresh(),
                ['product_id' => $big->product_id, 'quantity' => 1],
                $this->admin,
                null,
                'row26',
                'cod',
                (string) Str::uuid(),
            );
            $this->fail('Row 26 add-line must be refused.');
        } catch (ApiException $e) {
            $this->assertSame('order_financial_snapshot_frozen', $e->errors()['code'] ?? null);
        }

        // Even a stale direct quantity write cannot make ensure() re-allocate.
        $beforeQty = (int) $small->fulfilled_quantity;
        $small->update(['fulfilled_quantity' => $beforeQty + 1]);
        $again = $snapshots->ensure($order->fresh(), OrderFinancialSnapshotService::TRIGGER_RETURN_APPROVAL);

        $this->assertSame($snapshot->id, $again->id);
        $this->assertSame($before, $this->allocation($snapshot->fresh()));
        $this->assertSame(3000000, (int) round((float) $snapshot->units()->sum('allocated_discount') * 100));

        // Restore the fixture so the integrity check matches the frozen truth.
        $small->update(['fulfilled_quantity' => $beforeQty]);
        $snapshots->validateIntegrity($snapshot->fresh());
    }

    public function test_consumption_and_reservation_exhaustion_never_go_negative(): void
    {
        $order = $this->singleLineOrder();
        $this->setPaid($order, 300000);
        app(OrderFulfillmentService::class)->adjustItemQuantity($this->item($order), 2, $this->admin, 'exhaustion');

        $obligation = RefundObligation::query()->where('order_id', $order->id)->where('source_type', 'fulfillment_shortfall')->firstOrFail();
        $ledger = app(RefundLedgerService::class);

        $first = $ledger->createAttempt($order->fresh(), $obligation, [
            'amount_cents' => 6000000, 'method' => 'cash', 'idempotency_key' => 'ex-1',
        ], $this->admin);
        $ledger->recordInitiation($first, $this->admin);

        try {
            $ledger->createAttempt($order->fresh(), $obligation, [
                'amount_cents' => 6000000, 'method' => 'cash', 'idempotency_key' => 'ex-2',
            ], $this->admin);
            $this->fail('A second reservation beyond the remaining balance must be refused.');
        } catch (ApiException $e) {
            $this->assertSame('attempt_exceeds_remaining', $e->errors()['code'] ?? null);
        }

        // A duplicate key is a replay ONLY for the same amount/method (RF-01). Reusing it with a
        // different amount is a conflict and must never silently return the old artifact.
        $replay = $ledger->createAttempt($order->fresh(), $obligation, [
            'amount_cents' => 6000000, 'method' => 'cash', 'idempotency_key' => 'ex-1',
        ], $this->admin);
        $this->assertSame($first->id, $replay->id);

        try {
            $ledger->createAttempt($order->fresh(), $obligation, [
                'amount_cents' => 1000000, 'method' => 'cash', 'idempotency_key' => 'ex-1',
            ], $this->admin);
            $this->fail('The same idempotency key with a different amount must conflict.');
        } catch (ApiException $e) {
            $this->assertSame('idempotency_key_conflict', $e->errors()['code'] ?? null);
        }

        $ledger->completeAttempt($first->fresh(), $this->admin);

        $this->assertSame('partially_paid', $obligation->fresh()->status);
        $rest = $ledger->createAttempt($order->fresh(), $obligation, [
            'amount_cents' => 4000000, 'method' => 'cash', 'idempotency_key' => 'ex-3',
        ], $this->admin);
        $ledger->recordInitiation($rest, $this->admin);
        $ledger->completeAttempt($rest->fresh(), $this->admin);

        try {
            $ledger->createAttempt($order->fresh(), $obligation, [
                'amount_cents' => 1, 'method' => 'cash', 'idempotency_key' => 'ex-4',
            ], $this->admin);
            $this->fail('A settled obligation must not accept another attempt.');
        } catch (ApiException $e) {
            $this->assertSame('obligation_not_payable', $e->errors()['code'] ?? null);
        }

        $this->assertSame(10000000, (int) round((float) RefundPayout::query()->where('obligation_id', $obligation->id)->sum('amount') * 100));
        $this->assertSame(20000000, (int) round((float) $order->fresh()->paid_amount * 100));
        $this->assertSame(0, RefundObligation::query()->where('order_id', $order->id)->where('status', 'partially_paid')->count());
    }

    public function test_deferred_cap_replay_supersede_and_owner_gate(): void
    {
        $order = $this->singleLineOrder();
        $this->setPaid($order, 50000);

        // Fully deferred: event 100000, C = 0 -> amount 0, deferred 100000, no attempt allowed.
        app(OrderFulfillmentService::class)->adjustItemQuantity($this->item($order), 2, $this->admin, 'deferred');
        $origin = RefundObligation::query()->where('order_id', $order->id)->where('source_type', 'fulfillment_shortfall')->firstOrFail();
        $this->assertSame(0, (int) round((float) $origin->amount * 100));
        $this->assertSame(10000000, (int) round((float) $origin->deferred_remaining * 100));

        $keuangan = User::factory()->keuangan()->create(['agent_id' => $this->owner->agent_id]);
        $ledger = app(RefundLedgerService::class);

        try {
            $ledger->createAttempt($order->fresh(), $origin, [
                'amount_cents' => 1, 'method' => 'cash', 'idempotency_key' => 'zero-1',
            ], $this->admin);
            $this->fail('A zero-amount container cannot receive an attempt.');
        } catch (ApiException $e) {
            $this->assertSame('zero_amount_obligation_no_attempt', $e->errors()['code'] ?? null);
        }

        // Cash arrives: P = 250000, B = 200000 -> C = 50000.
        $this->setPaid($order->fresh(), 250000);

        // Finance may only request; execution needs the Owner.
        $ledger->requestDeferredMaterialization($origin, 5000000, $keuangan, 'req-1');
        try {
            $ledger->materializeDeferred($origin, 5000000, $keuangan, 'mat-auth');
            $this->fail('Finance must not execute a deferred materialization.');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->status());
        }

        try {
            $ledger->materializeDeferred($origin, 6000000, $this->owner, 'mat-over');
            $this->fail('Materialization above the C cap must be refused.');
        } catch (ApiException $e) {
            $this->assertSame('deferred_materialization_exceeds_cap', $e->errors()['code'] ?? null);
        }

        $child = $ledger->materializeDeferred($origin, 5000000, $this->owner, 'mat-1');
        $this->assertSame(5000000, (int) round((float) $child->amount * 100));
        $this->assertSame(5000000, (int) round((float) $origin->fresh()->deferred_remaining * 100));

        // Replay returns the same child; conflicting parameters are a 409.
        $this->assertSame($child->id, $ledger->materializeDeferred($origin->fresh(), 5000000, $this->owner, 'mat-1')->id);
        try {
            $ledger->materializeDeferred($origin->fresh(), 1000000, $this->owner, 'mat-1');
            $this->fail('Conflicting replay must be refused.');
        } catch (ApiException $e) {
            $this->assertSame('idempotency_key_conflict', $e->errors()['code'] ?? null);
        }

        $this->assertSame(1, RefundObligation::query()->where('parent_obligation_id', $origin->id)->count());

        // Supersede zeroes the remainder; new materialization is exhausted, replay still works.
        $ledger->supersedeDeferred($origin->fresh(), $this->owner, 'cancel');
        $this->assertSame(0, (int) round((float) $origin->fresh()->deferred_remaining * 100));
        $this->assertSame($child->id, $ledger->materializeDeferred($origin->fresh(), 5000000, $this->owner, 'mat-1')->id);

        try {
            $ledger->materializeDeferred($origin->fresh(), 1000000, $this->owner, 'mat-2');
            $this->fail('Superseded deferred entitlement must not materialize again.');
        } catch (ApiException $e) {
            $this->assertSame('deferred_exhausted', $e->errors()['code'] ?? null);
        }
    }

    public function test_i17_no_new_obligation_after_cancellation_financial_finalization(): void
    {
        $order = $this->singleLineOrder();
        $ledger = app(RefundLedgerService::class);

        $existing = $ledger->createForwardObligation($order, [
            'source_type' => 'fulfillment_shortfall',
            'source_id' => 1,
            'obligation_key' => 'i17:existing',
        ], 50000, $this->admin, true);

        $order->update(['cancellation_financial_status' => 'finalized']);

        // A replay of an already-created obligation is not a new obligation.
        $replayed = $ledger->createForwardObligation($order->fresh(), [
            'source_type' => 'fulfillment_shortfall',
            'source_id' => 1,
            'obligation_key' => 'i17:existing',
        ], 50000, $this->admin, true);
        $this->assertSame($existing->id, $replayed->id);

        foreach (['forward', 'verification'] as $kind) {
            try {
                if ($kind === 'forward') {
                    $ledger->createForwardObligation($order->fresh(), [
                        'source_type' => 'return_stage',
                        'source_id' => 2,
                        'obligation_key' => 'i17:new-forward',
                    ], 50000, $this->admin, true);
                } else {
                    $ledger->createVerificationObligation($order->fresh(), [
                        'source_type' => 'return_stage',
                        'source_id' => 3,
                        'obligation_key' => 'i17:new-verification',
                    ], 50000, $this->admin);
                }

                $this->fail("A post-finalization {$kind} obligation must be refused.");
            } catch (ApiException $e) {
                $this->assertSame('order_financially_finalized', $e->errors()['code'] ?? null);
            }
        }

        $this->assertSame(1, RefundObligation::query()->where('order_id', $order->id)->count());
    }

    public function test_i17_owner_approved_deferred_materialization_is_the_only_exception(): void
    {
        $order = $this->singleLineOrder();
        $this->setPaid($order, 50000);
        app(OrderFulfillmentService::class)->adjustItemQuantity($this->item($order), 2, $this->admin, 'i17-deferred');

        $origin = RefundObligation::query()
            ->where('order_id', $order->id)
            ->where('source_type', 'fulfillment_shortfall')
            ->firstOrFail();
        $this->assertSame(0, (int) round((float) $origin->amount * 100));

        // Cash arrives; the order is (separately) marked financially finalized.
        $this->setPaid($order->fresh(), 250000);
        $order->update(['cancellation_financial_status' => 'finalized']);

        $ledger = app(RefundLedgerService::class);
        $child = $ledger->materializeDeferred($origin, 5000000, $this->owner, 'i17-owner-correction');

        $this->assertSame('deferred', $child->source_type);
        $this->assertSame('owner_manual_correction', $child->approval_source);
        $this->assertSame($this->owner->id, (int) $child->approved_by);
        $this->assertSame(5000000, (int) round((float) $origin->fresh()->deferred_remaining * 100));
    }

    public function test_needs_verification_exits_are_separated_by_role(): void
    {
        $order = $this->singleLineOrder();
        $ledger = app(RefundLedgerService::class);

        $obligation = $ledger->createVerificationObligation($order, [
            'source_type' => 'return_stage',
            'source_id' => 1,
            'obligation_key' => 'nv:test:1',
            'reason' => 'legacy proof missing',
        ], 2000000, $this->admin);

        $this->assertSame('needs_verification', $obligation->status);
        $this->assertSame(2000000, (int) round((float) $obligation->amount * 100), 'needs_verification is not capped by C');

        $keuangan = User::factory()->keuangan()->create(['agent_id' => $this->owner->agent_id]);

        try {
            $ledger->resolveNeedsVerification($obligation, $keuangan, 'B', 'proof', 'reject');
            $this->fail('Exit B is Owner-only.');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->status());
        }

        try {
            $ledger->resolveNeedsVerification($obligation, $keuangan, 'A', '   ', 'approve');
            $this->fail('Evidence is mandatory.');
        } catch (ApiException $e) {
            $this->assertSame('reconciliation_evidence_required', $e->errors()['code'] ?? null);
        }

        $approved = $ledger->resolveNeedsVerification($obligation->fresh(), $keuangan, 'A', 'bank statement', 'approve');
        $this->assertSame('approved', $approved->status);
        $this->assertSame($keuangan->id, (int) $approved->approved_by);

        // Replayed resolution is a no-op and does not flip the outcome.
        $this->assertSame('approved', $ledger->resolveNeedsVerification($approved->fresh(), $this->owner, 'B', 'x')->status);
    }

    public function test_reinstatement_bounds_and_owner_authorization(): void
    {
        $order = $this->singleLineOrder();
        $order->update([
            'total_amount' => 80000,
            'paid_amount' => 100000,
            'gross_received_amount' => 100000,
            'remaining_amount' => 0,
            'payment_status' => 'paid',
        ]);

        $reduction = OrderSalesReduction::create([
            'order_id' => $order->id,
            'source_type' => 'return_stage',
            'source_id' => 1,
            'amount' => 20000,
            'applied_to_order_total' => true,
            'provenance' => 'forward',
        ]);

        // Row 23: P=100, B=80, reserved 20 => R_r 20 > P-B 0 -> invalid.
        $obligation = RefundObligation::create([
            'obligation_no' => 'RO-'.Str::ulid(),
            'order_id' => $order->id,
            'source_type' => 'return_stage',
            'obligation_key' => 'reinstate-test',
            'amount' => 20000,
            'status' => 'approved',
            'provenance' => 'forward',
            'approved_at' => now(),
            'deferred_amount' => '0.00',
            'deferred_remaining' => '0.00',
        ]);

        $ledger = app(RefundLedgerService::class);
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $this->owner->agent_id]);

        try {
            $ledger->reinstateReduction($reduction, $keuangan, 'evidence');
            $this->fail('Reinstatement is Owner-only.');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->status());
        }

        try {
            $ledger->reinstateReduction($reduction, $this->owner, 'evidence');
            $this->fail('Row 23 must violate R_r <= P - B.');
        } catch (ApiException $e) {
            $this->assertSame('reinstatement_exceeds_bounds', $e->errors()['code'] ?? null);
        }
        $this->assertNull($reduction->fresh()->reinstated_at);

        // Row 24: P=120, B=80, reserved 20 => both bounds hold.
        $order->update(['paid_amount' => 120000, 'gross_received_amount' => 120000]);

        $attempt = $ledger->createAttempt($order->fresh(), $obligation, [
            'amount_cents' => 2000000, 'method' => 'cash', 'idempotency_key' => 're-inflight',
        ], $this->admin);
        $ledger->recordInitiation($attempt, $this->admin);

        try {
            $ledger->reinstateReduction($reduction->fresh(), $this->owner, 'evidence');
            $this->fail('An in-flight attempt blocks reinstatement.');
        } catch (ApiException $e) {
            $this->assertSame('reinstatement_blocked_by_inflight_attempt', $e->errors()['code'] ?? null);
        }

        $ledger->markFailed($attempt->fresh(), $this->admin, 'test', 'test cleanup');

        $valid = $ledger->reinstateReduction($reduction->fresh(), $this->owner, 'reconciliation ref 42');
        $this->assertNotNull($valid->reinstated_at);
        $this->assertSame($this->owner->id, (int) $valid->reinstated_by);
        $this->assertSame('reconciliation ref 42', $valid->reinstatement_reconciliation_ref);

        // Idempotent repeat is a no-op.
        $again = $ledger->reinstateReduction($valid->fresh(), $this->owner, 'other');
        $this->assertSame($valid->reinstated_at?->toDateTimeString(), $again->reinstated_at?->toDateTimeString());
    }

    private User $owner;
    private User $admin;

    private function bootUsers(): void
    {
        if (isset($this->owner)) {
            return;
        }

        $this->owner = User::factory()->agen()->create();
        $this->owner->update(['agent_id' => $this->owner->id]);
        $this->admin = User::factory()->admin()->create(['agent_id' => $this->owner->id]);
    }

    /** @return array{0: Order, 1: OrderItem, 2: OrderItem} */
    private function crossLineOrder(): array
    {
        $this->bootUsers();
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $this->owner->id]);
        AgentProfile::create(['user_id' => $this->owner->id, 'store_name' => 'Invariant Branch', 'address' => 'Test', 'latitude' => -6.2, 'longitude' => 106.8]);
        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 0, 'free_shipping_enabled' => true, 'is_active' => true]);

        $big = $this->product(100000, 20);
        $small = $this->product(20000, 20);

        Voucher::create([
            'agent_id' => $this->owner->id, 'code' => 'ROW27', 'name' => 'Row 27',
            'type' => 'fixed', 'value' => 30000, 'is_active' => true,
        ]);

        $orderId = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'voucher_code' => 'ROW27',
                'items' => [
                    ['product_id' => $big->id, 'quantity' => 1],
                    ['product_id' => $small->id, 'quantity' => 3],
                ],
                'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated()->json('data.id');

        $order = Order::withoutGlobalScopes()->findOrFail($orderId);
        $bigItem = OrderItem::query()->where('order_id', $orderId)->where('product_id', $big->id)->firstOrFail();
        $smallItem = OrderItem::query()->where('order_id', $orderId)->where('product_id', $small->id)->firstOrFail();

        return [$order, $bigItem, $smallItem];
    }

    private function singleLineOrder(): Order
    {
        $this->bootUsers();
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $this->owner->id]);
        AgentProfile::create(['user_id' => $this->owner->id, 'store_name' => 'Invariant Branch', 'address' => 'Test', 'latitude' => -6.2, 'longitude' => 106.8]);
        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 0, 'free_shipping_enabled' => true, 'is_active' => true]);

        $product = $this->product(100000, 20);

        $orderId = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $product->id, 'quantity' => 3]],
                'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated()->json('data.id');

        return Order::withoutGlobalScopes()->findOrFail($orderId);
    }

    private function product(int $price, int $stock): Product
    {
        $product = Product::create([
            'sku' => 'IV-'.Str::uuid(), 'name' => 'Invariant '.$price, 'slug' => 'iv-'.uniqid(),
            'has_variations' => false, 'base_price' => $price, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $this->owner->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $this->owner->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => $stock]);

        return $product;
    }

    private function item(Order $order): OrderItem
    {
        return OrderItem::query()->where('order_id', $order->id)->firstOrFail();
    }

    private function setPaid(Order $order, int $cents): void
    {
        $order->update([
            'paid_amount' => $cents,
            'gross_received_amount' => $cents,
            'remaining_amount' => 0,
            'payment_status' => $cents > 0 ? 'partially_paid' : 'unpaid',
        ]);
    }

    private function unitDiscountCents(OrderFinancialSnapshot $snapshot, int $orderItemId): int
    {
        $unit = OrderFinancialSnapshotUnit::query()
            ->where('snapshot_id', $snapshot->id)
            ->where('order_item_id', $orderItemId)
            ->orderBy('unit_index')
            ->firstOrFail();

        return (int) round((float) $unit->allocated_discount * 100);
    }

    private function allocation(OrderFinancialSnapshot $snapshot): array
    {
        return OrderFinancialSnapshotUnit::query()
            ->where('snapshot_id', $snapshot->id)
            ->orderBy('order_item_id')->orderBy('unit_index')
            ->get(['order_item_id', 'unit_index', 'unit_gross', 'allocated_discount', 'unit_net'])
            ->map(fn ($u) => [$u->order_item_id, $u->unit_index, (string) $u->unit_gross, (string) $u->allocated_discount, (string) $u->unit_net])
            ->all();
    }
}
