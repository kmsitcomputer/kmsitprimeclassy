<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderFinancialSnapshot;
use App\Models\OrderItem;
use App\Models\OrderSalesReduction;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\RefundAttempt;
use App\Models\RefundObligation;
use App\Models\RefundPayout;
use App\Models\ShippingConfiguration;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\Order\OrderFulfillmentService;
use App\Services\Order\OrderLineAdditionService;
use App\Services\Refund\CancellationFinancialService;
use App\Services\Refund\OrderFinancialSnapshotService;
use App\Services\Refund\RefundLedgerService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * Core invariants of the 360° refund ledger (design §4–§6):
 * one snapshot, integer-cent conservation, D6 freeze, cancellation deferral and
 * attempt self-exclusion. These run against the real database and the REAL
 * checkout/cancellation paths — no mocked money.
 */
class RefundLedgerTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_first_handover_freezes_one_snapshot_and_later_handovers_reuse_it(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['admin'])
            ->patchJson("/api/v1/orders/{$f['order']->id}/status", ['status' => 'dikirim'])
            ->assertOk();

        $snapshot = OrderFinancialSnapshot::query()->where('order_id', $f['order']->id)->firstOrFail();
        $this->assertSame('first_handover', $snapshot->trigger);
        $this->assertCount(3, $snapshot->units);
        $this->assertSame(
            (int) round((float) $f['order']->fresh()->subtotal_amount * 100),
            (int) round((float) $snapshot->units()->sum('unit_gross') * 100),
        );
        $this->assertSame(0, (int) round((float) $snapshot->units()->sum('allocated_discount') * 100));

        app(OrderFinancialSnapshotService::class)->validateIntegrity($snapshot);

        // Delivering does not create a second snapshot nor re-allocate anything.
        $this->actingAs($f['admin'])
            ->patchJson("/api/v1/orders/{$f['order']->id}/status", ['status' => 'terkirim'])
            ->assertOk();

        $this->assertSame(1, OrderFinancialSnapshot::query()->where('order_id', $f['order']->id)->count());
        $this->assertSame(
            $snapshot->allocation_checksum,
            OrderFinancialSnapshot::query()->where('order_id', $f['order']->id)->value('allocation_checksum'),
        );
    }

    public function test_post_snapshot_quantity_and_add_line_are_refused(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['admin'])
            ->patchJson("/api/v1/orders/{$f['order']->id}/status", ['status' => 'dikirim'])
            ->assertOk();

        try {
            app(OrderFulfillmentService::class)->adjustItemQuantity($f['item']->fresh(), 2, $f['admin'], 'late');
            $this->fail('Quantity change after the snapshot must be refused.');
        } catch (ApiException $e) {
            $this->assertSame('order_financial_snapshot_frozen', $e->errors()['code'] ?? null);
        }

        try {
            app(OrderLineAdditionService::class)->addLine(
                $f['order']->fresh(),
                ['product_id' => $f['product']->id, 'quantity' => 1],
                $f['admin'],
                null,
                'late line',
                'cod',
                (string) Str::uuid(),
            );
            $this->fail('Adding a line after the snapshot must be refused.');
        } catch (ApiException $e) {
            $this->assertSame('order_financial_snapshot_frozen', $e->errors()['code'] ?? null);
        }
    }

    public function test_cancellation_defers_while_an_attempt_is_in_flight_then_finalizes_on_settlement(): void
    {
        $f = $this->fixture();
        $this->payInFull($f['order']);

        // Overpayment -> approved shortfall obligation of 100000.
        app(OrderFulfillmentService::class)->adjustItemQuantity($f['item']->fresh(), 2, $f['admin'], 'shortfall');
        $obligation = RefundObligation::query()
            ->where('order_id', $f['order']->id)
            ->where('source_type', 'fulfillment_shortfall')
            ->firstOrFail();
        $this->assertSame('approved', $obligation->status);
        $this->assertSame(10000000, (int) round((float) $obligation->amount * 100));

        $ledger = app(RefundLedgerService::class);
        $attempt = $ledger->createAttempt($f['order']->fresh(), $obligation, [
            'amount_cents' => 10000000,
            'method' => 'bank_transfer',
            'idempotency_key' => 'inflight-1',
        ], $f['admin']);
        $ledger->recordInitiation($attempt, $f['admin'], 'TRX-1');

        // Financial cancellation (Step 1 + Step 2) while the payout attempt is still in flight.
        $cancellation = app(CancellationFinancialService::class);
        $f['order']->update(['status' => 'dibatalkan', 'cancelled_at' => now(), 'cancelled_by' => $f['admin']->id]);
        $cancellation->bookCancellationReduction($f['order']->fresh(), $f['admin']);
        $cancellation->finalize($f['order']->fresh(), $f['admin']);

        $order = $f['order']->fresh();
        $this->assertSame('pending_reconciliation', $order->cancellation_financial_status);
        // Step 1 still booked B -> 0 and never touched paid_amount.
        $this->assertSame(30000000, (int) round((float) $order->paid_amount * 100));
        $this->assertSame(1, OrderSalesReduction::query()->where('order_id', $order->id)->where('source_type', 'cancellation')->count());

        $payout = $ledger->completeAttempt($attempt->fresh(), $f['admin'], null, 'bank_transfer', 'TRX-1');
        $this->assertSame(10000000, (int) round((float) $payout->amount * 100));

        $order = $order->fresh();
        $this->assertSame('finalized', $order->cancellation_financial_status);
        $this->assertNotNull($order->cancellation_finalized_at);
    }

    public function test_attempt_amount_cannot_exceed_remaining_after_self_exclusion(): void
    {
        $f = $this->fixture();
        $this->payInFull($f['order']);
        app(OrderFulfillmentService::class)->adjustItemQuantity($f['item']->fresh(), 2, $f['admin'], 'shortfall');

        $obligation = RefundObligation::query()
            ->where('order_id', $f['order']->id)
            ->where('source_type', 'fulfillment_shortfall')
            ->firstOrFail();

        $ledger = app(RefundLedgerService::class);
        $first = $ledger->createAttempt($f['order']->fresh(), $obligation, [
            'amount_cents' => 6000000, 'method' => 'cash', 'idempotency_key' => 'a-1',
        ], $f['admin']);
        $ledger->recordInitiation($first, $f['admin']);

        try {
            $ledger->createAttempt($f['order']->fresh(), $obligation, [
                'amount_cents' => 6000000, 'method' => 'cash', 'idempotency_key' => 'a-2',
            ], $f['admin']);
            $this->fail('A second attempt must not exceed amount minus the first in-flight attempt.');
        } catch (ApiException $e) {
            $this->assertSame('attempt_exceeds_remaining', $e->errors()['code'] ?? null);
        }

        $second = $ledger->createAttempt($f['order']->fresh(), $obligation, [
            'amount_cents' => 4000000, 'method' => 'cash', 'idempotency_key' => 'a-3',
        ], $f['admin']);
        $ledger->recordInitiation($second, $f['admin']);

        // Settling the second one first is allowed: the first attempt's earlier in-flight amount
        // is excluded from ITS remaining, but not from the second's.
        $ledger->completeAttempt($second->fresh(), $f['admin']);
        $ledger->completeAttempt($first->fresh(), $f['admin']);

        $this->assertSame(10000000, (int) round((float) RefundPayout::query()
            ->where('obligation_id', $obligation->id)->sum('amount') * 100));
        $this->assertSame('completed', $obligation->fresh()->status);
        $this->assertSame(2, RefundAttempt::query()->where('obligation_id', $obligation->id)->where('status', 'succeeded')->count());
    }

    public function test_obligation_and_payout_creation_are_idempotent(): void
    {
        $f = $this->fixture();
        $this->payInFull($f['order']);
        app(OrderFulfillmentService::class)->adjustItemQuantity($f['item']->fresh(), 2, $f['admin'], 'shortfall');

        $obligation = RefundObligation::query()
            ->where('order_id', $f['order']->id)->where('source_type', 'fulfillment_shortfall')->firstOrFail();
        $ledger = app(RefundLedgerService::class);

        $amount = (int) round((float) $obligation->amount * 100);
        $attrs = [
            'source_type' => 'fulfillment_shortfall',
            'source_id' => $f['item']->id,
            'obligation_key' => $obligation->obligation_key,
            'reason' => 'replay',
        ];
        $replayed = $ledger->createForwardObligation($f['order']->fresh(), $attrs, $amount, $f['admin'], true);

        $this->assertSame($obligation->id, $replayed->id);
        $this->assertSame(1, RefundObligation::query()->where('order_id', $f['order']->id)->count());

        $attempt = $ledger->createAttempt($f['order']->fresh(), $obligation, [
            'amount_cents' => $amount, 'method' => 'cash', 'idempotency_key' => 'dup-1',
        ], $f['admin']);
        $ledger->recordInitiation($attempt, $f['admin']);

        $p1 = $ledger->completeAttempt($attempt->fresh(), $f['admin']);
        $p2 = $ledger->completeAttempt($attempt->fresh(), $f['admin']);

        $this->assertSame($p1->id, $p2->id);
        $this->assertSame(1, RefundPayout::query()->where('obligation_id', $obligation->id)->count());
    }

    public function test_http_attempt_lifecycle_is_finance_only_and_keyed(): void
    {
        $f = $this->fixture();
        $this->payInFull($f['order']);
        app(OrderFulfillmentService::class)->adjustItemQuantity($f['item']->fresh(), 2, $f['admin'], 'shortfall');

        $obligation = RefundObligation::query()
            ->where('order_id', $f['order']->id)->where('source_type', 'fulfillment_shortfall')->firstOrFail();
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $f['agen']->id]);

        // Admin has oversight but cannot move money.
        $this->actingAs($f['admin'])
            ->postJson("/api/v1/admin/refund-obligations/{$obligation->id}/attempts", [
                'amount_cents' => 10000000, 'method' => 'cash',
            ])->assertStatus(403);

        // Finance must supply an idempotency key.
        $this->flushHeaders();
        $this->actingAs($keuangan)
            ->postJson("/api/v1/admin/refund-obligations/{$obligation->id}/attempts", [
                'amount_cents' => 10000000, 'method' => 'cash',
            ])->assertStatus(422)->assertJsonPath('errors.code', 'idempotency_key_required');

        $attemptId = $this->actingAs($keuangan)
            ->withHeaders(['Idempotency-Key' => 'http-1'])
            ->postJson("/api/v1/admin/refund-obligations/{$obligation->id}/attempts", [
                'amount_cents' => 10000000, 'method' => 'cash',
            ])->assertCreated()->json('data.id');

        // Replaying the same key returns the same attempt, never a second one.
        $this->actingAs($keuangan)
            ->withHeaders(['Idempotency-Key' => 'http-1'])
            ->postJson("/api/v1/admin/refund-obligations/{$obligation->id}/attempts", [
                'amount_cents' => 10000000, 'method' => 'cash',
            ])->assertCreated()->assertJsonPath('data.id', $attemptId);

        $this->actingAs($keuangan)->postJson("/api/v1/admin/refund-attempts/{$attemptId}/initiate")->assertOk();
        $this->actingAs($keuangan)->postJson("/api/v1/admin/refund-attempts/{$attemptId}/complete")->assertOk();

        $this->assertSame(20000000, (int) round((float) $f['order']->fresh()->paid_amount * 100));
        $this->assertSame(1, RefundAttempt::query()->where('obligation_id', $obligation->id)->count());
    }

    /** One agent branch, one order for 3 units at 100000, still `diproses` and unpaid. */
    private function fixture(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Ledger Branch', 'address' => 'Test', 'latitude' => -6.2, 'longitude' => 106.8]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 0, 'free_shipping_enabled' => true, 'is_active' => true]);

        $product = Product::create(['sku' => 'LD-'.Str::uuid(), 'name' => 'Ledger Cake', 'slug' => 'ld-'.uniqid(), 'has_variations' => false, 'base_price' => 100000, 'weight_grams' => 500, 'status' => 'active']);
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

    private function payInFull(Order $order): void
    {
        $order->update([
            'payment_status' => 'paid',
            'paid_amount' => $order->total_amount,
            'gross_received_amount' => $order->total_amount,
            'remaining_amount' => 0,
        ]);
    }
}
