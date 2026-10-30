<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderFinancialSnapshot;
use App\Models\OrderFinancialSnapshotUnit;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\RefundAttempt;
use App\Models\RefundLedgerEvent;
use App\Models\RefundObligation;
use App\Models\RefundPayout;
use App\Models\ShippingConfiguration;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\Refund\OrderFinancialSnapshotService;
use App\Services\Refund\RefundAudit;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * Audit/financial immutability safeguards that the application itself enforces:
 * append-only audit, immutable snapshot allocations, immutable successful payouts
 * (only legacy_unverified -> proven), immutable obligation amount after approval,
 * and UNIQUE idempotency references at the database level.
 *
 * DB-level triggers are NOT installed in v1: these are model-boundary guards plus
 * database UNIQUE constraints. See artifact 18 (§4) for the exact enforcement split.
 */
class RefundLedgerAuditImmutabilityTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_audit_events_are_append_only_at_the_application_boundary(): void
    {
        $order = $this->order();
        $audit = app(RefundAudit::class);

        $event = $audit->record('immutability.test', $order, eventKey: 'immutability-event-1', reason: 'original', payload: ['x' => 1]);
        $this->assertNotNull($event);

        $this->assertThrowsRuntime(fn () => $event->update(['reason' => 'tampered']), 'append-only');
        $this->assertThrowsRuntime(fn () => $event->delete(), 'append-only');

        $fresh = RefundLedgerEvent::query()->whereKey($event->id)->firstOrFail();
        $this->assertSame('original', $fresh->reason);
        $this->assertSame(1, RefundLedgerEvent::query()->where('event_key', 'immutability-event-1')->count());

        // Idempotency reference uniqueness is enforced by the database.
        try {
            RefundLedgerEvent::create([
                'order_id' => $order->id,
                'event_type' => 'immutability.duplicate',
                'event_key' => 'immutability-event-1',
                'actor_type' => 'system',
                'occurred_at' => now(),
            ]);
            $this->fail('A duplicate event_key must be rejected by the database.');
        } catch (QueryException) {
            // expected
        }

        $this->assertSame(1, RefundLedgerEvent::query()->where('event_key', 'immutability-event-1')->count());
    }

    public function test_snapshot_and_its_unit_allocations_are_immutable(): void
    {
        $order = $this->order();
        $snapshot = app(OrderFinancialSnapshotService::class)
            ->ensure($order, OrderFinancialSnapshotService::TRIGGER_RETURN_APPROVAL);

        $this->assertThrowsRuntime(fn () => $snapshot->update(['subtotal_basis' => 1]), 'immutable');
        $this->assertThrowsRuntime(fn () => $snapshot->delete(), 'never deleted');

        $unit = OrderFinancialSnapshotUnit::query()->where('snapshot_id', $snapshot->id)->orderBy('id')->firstOrFail();
        $originalNet = (string) $unit->unit_net;
        $originalGross = (string) $unit->unit_gross;
        $originalSubtotal = (string) $snapshot->fresh()->subtotal_basis;

        $this->assertThrowsRuntime(fn () => $unit->fresh()->update(['unit_net' => 0.01]), 'allocations are immutable');
        $this->assertThrowsRuntime(fn () => $unit->fresh()->update(['unit_gross' => 0.01]), 'allocations are immutable');
        $this->assertThrowsRuntime(fn () => $unit->fresh()->delete(), 'never deleted');

        // Consumption fields are the only allowed mutation, and only once.
        $unit = $unit->fresh();
        $unit->update(['consumed_at' => now(), 'consumed_by_type' => 'return_approval', 'consumed_by_id' => 1, 'consumption_seq' => 1]);
        $this->assertThrowsRuntime(fn () => $unit->fresh()->update(['consumption_seq' => 2]), 'final and cannot be changed');

        $fresh = $unit->fresh();
        $this->assertSame($originalNet, (string) $fresh->unit_net);
        $this->assertSame($originalGross, (string) $fresh->unit_gross);
        $this->assertSame($originalSubtotal, (string) $snapshot->fresh()->subtotal_basis);
    }

    public function test_successful_payout_is_immutable_except_proven_promotion(): void
    {
        $order = $this->order();
        $obligation = $this->obligation($order, 'payout-immutable', 50000, 'approved');

        $payout = $this->payout($order, $obligation, 'payout-immutable-a', 'legacy_unverified', true);

        $this->assertThrowsRuntime(fn () => $payout->fresh()->update(['amount' => 1]), 'immutable');
        $this->assertThrowsRuntime(fn () => $payout->fresh()->update(['method' => 'bank_transfer']), 'immutable');
        $this->assertThrowsRuntime(fn () => $payout->fresh()->update(['paid_at' => now()->subDay()]), 'immutable');
        $this->assertThrowsRuntime(fn () => $payout->fresh()->delete(), 'never deleted');

        // The one permitted transition: legacy_unverified -> proven.
        $payout = $payout->fresh();
        $payout->update(['verification_status' => 'proven']);
        $this->assertSame('proven', $payout->fresh()->verification_status);

        $this->assertThrowsRuntime(
            fn () => $payout->fresh()->update(['verification_status' => 'legacy_unverified']),
            'only go legacy_unverified -> proven',
        );

        // A payout that does not affect paid truth may never be proven (D-01 prohibition).
        $secondObligation = $this->obligation($order, 'payout-immutable-2', 10000, 'approved');
        $nonAffecting = $this->payout($order, $secondObligation, 'payout-immutable-b', 'legacy_unverified', false);

        $this->assertThrowsRuntime(
            fn () => $nonAffecting->fresh()->update(['verification_status' => 'proven']),
            'Only payouts that affect paid_amount may become proven',
        );
    }

    public function test_obligation_amount_is_immutable_after_approval(): void
    {
        $order = $this->order();
        $obligation = $this->obligation($order, 'obligation-immutable', 10000, 'pending');

        // Pre-approval corrections are still possible.
        $obligation->update(['amount' => 20000]);
        $this->assertSame('20000.00', (string) $obligation->fresh()->amount);

        $obligation->update(['status' => 'approved', 'approved_at' => now(), 'approval_source' => 'manual']);

        $this->assertThrowsRuntime(fn () => $obligation->fresh()->update(['amount' => 30000]), 'immutable after approval');
        $this->assertThrowsRuntime(fn () => $obligation->fresh()->delete(), 'never deleted');

        // State transitions/annotations are still allowed.
        $obligation->fresh()->update(['status' => 'partially_paid', 'cancel_reason' => 'note']);
        $this->assertSame('partially_paid', $obligation->fresh()->status);
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

    private function order(): Order
    {
        $this->bootUsers();
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $this->owner->id]);
        AgentProfile::create(['user_id' => $this->owner->id, 'store_name' => 'Immutability Branch', 'address' => 'Test', 'latitude' => -6.2, 'longitude' => 106.8]);
        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 0, 'free_shipping_enabled' => true, 'is_active' => true]);

        $product = Product::create([
            'sku' => 'IM-'.Str::uuid(), 'name' => 'Immutability Cake', 'slug' => 'im-'.uniqid(),
            'has_variations' => false, 'base_price' => 100000, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $this->owner->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $this->owner->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 10]);

        $orderId = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $product->id, 'quantity' => 3]],
                'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated()->json('data.id');

        return Order::withoutGlobalScopes()->findOrFail($orderId);
    }

    private function obligation(Order $order, string $key, int $amount, string $status): RefundObligation
    {
        return RefundObligation::create([
            'obligation_no' => 'RO-'.Str::ulid(),
            'order_id' => $order->id,
            'source_type' => 'return_stage',
            'obligation_key' => $key,
            'amount' => $amount,
            'status' => $status,
            'provenance' => 'forward',
            'approved_at' => $status === 'approved' ? now() : null,
            'deferred_amount' => '0.00',
            'deferred_remaining' => '0.00',
        ]);
    }

    private function payout(Order $order, RefundObligation $obligation, string $key, string $verification, bool $affectsPaid): RefundPayout
    {
        $attempt = RefundAttempt::create([
            'obligation_id' => $obligation->id,
            'attempt_no' => ((int) RefundAttempt::query()->where('obligation_id', $obligation->id)->max('attempt_no')) + 1,
            'method' => 'cash',
            'amount' => $obligation->amount,
            'status' => 'succeeded',
            'idempotency_key' => $key,
        ]);

        return RefundPayout::create([
            'payout_no' => 'RP-'.Str::ulid(),
            'obligation_id' => $obligation->id,
            'order_id' => $order->id,
            'attempt_id' => $attempt->id,
            'payout_key' => $key,
            'amount' => $obligation->amount,
            'method' => 'cash',
            'paid_at' => now(),
            'verification_status' => $verification,
            'affects_paid_amount' => $affectsPaid,
            'paid_effect_applied_at' => now(),
        ]);
    }

    private function assertThrowsRuntime(callable $callback, string $messageContains): void
    {
        try {
            $callback();
            $this->fail('Expected RuntimeException containing: '.$messageContains);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($messageContains, $e->getMessage());
        }
    }
}
