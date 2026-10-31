<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AgentProfile;
use App\Models\BankTransferVerification;
use App\Models\Order;
use App\Models\OrderInvoiceSnapshot;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ShippingConfiguration;
use App\Models\User;
use App\Services\Invoice\InvoiceIssuanceService;
use App\Services\Order\OrderService;
use App\Services\Payment\PaymentService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ConcurrencyHarness;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

/**
 * PC-V2-GLM-AUDIT-CONTINUATION-001 (A) — deterministic two-connection
 * regressions for invoice issuance (Improvement 11).
 *
 * Two scenarios race through the REAL production service paths on two
 * genuinely independent MySQL connections (ConcurrencyHarness::runServiceRace
 * spawns two barrier-overlapped PHP processes; the harness refuses to report
 * anything if they shared a connection):
 *
 *  1. `invoice-ensure` — two concurrent lazy legacy issuances of an order
 *     whose invoice_issued_at was stripped (pre-tracking data). The locked
 *     re-read inside ensureIssuedIfEligible must serialize them: exactly one
 *     stamps issued_at and appends snapshot version 1, the loser neither
 *     re-issues nor duplicates a snapshot, and no unique-constraint error may
 *     surface.
 *
 *  2. `verify-bank-transfer` — two DIFFERENT pending proofs of the SAME order
 *     verified concurrently (two installments). Both verifications may win
 *     (different rows), but the ORDER row lock serializes the money and the
 *     invoice work: the invoice is issued exactly once (exactly one
 *     `invoice.issued` audit row) and snapshots remain strictly versioned
 *     [1, 2] with no duplicate-version violation.
 */
