<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AgentPaymentGatewayConfig;
use App\Models\AgentProfile;
use App\Models\Commission;
use App\Models\Order;
use App\Models\OrderAdditionalPayment;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariation;
use App\Models\ProductVariationStock;
use App\Models\Shipment;
use App\Models\StockMovement;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Exceptions\ApiException;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Payment\PaymentService;
use Illuminate\Http\UploadedFile;
use App\Models\WarehouseSubLocation;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\Concerns\ResolvesCheckoutActor;
use Tests\TestCase;

/**
 * REM-004 / Owner Decision 01 — Admin order edits on a settled Kurir Toko order use the EXISTING
 * additional-payment (increase) and refund (decrease) mechanisms; they are never a 4th regular payment.
 */
class AdditionalPaymentAfterRegularPaymentsTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;
    use ResolvesCheckoutActor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    /** @return array{agen:User, admin:User, keuangan:User, konsumen:User} */
    private function makeAgentBranch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko QA', 'address' => 'Jl. QA',
            'latitude' => -6.2, 'longitude' => 106.8166,
        ]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);

        $bankTransfer = PaymentMethod::where('code', 'bank_transfer')->firstOrFail();
        AgentPaymentGatewayConfig::create([
            'agent_id' => $agen->id, 'payment_method_id' => $bankTransfer->id, 'environment' => 'sandbox',
            'config' => ['bank_name' => 'BCA', 'account_name' => 'QA', 'account_number' => '123'],
        ]);

        return compact('agen', 'admin', 'keuangan', 'konsumen');
    }

    private function makeProduct(User $agen, string $name, int $price, int $stockQty): Product
    {
        $product = Product::create(['sku' => 'AP-'.Str::uuid(), 'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(), 'has_variations' => false, 'base_price' => $price, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => $stockQty, 'quantity_reserved' => 0]);

        return $product;
    }

    private function placeOrder(User $konsumen, array $items): Order
    {
        $response = $this->postOrderAs($konsumen, [
            'payment_method_code' => 'cod', 'items' => $items,
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);
        $response->assertCreated();

        return Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
    }

    /** Three regular COD collections that settle the order (kurir_toko channel, real PaymentService path). */
    private function settleWithThreeRegularPayments(Order $order, User $admin, User $konsumen): Order
    {
        $svc = app(PaymentService::class);
        $total = round((float) $order->total_amount, 2);
        foreach ([round($total * 0.25, 2), round($total * 0.25, 2), null] as $amount) {
            $order->refresh();
            $tx = PaymentTransaction::where('order_id', $order->id)->where('status', 'pending')->latest('id')->firstOrFail();
            $open = round((float) $order->total_amount - (float) $order->paid_amount, 2);
            $proof = $svc->submitCodPaymentProof($tx, UploadedFile::fake()->image('c.jpg'), $konsumen, 'konsumen', $amount ?? $open);
            $svc->confirmCodPayment($proof, $admin, true);
        }

        return $order->fresh();
    }

    private function addLine(Order $order, User $actor, array $payload)
    {
        return $this->actingAs($actor)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson("/api/v1/orders/{$order->id}/items", $payload);
    }

    private function regularPaid(Order $order): int
    {
        return PaymentTransaction::where('order_id', $order->id)->where('status', 'paid')->where('type', '!=', 'additional_payment')->count();
    }

    public function test_sc03_after_three_regular_payments_uses_additional_payment_not_a_fourth_instalment(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'keuangan' => $keuangan, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $a = $this->makeProduct($agen, 'Kue A', 40000, 20);
        $b = $this->makeProduct($agen, 'Kue B', 25000, 20);
        $order = $this->placeOrder($konsumen, [['product_id' => $a->id, 'quantity' => 2]]);
        $order = $this->settleWithThreeRegularPayments($order, $admin, $konsumen);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(3, $this->regularPaid($order));
        $paidBefore = (float) $order->paid_amount;

        // Admin SC-03 add-line still works and creates ONE pending additional payment for the delta.
        $this->addLine($order, $admin, ['product_id' => $b->id, 'quantity' => 1, 'reason' => 'tambah', 'additional_payment_method' => 'cod'])->assertCreated();
        $order->refresh();
        $additional = OrderAdditionalPayment::where('order_id', $order->id)->get();
        $this->assertCount(1, $additional);
        $this->assertEquals(25000, $additional->first()->amount);
        $this->assertSame('partially_paid', $order->payment_status);
        $this->assertEquals(25000, $order->remaining_amount);

        // A regular payment route cannot touch it: the cap holds and nothing is double collected.
        $svc = app(PaymentService::class);
        try {
            $svc->markCod($order->fresh(), $admin, true);
            $this->assertEquals($paidBefore, (float) $order->fresh()->paid_amount, 'regular COD must not swallow the additional obligation');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status());
        }
        $this->assertSame(3, $this->regularPaid($order));

        // The existing additional-payment mechanism settles it (Keuangan), exactly once.
        $this->actingAs($keuangan)->patchJson("/api/v1/admin/additional-payments/{$additional->first()->id}/status", ['paid' => true])->assertOk();
        $this->actingAs($keuangan)->patchJson("/api/v1/admin/additional-payments/{$additional->first()->id}/status", ['paid' => true])->assertStatus(422);
        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertEquals(0, $order->remaining_amount);
        $this->assertEquals($paidBefore + 25000, (float) $order->paid_amount);
        $this->assertSame(3, $this->regularPaid($order), 'regular payments stay three');
        $this->assertSame(1, OrderAdditionalPayment::where('order_id', $order->id)->where('status', 'paid')->count());
    }

    public function test_reducing_quantity_after_three_regular_payments_uses_the_existing_refund_path(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $a = $this->makeProduct($agen, 'Kue A', 40000, 20);
        $order = $this->placeOrder($konsumen, [['product_id' => $a->id, 'quantity' => 3]]);
        $order = $this->settleWithThreeRegularPayments($order, $admin, $konsumen);
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();
        $paidBefore = (float) $order->paid_amount;

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/fulfillment", ['fulfilled_quantity' => 2, 'reason' => 'stok'])->assertOk();

        $order->refresh();
        $this->assertSame(3, $this->regularPaid($order), 'no new payment is created by a reduction');
        $this->assertEquals($paidBefore, (float) $order->paid_amount, 'paid history untouched until a refund is actually processed');
        $this->assertDatabaseHas('order_item_adjustments', ['order_item_id' => $item->id, 'quantity_reduced' => 1, 'refund_amount' => 40000, 'refund_status' => 'pending']);
        $this->assertSame(0, OrderAdditionalPayment::where('order_id', $order->id)->count());
        $this->assertEquals(0, $order->remaining_amount);
    }

    private function settledOrderWithTransferAdditional(): array
    {
        ['agen' => $agen, 'admin' => $admin, 'keuangan' => $keuangan, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $a = $this->makeProduct($agen, 'Kue A', 40000, 20);
        $b = $this->makeProduct($agen, 'Kue B', 25000, 20);
        $order = $this->settleWithThreeRegularPayments($this->placeOrder($konsumen, [['product_id' => $a->id, 'quantity' => 2]]), $admin, $konsumen);
        $this->addLine($order, $admin, ['product_id' => $b->id, 'quantity' => 1, 'reason' => 'tambah', 'additional_payment_method' => 'transfer'])->assertCreated();
        $obligation = OrderAdditionalPayment::where('order_id', $order->id)->firstOrFail();
        $tx = PaymentTransaction::findOrFail($obligation->payment_transaction_id);

        return [$order->fresh(), $obligation, $tx, $admin, $keuangan, $konsumen];
    }

    /** F03 — transfer verification first, direct "paid" second: the balance moves once, nothing is stale. */
    public function test_f03_transfer_verified_then_direct_paid_does_not_double_apply(): void
    {
        [$order, $obligation, $tx, , $keuangan, $konsumen] = $this->settledOrderWithTransferAdditional();
        $svc = app(PaymentService::class);
        $paidBefore = (float) $order->paid_amount;

        $verification = $svc->submitBankTransferProof($tx, UploadedFile::fake()->image('p.jpg'), $konsumen);
        $svc->verifyBankTransfer($verification, $keuangan, true);

        $this->assertSame('paid', $obligation->fresh()->status, 'the obligation is settled by the verification itself');
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertEquals($paidBefore + 25000, (float) $order->fresh()->paid_amount);

        $this->actingAs($keuangan)->patchJson("/api/v1/admin/additional-payments/{$obligation->id}/status", ['paid' => true])->assertStatus(422);
        $this->assertEquals($paidBefore + 25000, (float) $order->fresh()->paid_amount, 'no second application');
        $this->assertSame(3, $this->regularPaid($order));
    }

    /** F03 — direct "paid" first, then the pending transfer can no longer be verified onto the same obligation. */
    public function test_f03_direct_paid_then_transfer_verification_is_refused(): void
    {
        [$order, $obligation, $tx, , $keuangan, $konsumen] = $this->settledOrderWithTransferAdditional();
        $svc = app(PaymentService::class);
        $paidBefore = (float) $order->paid_amount;
        $verification = $svc->submitBankTransferProof($tx, UploadedFile::fake()->image('p.jpg'), $konsumen);

        $this->actingAs($keuangan)->patchJson("/api/v1/admin/additional-payments/{$obligation->id}/status", ['paid' => true])->assertOk();
        $this->assertSame('paid', $tx->fresh()->status, 'the linked transaction is closed with the obligation');
        $this->assertSame('verified', $verification->fresh()->status, 'no stale pending proof is left');
        $this->assertEquals($paidBefore + 25000, (float) $order->fresh()->paid_amount);

        try {
            $svc->verifyBankTransfer($verification->fresh(), $keuangan, true);
            $this->fail('a settled transaction must not be verified again');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status());
        }
        $this->assertEquals($paidBefore + 25000, (float) $order->fresh()->paid_amount);
        $this->assertSame(1, OrderAdditionalPayment::where('order_id', $order->id)->where('status', 'paid')->count());
    }

    public function test_short_verified_additional_keeps_residual_outside_regular_payments_and_can_finish_by_transfer(): void
    {
        [$order, $obligation, $tx, , $keuangan, $konsumen] = $this->settledOrderWithTransferAdditional();
        $svc = app(PaymentService::class);
        $paidBefore = (float) $order->paid_amount;
        $proof = $svc->submitBankTransferProof($tx, UploadedFile::fake()->image('partial.jpg'), $konsumen);
        $svc->verifyBankTransfer($proof, $keuangan, true, null, 10000, 'uang diterima sebagian');

        $this->assertSame('pending', $obligation->fresh()->status);
        $this->assertEquals(15000, $obligation->fresh()->amount);
        $this->assertEquals(25000, OrderAdditionalPayment::where('order_id', $order->id)->sum('amount'));
        $this->assertDatabaseHas('order_additional_payments', ['order_id' => $order->id, 'payment_transaction_id' => $tx->id, 'amount' => 10000, 'status' => 'paid']);
        $this->assertEquals(25000, $tx->fresh()->amount, 'submitted transaction history stays immutable');
        $this->assertEquals(10000, $proof->fresh()->verified_amount);
        $this->assertEquals($paidBefore + 10000, $order->fresh()->paid_amount);
        $summary = \App\Services\Payment\PaymentSummaryService::summarize($order->fresh());
        $this->assertEquals(15000, $summary['additional_payment_amount']);
        $this->assertEquals(15000, $summary['remaining_balance']);
        $this->assertSame(3, $summary['regular_payments_count']);
        $residual = PaymentTransaction::findOrFail($obligation->fresh()->payment_transaction_id);
        $this->assertNotSame($tx->id, $residual->id);
        $this->assertSame('additional_payment', $residual->type);
        $this->assertEquals(15000, $residual->amount);
        $this->assertSame('pending', $residual->status);
        $this->assertSame($tx->id, $residual->raw_payload['residual_of']);
        $linkedItem = OrderItem::where('order_id', $order->id)->where('additional_payment_id', $obligation->id)->firstOrFail();
        $this->assertSame('pending_additional_payment', app(\App\Services\Order\OrderFulfillmentService::class)->cancellationBlocker($order->fresh(), $linkedItem));
        $this->assertSame(1, ActivityLog::where('event', 'order_additional_payment.partially_settled')->where('subject_id', $obligation->id)->count());
        $snapshot = \App\Models\OrderInvoiceSnapshot::where('order_id', $order->id)->latest('version')->firstOrFail();
        $this->assertEquals($paidBefore + 10000, $snapshot->verified_paid_amount);
        $txCount = PaymentTransaction::where('order_id', $order->id)->count();
        try {
            $svc->verifyBankTransfer($proof->fresh(), $keuangan, true, null, 10000, 'ulang');
            $this->fail('a retry must not apply money or create another residual');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status());
        }
        $this->assertSame($txCount, PaymentTransaction::where('order_id', $order->id)->count());
        $this->assertEquals($paidBefore + 10000, $order->fresh()->paid_amount);

        // A second short verification keeps the same item/obligation and creates only its new residual.
        $this->actingAs($konsumen)->postJson("/api/v1/orders/{$order->id}/payment/proof", ['proof' => UploadedFile::fake()->image('second.jpg')])->assertOk();
        $proof2 = $residual->bankTransferVerification()->firstOrFail();
        $svc->verifyBankTransfer($proof2, $keuangan, true, null, 5000, 'pembayaran kedua sebagian');
        $this->assertEquals(10000, $obligation->fresh()->amount);
        $last = PaymentTransaction::findOrFail($obligation->fresh()->payment_transaction_id);
        $proof3 = $svc->submitBankTransferProof($last, UploadedFile::fake()->image('final.jpg'), $konsumen);
        $svc->verifyBankTransfer($proof3, $keuangan, true);
        $this->assertSame('paid', $obligation->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertEquals($paidBefore + 25000, $order->fresh()->paid_amount);
        $this->assertSame(3, $this->regularPaid($order));
        $this->assertSame(3, OrderAdditionalPayment::where('order_id', $order->id)->count());
        $this->assertEquals(25000, OrderAdditionalPayment::where('order_id', $order->id)->where('status', 'paid')->sum('amount'));
    }

    public function test_short_additional_residual_can_be_settled_directly_without_collecting_the_original_amount_again(): void
    {
        [$order, $obligation, $tx, , $keuangan, $konsumen] = $this->settledOrderWithTransferAdditional();
        $svc = app(PaymentService::class);
        $paidBefore = (float) $order->paid_amount;
        $proof = $svc->submitBankTransferProof($tx, UploadedFile::fake()->image('partial.jpg'), $konsumen);
        $svc->verifyBankTransfer($proof, $keuangan, true, null, 10000, 'uang diterima sebagian');
        $residualId = $obligation->fresh()->payment_transaction_id;
        $pendingProof = $svc->submitBankTransferProof(PaymentTransaction::findOrFail($residualId), UploadedFile::fake()->image('residual.jpg'), $konsumen);
        $this->actingAs($keuangan)->patchJson("/api/v1/admin/additional-payments/{$obligation->id}/status", ['paid' => true])->assertOk();
        $this->assertEquals($paidBefore + 25000, $order->fresh()->paid_amount);
        $this->assertSame('paid', PaymentTransaction::findOrFail($residualId)->status);
        $this->assertSame('verified', $pendingProof->fresh()->status);
        $this->assertEquals(15000, $pendingProof->fresh()->verified_amount);
        $this->assertEquals(10000, $proof->fresh()->verified_amount);
        $this->assertSame('paid', $obligation->fresh()->status);
        $this->actingAs($keuangan)->patchJson("/api/v1/admin/additional-payments/{$obligation->id}/status", ['paid' => true])->assertStatus(422);
        $this->assertEquals($paidBefore + 25000, $order->fresh()->paid_amount);
        $this->assertSame(3, $this->regularPaid($order));
    }

    /** F09 — every balance-changing event appends exactly one balance snapshot to the single invoice. */
    public function test_f09_invoice_balance_history_follows_every_money_event_without_reissuing(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'keuangan' => $keuangan, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $a = $this->makeProduct($agen, 'Kue A', 40000, 20);
        $b = $this->makeProduct($agen, 'Kue B', 25000, 20);
        $order = $this->placeOrder($konsumen, [['product_id' => $a->id, 'quantity' => 2]]);
        $issuedAt = $order->fresh()->invoice_issued_at;
        $this->assertNotNull($issuedAt, 'COD invoice issued at creation');
        $versions = fn () => \App\Models\OrderInvoiceSnapshot::where('order_id', $order->id)->count();
        $v0 = $versions();

        $order = $this->settleWithThreeRegularPayments($order, $admin, $konsumen); // partial, partial, settle
        $this->assertGreaterThanOrEqual($v0 + 3, $versions(), 'each COD collection records a balance');
        $v1 = $versions();

        $this->addLine($order, $admin, ['product_id' => $b->id, 'quantity' => 1, 'reason' => 'tambah', 'additional_payment_method' => 'cod'])->assertCreated();
        $this->assertGreaterThan($v1, $versions(), 'SC-03 total change');
        $v2 = $versions();
        $obligation = OrderAdditionalPayment::where('order_id', $order->id)->firstOrFail();
        $this->actingAs($keuangan)->patchJson("/api/v1/admin/additional-payments/{$obligation->id}/status", ['paid' => true])->assertOk();
        $this->assertGreaterThan($v2, $versions(), 'additional payment settlement');

        $last = \App\Models\OrderInvoiceSnapshot::where('order_id', $order->id)->orderByDesc('version')->first();
        $o = $order->fresh();
        $this->assertEquals([(float) $o->total_amount, (float) $o->paid_amount, 'paid'], [(float) $last->total_amount, (float) $last->verified_paid_amount, $last->payment_status]);
        $this->assertEquals($issuedAt, $o->invoice_issued_at, 'one invoice, never re-issued');
        $this->assertSame($versions(), \App\Models\OrderInvoiceSnapshot::where('order_id', $order->id)->distinct('version')->count('version'), 'no duplicate versions');
    }
}
