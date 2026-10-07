<?php

namespace Tests\Feature;

use App\Models\AgentPaymentGatewayConfig;
use App\Models\AgentProfile;
use App\Models\CodPaymentProof;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ShippingConfiguration;
use App\Models\User;
use App\Services\Order\OrderService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ConcurrencyHarness;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

/**
 * IMP-001 Step 3 — Audit finding #2 and #3 deterministic two-connection races
 * through the REAL production PaymentService paths (see
 * .phpunit-concurrency-actor.php `verify-bank-transfer`, `submit-bank-proof`,
 * `submit-cod-proof`, `confirm-cod-proof`).
 *
 * Committed fixtures + rebuild between tests (RestoresIsolatedTestDatabase),
 * same as the other concurrency suites — the actor processes must see the
 * branch/order/proof rows through their own MySQL connections.
 */
class PaymentProofConcurrencyTest extends TestCase
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
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Payment Race', 'address' => 'Race', 'latitude' => -6.2, 'longitude' => 106.8]);
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);

        $product = Product::create([
            'sku' => 'PPC-'.Str::uuid(), 'name' => 'Payment Race Cake', 'slug' => 'ppc-'.Str::uuid(),
            'has_variations' => false, 'base_price' => 100000, 'weight_grams' => 1000, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);

        if ($method === 'bank_transfer') {
            $bank = PaymentMethod::query()->where('code', 'bank_transfer')->firstOrFail();
            AgentPaymentGatewayConfig::create([
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
            $konsumen,
            $method,
            null,
            'ppc-'.Str::uuid(),
            null,
            null,
            null,
            null,
            null,
        );

        return compact('agen', 'keuangan', 'konsumen', 'product', 'order');
    }

    /**
     * Finding #2 (MAJOR) — concurrent verification of the SAME bank-transfer
     * proof must not apply the payment twice. Two requests that both passed the
     * controller's pending check cannot both add amount: the loser re-reads the
     * (now non-pending) persisted row under the lock and refuses. Exactly one
     * 'verified' row, one PAID transaction, and paid_amount == total.
     */
    public function test_concurrent_bank_transfer_verification_applies_payment_exactly_once(): void
    {
        for ($iteration = 1; $iteration <= 3; $iteration++) {
            $f = $this->fixture();
            $proof = $this->submitBankProof($f['konsumen'], $f['order']);

            $report = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'verify-bank-transfer', 'actor_id' => $f['keuangan']->id, 'subject_id' => $proof['verification_id'], 'extra' => ['approved' => true]],
                ['op' => 'verify-bank-transfer', 'actor_id' => $f['keuangan']->id, 'subject_id' => $proof['verification_id'], 'extra' => ['approved' => true]],
            );

            $this->assertTrue($report['different_connections'], 'must run on separate MySQL connections');
            $this->assertTrue($report['true_overlap'], 'the two verification transactions must genuinely overlap');
            $this->assertSame(0, $this->deadlockCount($report), 'no deadlock may surface: '.json_encode($report));

            $outcomes = collect([$report['a'], $report['b']])->pluck('outcome')->all();
            sort($outcomes);
            $this->assertSame(['failed', 'success'], $outcomes, 'exactly one verification wins, the loser is refused: '.json_encode(['a' => $report['a'], 'b' => $report['b']]));

            $order = $f['order']->fresh();
            $this->assertSame('verified', \App\Models\BankTransferVerification::find($proof['verification_id'])->status, 'exactly one verification row, finalized once');
            $this->assertSame('paid', $order->payment_status);
            $this->assertSame((float) $order->total_amount, (float) $order->paid_amount, 'the amount is applied exactly once');
            $this->assertSame(1, $order->paymentTransactions()->where('status', 'paid')->count(), 'exactly one PAID transaction');

            fwrite(STDOUT, 'PAYMENT_VERIFY_RACE '.json_encode(['iteration' => $iteration, 'a' => $report['a']['outcome'], 'b' => $report['b']['outcome']]).PHP_EOL);
        }
    }

    /**
     * Finding #3 (MAJOR, COD leg) — concurrent CONFIRMATION of the same COD
     * proof. The proof-row lock serializes them; the loser re-reads
     * status != pending and refuses, so the order is marked paid exactly once.
     */
    public function test_concurrent_cod_confirmation_marks_paid_exactly_once(): void
    {
        for ($iteration = 1; $iteration <= 2; $iteration++) {
            $f = $this->fixture('cod');
            $proof = $this->submitCodProof($f['konsumen'], $f['order']);

            $report = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'confirm-cod-proof', 'actor_id' => $f['keuangan']->id, 'subject_id' => $proof->id, 'extra' => ['confirmed' => true]],
                ['op' => 'confirm-cod-proof', 'actor_id' => $f['keuangan']->id, 'subject_id' => $proof->id, 'extra' => ['confirmed' => true]],
            );

            $this->assertTrue($report['different_connections']);
            $this->assertTrue($report['true_overlap']);
            $this->assertSame(0, $this->deadlockCount($report));

            $outcomes = collect([$report['a'], $report['b']])->pluck('outcome')->all();
            sort($outcomes);
            $this->assertSame(['failed', 'success'], $outcomes, 'exactly one COD confirmation wins: '.json_encode(['a' => $report['a'], 'b' => $report['b']]));

            $order = $f['order']->fresh();
            $this->assertSame('confirmed', $proof->fresh()->status);
            $this->assertSame('paid', $order->payment_status);
            $this->assertSame((float) $order->total_amount, (float) $order->paid_amount);

            fwrite(STDOUT, 'COD_CONFIRM_RACE '.json_encode(['iteration' => $iteration, 'a' => $report['a']['outcome'], 'b' => $report['b']['outcome']]).PHP_EOL);
        }
    }

    /**
     * Finding #3 (MAJOR) — concurrent bank-transfer proof submission vs
     * ORDER CANCELLATION. Both serialize on the ORDER row lock; whichever wins
     * second re-reads the persisted status. A cancellation that lands first
     * leaves the submitter refused (proof_locked); a submission that lands
     * first leaves the cancellation to proceed (or refuse) on its own terms.
     * Either interleaving is legal — the forbidden outcome is a proof landing
     * on a cancelled order.
     */
    public function test_concurrent_bank_submit_vs_cancellation_never_lands_proof_on_cancelled_order(): void
    {
        for ($iteration = 1; $iteration <= 3; $iteration++) {
            $f = $this->fixture();
            $proof = $this->submitBankProof($f['konsumen'], $f['order']);

            $report = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'cancel-order', 'actor_id' => $f['keuangan']->id, 'extra' => ['order_id' => $f['order']->id, 'reason' => 'concurrent with proof submission']],
                ['op' => 'submit-bank-proof', 'actor_id' => $f['konsumen']->id, 'subject_id' => $proof['transaction_id'], 'extra' => []],
            );

            $this->assertTrue($report['different_connections']);
            $this->assertTrue($report['true_overlap']);
            $this->assertSame(0, $this->deadlockCount($report), 'no deadlock: '.json_encode($report));

            $final = $f['order']->fresh();
            // The invariant that matters: a cancelled order can never gain a
            // freshly submitted proof, and can never be paid. Both
            // interleavings are legal — a submission that lands first (while
            // the order is still live) leaves the cancellation to proceed
            // afterwards; a cancellation that lands first is refused by the
            // locking re-read in submitBankTransferProof. The guard is what
            // makes the second interleaving impossible, so a "success" here
            // proves the submission saw a live order.
            $racingOutcome = $report['b']['outcome'];
            if ($racingOutcome === 'success') {
                $this->assertSame('pending', \App\Models\BankTransferVerification::find($proof['verification_id'])->status);
                $this->assertSame(0, $f['order']->paymentTransactions()->where('status', 'paid')->count());
                $this->assertNotSame('paid', $final->payment_status, 'a proof must never pay a cancelled order: '.json_encode($report['b']));
            } else {
                $this->assertSame('dibatalkan', $final->status, 'a refused submission implies the cancellation landed: '.json_encode($report['b']));
                $this->assertSame('pending', \App\Models\BankTransferVerification::find($proof['verification_id'])->status, 'the cancelled order keeps only its pre-race pending proof');
                $this->assertSame(0, $f['order']->paymentTransactions()->where('status', 'paid')->count());
            }

            fwrite(STDOUT, 'BANK_SUBMIT_CANCEL_RACE '.json_encode(['iteration' => $iteration, 'cancel' => $report['a']['outcome'], 'submit' => $report['b']['outcome']]).PHP_EOL);
        }
    }

    /**
     * Finding #3 (MAJOR, COD leg) — concurrent COD proof submission vs ORDER
     * CANCELLATION, same ORDER row lock. A cancelled order must never receive
     * a COD proof.
     */
    public function test_concurrent_cod_submit_vs_cancellation_never_lands_proof_on_cancelled_order(): void
    {
        for ($iteration = 1; $iteration <= 2; $iteration++) {
            $f = $this->fixture('cod');
            $proof = $this->submitCodProof($f['konsumen'], $f['order']);

            $report = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'cancel-order', 'actor_id' => $f['keuangan']->id, 'extra' => ['order_id' => $f['order']->id, 'reason' => 'concurrent with cod proof']],
                ['op' => 'submit-cod-proof', 'actor_id' => $f['konsumen']->id, 'subject_id' => $proof->payment_transaction_id, 'extra' => []],
            );

            $this->assertTrue($report['different_connections']);
            $this->assertTrue($report['true_overlap']);
            $this->assertSame(0, $this->deadlockCount($report));

            $final = $f['order']->fresh();
            // Same invariant as the bank-transfer leg: a COD proof must never
            // land on an already-cancelled order (refused by the locking
            // re-read in submitCodPaymentProof); a proof that lands first is
            // legal and the cancellation simply proceeds afterwards.
            $racingOutcome = $report['b']['outcome'];
            if ($racingOutcome === 'success') {
                $this->assertSame('pending', $proof->fresh()->status);
                $this->assertSame('unpaid', $final->payment_status);
            } else {
                $this->assertSame('dibatalkan', $final->status, 'a refused COD submission implies the cancellation landed: '.json_encode($report['b']));
                $this->assertSame('pending', $proof->fresh()->status, 'the cancelled order keeps only its pre-race pending proof');
                $this->assertSame('unpaid', $final->payment_status);
            }

            fwrite(STDOUT, 'COD_SUBMIT_CANCEL_RACE '.json_encode(['iteration' => $iteration, 'cancel' => $report['a']['outcome'], 'submit' => $report['b']['outcome']]).PHP_EOL);
        }
    }

    /** @return array{verification_id:int, transaction_id:int} */
    private function submitBankProof(User $konsumen, Order $order): array
    {
        $transaction = $order->paymentTransactions()->where('status', 'pending')->firstOrFail();

        $response = $this->actingAs($konsumen)->postJson("/api/v1/orders/{$order->id}/payment/proof", [
            'proof' => \Illuminate\Http\UploadedFile::fake()->image('proof.jpg'),
        ]);
        $response->assertOk();

        return [
            'verification_id' => (int) $response->json('data.transaction.bank_transfer_verification.id'),
            'transaction_id' => (int) $transaction->id,
        ];
    }

    private function submitCodProof(User $konsumen, Order $order): CodPaymentProof
    {
        $transaction = $order->paymentTransactions()->where('status', 'pending')->firstOrFail();

        $this->actingAs($konsumen)->postJson("/api/v1/orders/{$order->id}/payment/cod-proof", [
            'proof' => \Illuminate\Http\UploadedFile::fake()->image('proof.jpg'),
        ])->assertOk();

        return CodPaymentProof::query()->where('payment_transaction_id', $transaction->id)->firstOrFail();
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