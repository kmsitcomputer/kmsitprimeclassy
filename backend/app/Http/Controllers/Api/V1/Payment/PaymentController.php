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
use App\Models\User;
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
        $this->authorizeProofSubmission($request, $order);

        $transaction = $this->latestManualTransaction($order);

        $this->paymentService->submitBankTransferProof($transaction, $request->file('proof'), $request->user(), $this->resolvePaidBy($request));

        return $this->ok([
            'transaction' => new PaymentTransactionResource($transaction->fresh('bankTransferVerification')),
        ], __('messages.payment.proof_submitted'));
    }

    public function verify(VerifyBankTransferRequest $request, Order $order)
    {
        $actor = $request->user();

        // UAT-008: Keuangan/super_admin keep their existing authority; an
        // in-scope Sales/Korsal/Sales-Kurir-Sub may additionally verify the
        // required payment/pelunasan for orders inside their own legitimate
        // scope (OrderPolicy::payOnBehalf — current referral chain,
        // same-Agent). Scope stays server-authoritative; the canonical
        // PaymentService::verifyBankTransfer (locked, idempotent,
        // approve/reject) is reused, never duplicated.
        $this->assertMayVerifyPayment($actor, $order);

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

    /** Konsumen submits a photo of the cash handed to the kurir, requesting Admin/Agen mark the COD order as paid in full. */    public function submitCodProof(StoreCodPaymentProofRequest $request, Order $order)
    {
        $this->authorizeProofSubmission($request, $order);

        if ($order->paymentMethod?->type !== 'cod') {
            throw new ApiException(__('messages.payment.order_not_cod'), 422);
        }

        $transaction = $this->latestCodTransaction($order);
        $proof = $this->paymentService->submitCodPaymentProof($transaction, $request->file('proof'), $request->user(), $this->resolvePaidBy($request));

        return $this->ok([
            'transaction' => new PaymentTransactionResource($transaction->fresh('codPaymentProof.proof')),
        ], __('messages.payment.cod_proof_submitted'));
    }

    /** Admin/Agen confirms (or rejects) a konsumen's COD proof — the only thing that ever actually flips a COD order to 'paid' from this flow. */
    public function confirmCodProof(ConfirmCodPaymentRequest $request, CodPaymentProof $codPaymentProof)
    {
        $actor = $request->user();
        $order = $codPaymentProof->paymentTransaction->order;

        // UAT-008: same scoped authority as verify() — the proof-based
        // approve/reject workflow is reused, never duplicated.
        $this->assertMayVerifyPayment($actor, $order);

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

        // UAT-008: pelunasan request follows the same scoped authority as
        // verification (it only *creates* the settlement transaction; the
        // money still moves only through verifyBankTransfer()).
        $this->assertMayVerifyPayment($actor, $order);

        if ($order->paymentMethod?->code !== 'down_payment') {
            throw new ApiException(__('messages.payment.not_dp_order'), 422);
        }

        [$transaction, $wasReplay] = $this->paymentService->requestSettlement($order, $actor);

        return $this->ok([
            'transaction' => new PaymentTransactionResource($transaction),
            // A retry of an equivalent request is not a new creation, so the HTTP status stays 200
            // for both; `replay` tells the caller which happened without a status change.
            'replay' => $wasReplay,
            'order' => new OrderResource($order->fresh([
                'items', 'konsumen', 'sales', 'korsal', 'paymentMethod',
                'paymentTransactions.bankTransferVerification', 'shipments.courier.user', 'shipments.proof',
            ])),
        ], $wasReplay ? __('messages.payment.settlement_replayed') : __('messages.payment.settlement_requested'));
    }

    /**
     * Payment verification authority — who may approve/reject a
     * payment proof or request pelunasan on this order:
     *
     * - super_admin anywhere (existing override);
     * - keuangan of the order's own branch (existing separation of duties);
     * - sales / korsal / sales-kurir-sub are NEVER approvers (they only upload proof).
     *
     * The direct COD paid/unpaid toggle (markCod) deliberately stays
     * keuangan-only: it flips payment state with no proof workflow, so it
     * is not part of the scoped approve/reject path.
     */
    private function assertMayVerifyPayment(User $actor, Order $order): void
    {
        if ($actor->isRole('super_admin')) {
            return;
        }

        if ($actor->isRole('keuangan') && $order->agent_id === $actor->agent_id) {
            return;
        }

        // Business rule (Human, supersedes UAT-008): Sales / Korsal / Sales-Kurir-Sub are NOT payment
        // approvers. They may only SUBMIT proof in scope (authorizeProofSubmission).
        throw new ApiException(__('messages.system.unauthorized_action'), 403);
    }

    /**
     * Who may submit a payment proof: the konsumen who owns the order, that branch's agen/admin/keuangan,
     * super_admin — and (IMP-001) a Korsal/Sales/Sales-Kurir-Sub paying ON BEHALF of a konsumen inside their
     * own current referral scope (OrderPolicy::payOnBehalf). Those roles are authorized ONLY through that
     * policy, never through the order snapshot or the konsumen path.
     */
    private function authorizeProofSubmission(Request $request, Order $order): void
    {
        if ($request->user()->isRole('korsal', 'sales', 'sales-kurir-sub') && $request->user()->id !== $order->konsumen_id) {
            if (! $request->user()->can('payOnBehalf', $order)) {
                throw new ApiException(__('messages.payment.on_behalf_forbidden'), 403);
            }

            return;
        }

        $this->authorize('view', $order);
        $this->assertOwnedByActor($request, $order);
    }

    /**
     * PAID BY (Pembayar) — who actually supplied the money: konsumen | sales | korsal. Distinct from the
     * proof uploader (the authenticated actor) and the verifier. Default konsumen. A Sales/Sales-Kurir-Sub
     * may only claim 'sales', a Korsal only 'korsal', everyone else only 'konsumen' (422 otherwise).
     */
    private function resolvePaidBy(Request $request): string
    {
        $paidBy = $request->input('paid_by', 'konsumen');
        $actor = $request->user();
        $allowed = ['konsumen'];
        if ($actor->isRole('sales', 'sales-kurir-sub')) {
            $allowed[] = 'sales';
        } elseif ($actor->isRole('korsal')) {
            $allowed[] = 'korsal';
        }
        if (! is_string($paidBy) || ! in_array($paidBy, $allowed, true)) {
            throw new ApiException(__('messages.system.validation_failed'), 422, ['paid_by' => __('messages.system.field_invalid')]);
        }

        return $paidBy;
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
