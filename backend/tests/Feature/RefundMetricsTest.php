<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
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
use App\Services\Refund\RefundMetrics;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/** I1–I9, I11, I12, I16, I18, I19 against RefundMetrics (integer cents). */
class RefundMetricsTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_metric_identities_and_billable_basis(): void
    {
        $order = $this->order();
        $order->update(['total_amount' => 100000, 'paid_amount' => 80000, 'gross_received_amount' => 100000]);

        $obligation = $this->obligation($order, 'metrics-1', 20000, 'completed');
        $this->payout($order, $obligation, 20000, 'legacy_unverified');

        // applied_to_order_total=false reductions are the only ones subtracted from B.
        OrderSalesReduction::create([
            'order_id' => $order->id, 'source_type' => 'return_stage', 'source_id' => 1,
            'amount' => 20000, 'applied_to_order_total' => false, 'provenance' => 'forward',
        ]);
        OrderSalesReduction::create([
            'order_id' => $order->id, 'source_type' => 'fulfillment_shortfall', 'source_id' => 2,
            'amount' => 20000, 'applied_to_order_total' => true, 'provenance' => 'forward',
        ]);

        $m = app(RefundMetrics::class)->forOrder($order->fresh());

        $this->assertSame(10000000, $m['gross_cents']);
        $this->assertSame(8000000, $m['paid_cents']);
        $this->assertSame(2000000, $m['applied_cents']);
        $this->assertSame(0, $m['proven_cents']);
        $this->assertSame(2000000, $m['unverified_cents']);

        $this->assertGreaterThanOrEqual(0, $m['paid_cents']); // I2
        $this->assertGreaterThanOrEqual(0, $m['billable_cents']);
        $this->assertGreaterThanOrEqual(0, $m['reserved_cents']);
        $this->assertGreaterThanOrEqual(0, $m['available_cents']);

        // I1 P = G - A; I3 A = R_c + R_u; I4 R_c is proven-only.
        $this->assertSame($m['gross_cents'] - $m['applied_cents'], $m['paid_cents']);
        $this->assertSame($m['applied_cents'], $m['proven_cents'] + $m['unverified_cents']);

        // I6 only post-invoice reductions + reinstatements affect B (the true row is not subtracted twice).
        $this->assertSame(8000000, $m['billable_cents']);
        $this->assertSame(0, $m['outstanding_cents']); // I7
        $this->assertSame(0, $m['overpayment_cents']); // I8
        $this->assertSame(0, $m['reserved_cents']);
        $this->assertSame(0, $m['available_cents']);   // I9
    }

    public function test_in_flight_reservation_partial_payout_and_no_dual_outstanding_overpayment(): void
    {
        $order = $this->order();
        $order->update(['total_amount' => 100000, 'paid_amount' => 80000, 'gross_received_amount' => 100000]);

        $obligation = $this->obligation($order, 'metrics-2', 30000, 'approved');

        // I18: a non-terminal attempt is reserved in R_r even before any payout.
        $this->attempt($obligation, 10000, 'created', 'metrics-attempt-1');
        $m = app(RefundMetrics::class)->forOrder($order->fresh());
        $this->assertSame(3000000, $m['reserved_cents']);
        $this->assertSame(0, $m['available_cents']);

        // I11: settling 4000 reduces P and R_r by the same x and leaves C unchanged.
        $this->attempt($obligation, 10000, 'failed', 'metrics-attempt-2');
        $this->payout($order, $obligation, 4000, 'proven');
        $order->update(['paid_amount' => 76000]);
        $after = app(RefundMetrics::class)->forOrder($order->fresh());

        $this->assertSame($m['paid_cents'] - 400000, $after['paid_cents']);
        $this->assertSame($m['reserved_cents'] - 400000, $after['reserved_cents']);
        $this->assertSame($m['available_cents'], $after['available_cents']);

        // I16: A + R_r <= G (always).
        $this->assertLessThanOrEqual($after['gross_cents'], $after['applied_cents'] + $after['reserved_cents']);

        // I12: outstanding and overpayment are never both positive.
        $order->update(['total_amount' => 70000]);
        $over = app(RefundMetrics::class)->forOrder($order->fresh());
        $this->assertGreaterThan(0, $over['overpayment_cents']);
        $this->assertSame(0, $over['outstanding_cents']);

        $order->update(['total_amount' => 200000]);
        $under = app(RefundMetrics::class)->forOrder($order->fresh());
        $this->assertGreaterThan(0, $under['outstanding_cents']);
        $this->assertSame(0, $under['overpayment_cents']);

        // I19 deferred conservation + zero-amount container contributes nothing to R_r.
        $deferred = $this->obligation($order, 'metrics-deferred', 0, 'approved', 30000, 10000);
        $zero = $this->obligation($order, 'metrics-zero', 0, 'approved', 20000, 20000);
        $m2 = app(RefundMetrics::class)->forOrder($order->fresh());
        foreach ([$deferred, $zero] as $ob) {
            $this->assertGreaterThanOrEqual(0, (int) round((float) $ob->fresh()->deferred_remaining * 100));
            $this->assertLessThanOrEqual(
                (int) round((float) $ob->fresh()->deferred_amount * 100),
                (int) round((float) $ob->fresh()->deferred_remaining * 100),
            );
        }
        $this->assertSame($after['reserved_cents'], $m2['reserved_cents']);
    }

    private function order(): Order
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Metrics Branch', 'address' => 'Test', 'latitude' => -6.2, 'longitude' => 106.8]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 0, 'free_shipping_enabled' => true, 'is_active' => true]);
        $product = Product::create(['sku' => 'MT-'.Str::uuid(), 'name' => 'Metrics Cake', 'slug' => 'mt-'.uniqid(), 'has_variations' => false, 'base_price' => 100000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 10]);

        $orderId = $this->actingAs($agen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
            'konsumen_id' => $konsumen->id,
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated()->json('data.id');

        return Order::withoutGlobalScopes()->findOrFail($orderId);
    }

    private function obligation(Order $order, string $key, int $amount, string $status, int $deferred = 0, int $remaining = 0): RefundObligation
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
            'completed_at' => $status === 'completed' ? now() : null,
            'deferred_amount' => $deferred,
            'deferred_remaining' => $remaining,
        ]);
    }

    private function attempt(RefundObligation $obligation, int $amount, string $status, string $key): RefundAttempt
    {
        return RefundAttempt::create([
            'obligation_id' => $obligation->id,
            'attempt_no' => ((int) RefundAttempt::query()->where('obligation_id', $obligation->id)->max('attempt_no')) + 1,
            'method' => 'cash',
            'amount' => $amount,
            'status' => $status,
            'idempotency_key' => $key,
        ]);
    }

    private function payout(Order $order, RefundObligation $obligation, int $amount, string $verification): RefundPayout
    {
        $attempt = $this->attempt($obligation, $amount, 'succeeded', 'payout-'.$obligation->id.'-'.$amount.'-'.$verification);

        return RefundPayout::create([
            'payout_no' => 'RP-'.Str::ulid(),
            'obligation_id' => $obligation->id,
            'order_id' => $order->id,
            'attempt_id' => $attempt->id,
            'payout_key' => $attempt->idempotency_key,
            'amount' => $amount,
            'method' => 'cash',
            'paid_at' => now(),
            'verification_status' => $verification,
            'affects_paid_amount' => true,
            'paid_effect_applied_at' => now(),
        ]);
    }
}
