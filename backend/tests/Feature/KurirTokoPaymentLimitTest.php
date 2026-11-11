<?php

namespace Tests\Feature;

use App\Enums\PaymentTransactionStatus;
use App\Exceptions\ApiException;
use App\Models\CodPaymentProof;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Payment\PaymentService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REM-004 — Kurir Toko: at most 3 successful payments (COD + DP + transfer combined), the third
 * must settle, partial COD records the real nominal, no overpayment, idempotent confirmation.
 */
class KurirTokoPaymentLimitTest extends TestCase
{
    use RefreshDatabase;

    private PaymentService $payments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        $this->payments = app(PaymentService::class);
    }

    private function codOrder(string $provider = 'openroute', int $total = 50000): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $admin = User::factory()->admin()->create(['agent_id' => $agent->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        $cod = PaymentMethod::where('code', 'cod')->firstOrFail();
        $order = Order::create(['order_no' => 'PL-'.Str::upper(Str::random(10)), 'konsumen_id' => $konsumen->id, 'agent_id' => $agent->id, 'payment_method_id' => $cod->id, 'status' => 'diproses', 'payment_status' => 'unpaid', 'subtotal_amount' => $total, 'total_amount' => $total, 'paid_amount' => 0, 'remaining_amount' => $total, 'recipient_name_snapshot' => 'T', 'recipient_phone_snapshot' => '0811', 'address_snapshot' => 'T']);
        Shipment::create(['order_id' => $order->id, 'status' => 'pending', 'delivery_mode' => 'standard', 'shipping_provider_code' => $provider]);
        $this->payments->initiate($order, $cod);

        return [$order->fresh(), $admin, $konsumen];
    }

    /** One COD hand-over of $amount: submit the proof, then the finance side confirms it. */
    private function collectCod(Order $order, User $admin, User $konsumen, ?float $amount): CodPaymentProof
    {
        $tx = PaymentTransaction::where('order_id', $order->id)->where('status', PaymentTransactionStatus::PENDING->value)->latest('id')->firstOrFail();
        $proof = $this->payments->submitCodPaymentProof($tx, UploadedFile::fake()->image('cod.jpg'), $konsumen, 'konsumen', $amount);

        return $this->payments->confirmCodPayment($proof, $admin, true);
    }

    private function paid(Order $order): int
    {
        return PaymentTransaction::where('order_id', $order->id)->where('status', PaymentTransactionStatus::PAID->value)->count();
    }

    private function assertRefused(callable $fn, string $messageKey): void
    {
        try {
            $fn();
            $this->fail('expected the payment to be refused');
        } catch (ApiException $e) {
            $this->assertSame(__($messageKey), $e->getMessage());
            $this->assertSame(422, $e->status());
        }
    }

    public function test_partial_cod_records_the_real_nominal_and_never_marks_the_order_fully_paid(): void
    {
        [$order, $admin, $konsumen] = $this->codOrder();

        $this->collectCod($order, $admin, $konsumen, 20000);
        $order->refresh();
        $this->assertSame('partially_paid', $order->payment_status);
        $this->assertEquals(20000, $order->paid_amount);
        $this->assertEquals(30000, $order->remaining_amount);
        $this->assertEquals(20000, PaymentTransaction::where('order_id', $order->id)->where('status', 'paid')->value('amount'));
        $pending = PaymentTransaction::where('order_id', $order->id)->where('status', 'pending')->get();
        $this->assertCount(1, $pending, 'exactly one residual COD transaction is open');
        $this->assertEquals(30000, $pending->first()->amount);
    }

    public function test_three_combined_payments_settle_and_the_third_must_pay_everything(): void
    {
        [$order, $admin, $konsumen] = $this->codOrder();

        // Two earlier successful payments by other methods count toward the same limit.
        $bank = PaymentMethod::where('code', 'bank_transfer')->firstOrFail();
        foreach ([10000, 15000] as $amount) {
            PaymentTransaction::create(['order_id' => $order->id, 'payment_method_id' => $bank->id, 'type' => 'payment', 'amount' => $amount, 'status' => PaymentTransactionStatus::PAID->value, 'paid_at' => now()]);
        }
        $this->payments->applyPaymentToOrder($order->fresh(), 25000);
        // Pending / failed / cancelled attempts never count.
        foreach ([PaymentTransactionStatus::FAILED, PaymentTransactionStatus::CANCELLED, PaymentTransactionStatus::EXPIRED] as $status) {
            PaymentTransaction::create(['order_id' => $order->id, 'payment_method_id' => $bank->id, 'type' => 'payment', 'amount' => 5000, 'status' => $status->value]);
        }
        $order->refresh();
        $this->assertSame(2, $this->payments->successfulPaymentCount($order));

        // The third payment may not leave a balance behind …
        $this->assertRefused(fn () => $this->collectCod($order, $admin, $konsumen, 10000), 'messages.payment.third_payment_must_settle');
        $this->assertSame(2, $this->paid($order));
        // … nor exceed it …
        $this->assertRefused(fn () => $this->collectCod($order, $admin, $konsumen, 30000), 'messages.payment.overpayment_refused');
        // … and the settling one is accepted.
        $this->collectCod($order, $admin, $konsumen, 25000);
        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertEquals(0, $order->remaining_amount);
        $this->assertSame(3, $this->paid($order));
    }

    public function test_a_fourth_successful_payment_is_refused_even_when_a_balance_reappears(): void
    {
        [$order, $admin, $konsumen] = $this->codOrder();
        $this->collectCod($order, $admin, $konsumen, 10000);
        $this->collectCod($order, $admin, $konsumen, 10000);
        $this->collectCod($order, $admin, $konsumen, 30000);
        $order->refresh();
        $this->assertSame(3, $this->paid($order));

        // OWNER DECISION PENDING (REM-004 blocker): Admin QTY/SC-03 may raise the total after three
        // payments. The locked rule is enforced — a 4th payment is refused; what the business does with
        // that new obligation is NOT decided here and the Admin feature itself is not blocked.
        $order->update(['total_amount' => 60000]);
        $this->payments->reconcileTotals($order->fresh());
        $cod = PaymentMethod::where('code', 'cod')->firstOrFail();
        $this->payments->initiate($order->fresh(), $cod); // the open COD hand-over for the new balance

        // COD collection route …
        $this->assertRefused(fn () => $this->collectCod($order->fresh(), $admin, $konsumen, 10000), 'messages.payment.payment_limit_reached');
        // … and the transfer routes.
        $order->update(['payment_method_id' => PaymentMethod::where('code', 'bank_transfer')->value('id')]);
        $this->assertRefused(fn () => $this->payments->requestInstallment($order->fresh(), $admin, 5000), 'messages.payment.payment_limit_reached');
        $this->assertRefused(fn () => $this->payments->requestSettlement($order->fresh(), $admin), 'messages.payment.payment_limit_reached');
        $this->assertSame(3, $this->paid($order));
    }

    public function test_confirming_the_same_cod_proof_twice_never_doubles_the_payment(): void
    {
        [$order, $admin, $konsumen] = $this->codOrder();
        $proof = $this->collectCod($order, $admin, $konsumen, 20000);

        try {
            $this->payments->confirmCodPayment($proof, $admin, true);
            $this->fail('second confirmation must be refused');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status());
        }
        $this->assertEquals(20000, $order->fresh()->paid_amount);
        $this->assertSame(1, $this->paid($order));
    }

    public function test_marking_cod_paid_after_a_partial_collects_only_the_remainder(): void
    {
        [$order, $admin, $konsumen] = $this->codOrder();
        $this->collectCod($order, $admin, $konsumen, 20000);

        $this->payments->markCod($order->fresh(), $admin, true);
        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertEquals(50000, $order->paid_amount);
        $this->assertSame(2, $this->paid($order));
        // Idempotent: a second call finds nothing owed and adds nothing.
        $this->payments->markCod($order->fresh(), $admin, true);
        $this->assertSame(2, $this->paid($order));
        $this->assertEquals(50000, $order->fresh()->paid_amount);
    }

    public function test_a_plain_full_cod_collection_behaves_exactly_as_before(): void
    {
        [$order, $admin, $konsumen] = $this->codOrder();
        $this->collectCod($order, $admin, $konsumen, null);
        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertEquals(50000, $order->paid_amount);
        $this->assertSame(1, $this->paid($order));
        $this->assertSame(0, PaymentTransaction::where('order_id', $order->id)->where('status', 'pending')->count());
    }

    public function test_expedition_orders_accept_only_a_single_full_payment(): void
    {
        [$order, $admin] = $this->codOrder('rajaongkir');
        $order->update(['payment_method_id' => PaymentMethod::where('code', 'bank_transfer')->value('id')]);

        $this->assertRefused(fn () => $this->payments->requestInstallment($order->fresh(), $admin, 10000), 'messages.payment.expedition_full_payment_only');
        $this->assertSame(0, PaymentTransaction::where('order_id', $order->id)->where('status', 'paid')->count());
    }

    public function test_receipt_shows_the_outstanding_balance_not_the_order_total_after_a_partial_collection(): void
    {
        [$order, $admin, $konsumen] = $this->codOrder();
        $this->collectCod($order, $admin, $konsumen, 20000);
        $shipment = Shipment::where('order_id', $order->id)->firstOrFail();

        $this->actingAs($admin)->getJson("/api/v1/shipments/{$shipment->id}/receipt")
            ->assertOk()->assertJsonPath('data.payment.cod_amount_due', '30000.00');
    }

    /** F04 — a real partial COD collection cannot be wiped by the un-mark toggle. */
    public function test_f04_partial_cod_collection_cannot_be_undone_by_the_toggle(): void
    {
        [$order, $admin, $konsumen] = $this->codOrder();
        $this->collectCod($order, $admin, $konsumen, 20000);
        $historyBefore = PaymentTransaction::where('order_id', $order->id)->orderBy('id')->get(['id', 'amount', 'status'])->toArray();

        $this->assertRefused(fn () => $this->payments->markCod($order->fresh(), $admin, false), 'messages.payment.cod_undo_not_allowed');

        $this->assertEquals(20000, $order->fresh()->paid_amount, 'paid amount and the paid transaction still agree');
        $this->assertSame($historyBefore, PaymentTransaction::where('order_id', $order->id)->orderBy('id')->get(['id', 'amount', 'status'])->toArray());
        // The next collection continues from the real balance — no duplicate receipt.
        $this->collectCod($order->fresh(), $admin, $konsumen, 30000);
        $this->assertEquals(50000, $order->fresh()->paid_amount);
        $this->assertSame(2, $this->paid($order));
    }

    /** F04 — the legacy single FULL collection can still be un-marked, and the SAME transaction reverts. */
    public function test_f04_single_full_cod_collection_undo_reverts_the_paid_transaction_consistently(): void
    {
        [$order, $admin, $konsumen] = $this->codOrder();
        $this->collectCod($order, $admin, $konsumen, null);
        $this->payments->markCod($order->fresh(), $admin, false);

        $this->assertEquals(0, $order->fresh()->paid_amount);
        $this->assertSame(0, $this->paid($order), 'no paid transaction survives an un-marked order');
    }
}
