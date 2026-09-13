<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\ConfirmCodPaymentRequest;
use App\Http\Requests\Payment\MarkCodPaymentRequest;
use App\Http\Requests\Payment\StoreBankTransferProofRequest;
use App\Http\Requests\Payment\StoreCodPaymentProofRequest;
use App\Http\Requests\Payment\VerifyBankTransferRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PaymentTransactionResource;
use App\Models\CodPaymentProof;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\Payment\PaymentService;
use Illuminate\Http\Request;

/**
 * Post-order payment actions — proof upload (konsumen) and KEUANGAN
 * verification/settlement for the 'manual' (bank transfer / DP) sub-flow
 * (Blueprint: "Manual transfer membutuhkan verification"). COD/gateway orders
 * never reach the proof endpoints.
 *
 * Separation of duties: every financial write here (verify, markCod,
 * confirmCodProof) is authorized to KEUANGAN + super_admin only — ADMIN may
 * not validate payments (it owns transaction operations instead). The route
 * middleware enforces the same thing first; this is the defense-in-depth
 * re-check that additionally pins the actor to the order's own branch.
 */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $paymentService) {}

    public function submitProof(StoreBankTransferProofRequest $request, Order $order)
    {
        $this->authorize('view', $order);
        $this->assertOwnedByActor($request, $order);

        $transaction = $this->latestManualTransaction($order);

        $this->paymentService->submitBankTransferProof($transaction, $request->file('proof'));

        return $this->ok([
            'transaction' => new PaymentTransactionResource($transaction->fresh('bankTransferVerification')),
        ], __('messages.payment.proof_submitted'));
    }

    public function verify(VerifyBankTransferRequest $request, Order $order)
    {
        $actor = $request->user();

        if (! $actor->isRole('super_admin') && ! ($actor->isRole('keuangan') && $order->agent_id === $actor->agent_id)) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        $transaction = $this->latestManualTransaction($order);
        $verification = $transaction->bankTransferVerification;

        if (! $verification || $verification->status !== 'pending') {
            throw new ApiException(__('messages.payment.already_verified'), 422);
        }

        $verification = $this->paymentService->verifyBankTransfer(
            $verification,
            $actor,
            $request->boolean('approved'),
            $request->input('rejection_reason'),
        );

        return $this->ok([
            'transaction' => new PaymentTransactionResource($transaction->fresh('bankTransferVerification')),
        ], $request->boolean('approved') ? __('messages.payment.verified') : __('messages.payment.rejected'));
    }

    /**
     * "Status pembayaran COD hanya dapat diubah oleh Admin sesuai permission" —
     * only that branch's agen/admin (or super_admin) may call this; there is
     * no other endpoint that ever writes orders.payment_status for a COD order.
     */
    public function markCod(MarkCodPaymentRequest $request, Order $order)
    {
        $actor = $request->user();

        if (! $actor->isRole('super_admin') && ! ($actor->isRole('keuangan') && $order->agent_id === $actor->agent_id)) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        if ($order->paymentMethod?->type !== 'cod') {
            throw new ApiException(__('messages.payment.order_not_cod'), 422);
        }

        $order = $this->paymentService->markCod($order, $actor, $request->boolean('paid'));
        $order->load(['konsumen', 'sales', 'korsal', 'shipments.courier.user', 'shipments.proof']);

        return $this->ok(new OrderResource($order), __('messages.payment.cod_status_updated'));
    }

    /** Konsumen submits a photo of the cash handed to the kurir, requesting Admin/Agen mark the COD order as paid in full. */
    public function submitCodProof(StoreCodPaymentProofRequest $request, Order $order)
    {
        $this->authorize('view', $order);
        $this->assertOwnedByActor($request, $order);

        if ($order->paymentMethod?->type !== 'cod') {
            throw new ApiException(__('messages.payment.order_not_cod'), 422);
        }

        $transaction = $this->latestCodTransaction($order);
        $proof = $this->paymentService->submitCodPaymentProof($transaction, $request->file('proof'), $request->user());

        return $this->ok([
            'transaction' => new PaymentTransactionResource($transaction->fresh('codPaymentProof.proof')),
        ], __('messages.payment.cod_proof_submitted'));
    }

    /** Admin/Agen confirms (or rejects) a konsumen's COD proof — the only thing that ever actually flips a COD order to 'paid' from this flow. */
    public function confirmCodProof(ConfirmCodPaymentRequest $request, CodPaymentProof $codPaymentProof)
    {
        $actor = $request->user();
        $order = $codPaymentProof->paymentTransaction->order;

        if (! $actor->isRole('super_admin') && ! ($actor->isRole('keuangan') && $order->agent_id === $actor->agent_id)) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        if ($codPaymentProof->status !== 'pending') {
            throw new ApiException(__('messages.payment.cod_proof_already_processed'), 422);
        }

        $this->paymentService->confirmCodPayment(
            $codPaymentProof, $actor, $request->boolean('confirmed'), $request->input('rejection_reason')
        );

        $order = $order->fresh(['items', 'konsumen', 'sales', 'korsal', 'paymentMethod', 'paymentTransactions.codPaymentProof', 'shipments.courier.user', 'shipments.proof']);

        return $this->ok(new OrderResource($order), __('messages.payment.cod_proof_confirmed'));
    }

    /**
     * Keuangan asks for settlement of a DP order's outstanding balance. This
     * only *creates* the settlement transaction; the konsumen then uploads a
     * second proof through /payment/proof and Keuangan verifies it, which is
     * what finally flips the order to PAID (remaining_amount = 0).
     */
    public function settle(Request $request, Order $order)
    {
        $actor = $request->user();

        if (! $actor->isRole('super_admin') && ! ($actor->isRole('keuangan') && $order->agent_id === $actor->agent_id)) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        if ($order->paymentMethod?->code !== 'down_payment') {
            throw new ApiException(__('messages.payment.not_dp_order'), 422);
        }

        $transaction = $this->paymentService->requestSettlement($order, $actor);

        return $this->ok([
            'transaction' => new PaymentTransactionResource($transaction),
            'order' => new OrderResource($order->fresh([
                'items', 'konsumen', 'sales', 'korsal', 'paymentMethod',
                'paymentTransactions.bankTransferVerification', 'shipments.courier.user', 'shipments.proof',
            ])),
        ], __('messages.payment.settlement_requested'));
    }

    /** Only the konsumen who owns the order, or that branch's agen/admin/keuangan, may act on its payment. */
    private function assertOwnedByActor(Request $request, Order $order): void
    {
        $actor = $request->user();

        $allowed = $actor->id === $order->konsumen_id
            || ($actor->isRole('agen', 'admin', 'keuangan') && $actor->agent_id === $order->agent_id)
            || $actor->isRole('super_admin');

        if (! $allowed) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }
    }

    private function latestManualTransaction(Order $order): PaymentTransaction
    {
        // Ordered by id, not created_at: a DP order has several manual
        // transactions (the DP itself, then the pelunasan/settlement), which
        // are typically created within the same second — created_at ordering
        // would be ambiguous and could resurface an already-settled one.
        $transaction = $order->paymentTransactions()
            ->whereHas('paymentMethod', fn ($q) => $q->where('type', 'manual'))
            ->orderByDesc('id')
            ->first();

        if (! $transaction) {
            throw new ApiException(__('messages.payment.order_not_awaiting_proof'), 422);
        }

        return $transaction;
    }

    private function latestCodTransaction(Order $order): PaymentTransaction
    {
        $transaction = $order->paymentTransactions()
            ->whereHas('paymentMethod', fn ($q) => $q->where('type', 'cod'))
            ->orderByDesc('id')
            ->first();

        if (! $transaction) {
            throw new ApiException(__('messages.payment.cod_proof_not_found'), 422);
        }

        return $transaction;
    }
}