class InvoiceIssuanceConcurrencyTest extends TestCase
{
    use RestoresIsolatedTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        Storage::fake('public');
    }

    /** @return array<string, mixed> */
    private function fixture(string $method = 'bank_transfer'): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Invoice Race', 'address' => 'Race', 'latitude' => -6.2, 'longitude' => 106.8]);
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);

        $product = Product::create([
            'sku' => 'IIC-'.Str::uuid(), 'name' => 'Invoice Race Cake', 'slug' => 'iic-'.Str::uuid(),
            'has_variations' => false, 'base_price' => 100000, 'weight_grams' => 1000, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);

        if ($method === 'bank_transfer') {
            $bank = \App\Models\PaymentMethod::query()->where('code', 'bank_transfer')->firstOrFail();
            \App\Models\AgentPaymentGatewayConfig::create([
                'agent_id' => $agen->id, 'payment_method_id' => $bank->id, 'environment' => 'sandbox',
                'config' => ['bank_name' => 'BCA', 'account_name' => 'PT Prime', 'account_number' => '123456'],
            ]);
        }
        ShippingConfiguration::create([
            'agent_id' => $agen->id, 'price_per_km' => 2000, 'minimum_distance_km' => 0,
            'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true,
        ]);

        $order = app(OrderService::class)->createOrder(
            $konsumen,
            [['product_id' => $product->id, 'quantity' => 1]],
            [
                'recipient_name' => 'Race Buyer', 'recipient_phone' => '0811',
                'address_line' => 'Race Address', 'village_id' => null,
                'latitude' => -6.2, 'longitude' => 106.8,
            ],
            // Improvement 04: COD is staff-only — the branch agen places the
            // order on behalf of the buyer.
            $agen,
            $method,
            null,
            'iic-'.Str::uuid(),
            null,
            null,
            null,
            null,
            null,
        );

        return compact('agen', 'keuangan', 'konsumen', 'product', 'order');
    }

    private function issuedCount(Order $order): int
    {
        return ActivityLog::query()
            ->where('subject_type', Order::class)
            ->where('subject_id', $order->id)
            ->where('event', 'invoice.issued')
            ->count();
    }

    /**
     * Scenario 1 — lazy legacy issuance must be committed exactly once under a
     * genuine two-connection race (loss of one issuance is a financial-audit
     * regression; a double stamp is an idempotency regression; both are fail).
     */
    public function test_concurrent_lazy_legacy_issuance_commits_exactly_once(): void
    {
        for ($iteration = 1; $iteration <= 3; $iteration++) {
            $f = $this->fixture('cod');
            $order = $f['order']->fresh();

            // Simulate a legacy order that predates issuance tracking: strip the
            // timestamp, balance snapshots and the audit rows createOrder wrote.
            DB::table('activity_logs')->where('subject_type', Order::class)
                ->where('subject_id', $order->id)->where('event', 'invoice.issued')->delete();
            OrderInvoiceSnapshot::query()->where('order_id', $order->id)->delete();
            $order->forceFill(['invoice_issued_at' => null])->saveQuietly();

            $harness = new ConcurrencyHarness;
            $report = $harness->runServiceRace(
                ['op' => 'invoice-ensure', 'actor_id' => $f['keuangan']->id, 'subject_id' => $order->id],
                ['op' => 'invoice-ensure', 'actor_id' => $f['keuangan']->id, 'subject_id' => $order->id],
            );

            $this->assertTrue($report['different_connections'], 'must run on separate MySQL connections');
            $this->assertTrue($report['true_overlap'], 'the two lazy issuances must genuinely overlap');
            $this->assertSame(0, $this->deadlockCount($report), 'no deadlock may surface: '.json_encode($report));

            foreach (['a', 'b'] as $side) {
                $this->assertSame('success', $report[$side]['outcome'], 'ensureIssuedIfEligible must never error under the race: '.json_encode($report[$side]));
            }

            $final = $order->fresh();
            $this->assertNotNull($final->invoice_issued_at, 'a COD order is always eligible: the timestamp must be stamped');
            $this->assertSame(1, $this->issuedCount($final), 'exactly ONE audit row: no double issuance: '.json_encode($report));
            $this->assertSame(1, OrderInvoiceSnapshot::query()->where('order_id', $final->id)->count(), 'exactly one balance snapshot: no duplicate version');
            $this->assertSame(1, OrderInvoiceSnapshot::query()->where('order_id', $final->id)->value('version'));

            fwrite(STDOUT, 'INVOICE_LAZY_RACE '.json_encode(['iteration' => $iteration, 'a' => $report['a']['outcome'], 'b' => $report['b']['outcome']]).PHP_EOL);
        }
    }

    /**
     * Scenario 2 — two concurrent verifications of the SAME first payment
     * (bank transfer). Production intentionally keeps at most ONE pending
     * transaction per order (requestInstallment refuses a second), so the
     * reachable race is two Keuangan actors verifying the same proof at once:
     * exactly one applies the money AND issues the invoice; the loser is
     * refused (already_verified) without ever stamping artifacts. This is the
     * "invoice tidak diterbitkan ganda / no race error" regression.
     */
    public function test_concurrent_verification_issues_and_snapshots_exactly_once(): void
    {
        for ($iteration = 1; $iteration <= 3; $iteration++) {
            $f = $this->fixture('bank_transfer');
            $order = $f['order']->fresh();
            $transaction = $order->paymentTransactions()->where('status', 'pending')->firstOrFail();
            app(PaymentService::class)->submitBankTransferProof(
                $transaction, UploadedFile::fake()->image('proof.jpg'), $f['konsumen'],
            );
            $verification = BankTransferVerification::query()
                ->where('payment_transaction_id', $transaction->id)->firstOrFail();

            $report = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'verify-bank-transfer', 'actor_id' => $f['keuangan']->id, 'subject_id' => $verification->id, 'extra' => ['approved' => true]],
                ['op' => 'verify-bank-transfer', 'actor_id' => $f['keuangan']->id, 'subject_id' => $verification->id, 'extra' => ['approved' => true]],
            );

            $this->assertTrue($report['different_connections']);
            $this->assertTrue($report['true_overlap']);
            $this->assertSame(0, $this->deadlockCount($report), 'no deadlock may surface: '.json_encode($report));

            $outcomes = collect([$report['a'], $report['b']])->pluck('outcome')->all();
            sort($outcomes);
            $this->assertSame(['failed', 'success'], $outcomes, 'exactly one verification wins, the loser is refused: '.json_encode(['a' => $report['a'], 'b' => $report['b']]));

            $final = $order->fresh();
            $this->assertSame('verified', $verification->fresh()->status, 'exactly one verification row, finalized once');
            $this->assertSame('paid', $final->payment_status, 'the full-amount proof settles the order');
            $this->assertSame((float) $final->total_amount, (float) $final->paid_amount, 'the money is applied exactly once');
            $this->assertNotNull($final->invoice_issued_at, 'the first verified payment issues the invoice');
            $this->assertSame(1, $this->issuedCount($final), 'exactly ONE audit row: no double issuance across the race: '.json_encode($report));

            $snapshots = OrderInvoiceSnapshot::query()->where('order_id', $final->id)->orderBy('version')->get();
            $this->assertSame([1], $snapshots->pluck('version')->all(), 'exactly one balance snapshot: no duplicate-version violation');
            $this->assertSame('paid', $snapshots->last()->payment_status);
            $this->assertEquals(0.0, (float) $snapshots->last()->outstanding_amount, 'the snapshot must carry a zero outstanding once settled');

            fwrite(STDOUT, 'INVOICE_VERIFY_RACE '.json_encode(['iteration' => $iteration, 'a' => $report['a']['outcome'], 'b' => $report['b']['outcome']]).PHP_EOL);
        }
    }

    private function deadlockCount(array $report): int
    {
        return collect([$report['a'], $report['b']])->filter(function ($actor) {
            $sqlState = (string) ($actor['sql_state'] ?? '');
            $message = (string) ($actor['message'] ?? '');

            return $actor['outcome'] === 'failed' && ($sqlState === '40001' || str_contains($sqlState, '1213') || stripos($message, 'deadlock') !== false || stripos($message, 'lock wait timeout') !== false);
        })->count();
    }
}
