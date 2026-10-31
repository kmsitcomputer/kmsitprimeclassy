<?php

namespace App\Services\Payment;

use App\Enums\PaymentTransactionStatus;
use App\Exceptions\ApiException;
use App\Models\AgentPaymentGatewayConfig;
use App\Models\AgentPaymentMethodSetting;
use App\Models\BankTransferVerification;
use App\Models\CodPaymentProof;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Invoice\InvoiceIssuanceService;
use App\Services\Logging\ActivityLogger;
use App\Services\Media\MediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only place a PaymentTransaction is created or has its status changed.
 * Behavior branches on PaymentMethod::type exactly the way the checkout flow
 * needs to differ:
 *   - cod: nothing to configure, collected on delivery — starts 'unpaid' at
 *     the ORDER level; only markCod() (Admin/Agen, server-authorized) may
 *     ever flip it to 'paid'. The order itself can advance to 'diproses'
 *     immediately (Order::requiresPaymentBeforeProcessing() is false for cod).
 *   - manual (bank transfer): requires Super Admin to have configured a
 *     destination account first — never fabricates one. A submitted proof
 *     puts the order at 'pending_verification'; only an explicit
 *     server-authorized verification can move it to 'paid'/'failed'.
 *     The order cannot advance to 'diproses' until then.
 */
class PaymentService
{
    /** Legacy proofs live on this PUBLIC disk (kept readable); NEW proofs use PRIVATE_DISK. */
    private const DISK = 'public';

    /** Private disk (storage/app) — served only through the authorized proof endpoint. */
    public const PRIVATE_DISK = 'local';

    private const PROOF_DIRECTORY = 'payments/bank-transfer-proofs';

    /**
     * Canonical persisted marker of a DP settlement request. Stored in the transaction's
     * `raw_payload` exactly as the pre-existing settlement payload did, so historical rows keep
     * identifying themselves; it is what distinguishes a settlement from the DP transaction and
     * from an ordinary bank transfer on the same order.
     */
    private const SETTLEMENT_NOTE = 'dp_settlement';

    public function __construct(
        private readonly MediaService $mediaService,
    ) {}

    public function initiate(Order $order, PaymentMethod $method): PaymentTransaction
    {
        // DP is a 'manual' method, but its first transaction covers only the
        // dp_amount — not the order total — so branch on the code before the
        // generic type match. (The later pelunasan/settlement is an ordinary
        // bank_transfer transaction; see requestSettlement.)
        if ($method->code === 'down_payment') {
            return $this->initiateDownPayment($order, $method);
        }

        return match ($method->type) {
            'cod' => $this->initiateCod($order, $method),
            'manual' => $this->initiateManual($order, $method),
            default => throw new ApiException(__('messages.payment.unsupported_method_type', ['type' => $method->type]), 422),
        };
    }

    private function initiateCod(Order $order, PaymentMethod $method): PaymentTransaction
    {
        return PaymentTransaction::create([
            'order_id' => $order->id,
            'payment_method_id' => $method->id,
            'type' => 'payment',
            'amount' => $order->total_amount,
            'status' => PaymentTransactionStatus::PENDING->value,
            'raw_payload' => ['note' => 'cash_on_delivery'],
        ]);
    }

    private function initiateManual(Order $order, PaymentMethod $method): PaymentTransaction
    {
        $config = $this->activeConfigFor($method, $order->agent_id);

        return PaymentTransaction::create([
            'order_id' => $order->id,
            'payment_method_id' => $method->id,
            'type' => 'payment',
            'amount' => $order->total_amount,
            'status' => PaymentTransactionStatus::PENDING->value,
            'gateway_reference' => 'MT-'.strtoupper(Str::random(10)),
            'raw_payload' => [
                'bank_name' => $config->config['bank_name'] ?? null,
                'account_name' => $config->config['account_name'] ?? null,
                'account_number' => $config->config['account_number'] ?? null,
            ],
        ]);
    }

    /**
     * DP / down payment — the konsumen transfers a partial nominal and uploads
     * a proof; Keuangan verifies it. The destination account is the branch's
     * existing bank_transfer account (DP has no separate bank config), and the
     * transaction amount is the DP nominal, never the order total. The
     * outstanding balance stays owed until a later settlement.
     */
    private function initiateDownPayment(Order $order, PaymentMethod $method): PaymentTransaction
    {
        $config = $this->activeConfigForCode('bank_transfer', $order->agent_id);

        return PaymentTransaction::create([
            'order_id' => $order->id,
            'payment_method_id' => $method->id,
            'type' => 'payment',
            'amount' => $order->dp_amount,
            'status' => PaymentTransactionStatus::PENDING->value,
            'gateway_reference' => 'DP-'.strtoupper(Str::random(10)),
            'raw_payload' => [
                'bank_name' => $config->config['bank_name'] ?? null,
                'account_name' => $config->config['account_name'] ?? null,
                'account_number' => $config->config['account_number'] ?? null,
                'note' => 'down_payment',
            ],
        ]);
    }

    /**
     * "Additional payment dapat: transfer, COD" — for a quantity increase
     * during fulfillment (see OrderFulfillmentService). 'transfer' creates a
     * real PaymentTransaction the konsumen must pay + submit proof for, same
     * as the manual-transfer order flow; 'cod' has nothing to configure —
     * it's marked paid directly by Admin/Agen once collected.
     */
    public function initiateAdditionalPayment(Order $order, string $method, float $amount): ?PaymentTransaction
    {
        if ($method === 'cod') {
            return null;
        }

        $bankTransferMethod = PaymentMethod::query()->where('code', 'bank_transfer')->firstOrFail();
        $config = $this->activeConfigFor($bankTransferMethod, $order->agent_id);

        return PaymentTransaction::create([
            'order_id' => $order->id,
            'payment_method_id' => $bankTransferMethod->id,
            'type' => 'additional_payment',
            'amount' => $amount,
            'status' => PaymentTransactionStatus::PENDING->value,
            'gateway_reference' => 'AP-'.strtoupper(Str::random(10)),
            'raw_payload' => [
                'bank_name' => $config->config['bank_name'] ?? null,
                'account_name' => $config->config['account_name'] ?? null,
                'account_number' => $config->config['account_number'] ?? null,
            ],
        ]);
    }

    private function activeConfigFor(PaymentMethod $method, int $agentId): AgentPaymentGatewayConfig
    {
        $environment = AgentPaymentMethodSetting::query()
            ->where('agent_id', $agentId)->where('payment_method_id', $method->id)
            ->value('active_environment') ?? 'sandbox';

        $config = AgentPaymentGatewayConfig::query()
            ->where('agent_id', $agentId)
            ->where('payment_method_id', $method->id)
            ->where('environment', $environment)
            ->first();

        if (! $config) {
            throw new ApiException(__('messages.payment.method_not_configured', ['name' => $method->name]), 422);
        }

        return $config;
    }

    /** Resolve a configuration row by the method's code — used by DP, which reuses the branch's bank_transfer account. */
    private function activeConfigForCode(string $code, int $agentId): AgentPaymentGatewayConfig
    {
        return $this->activeConfigFor(PaymentMethod::query()->where('code', $code)->firstOrFail(), $agentId);
    }

    /**
     * Proof of a completed bank transfer, submitted by the konsumen OR — IMP-001 — by an authorized payer
     * acting on the konsumen's behalf (Korsal/Sales in scope, or branch staff). Snapshots the destination
     * bank details as they were on the transaction at initiate() time (raw_payload), not whatever the
     * config holds now. Never pays the order itself — only verifyBankTransfer() (Keuangan) does.
     *
     * `$submittedBy` is recorded as the payer actor (`submitted_by_user_id`); the order owner is never
     * changed. Lock order: verification row → ORDER (row lock) → transaction. Taking the ORDER row lock
     * (same row verifyBankTransfer and OrderService::cancel serialize on) closes the audit finding that
     * a concurrent cancellation could land between the eligibility read and the proof write — the
     * cancelled-order rejection now holds against a real concurrent cancel() (OrderService::cancel
     * re-reads the Order with the same lockForUpdate before acting).
     */
    public function submitBankTransferProof(PaymentTransaction $transaction, UploadedFile $proof, ?User $submittedBy = null, string $paidBy = 'konsumen'): BankTransferVerification
    {
        return DB::transaction(function () use ($transaction, $proof, $submittedBy, $paidBy) {
            $existing = BankTransferVerification::query()
                ->where('payment_transaction_id', $transaction->id)->lockForUpdate()->first();
            // Locking re-read: serialize against OrderService::cancel() and verifyBankTransfer()
            // so the eligibility decision is made on the CURRENT persisted row, never a stale snapshot.
            $current = PaymentTransaction::query()->findOrFail($transaction->id);
            $order = Order::query()->whereKey($current->order_id)->lockForUpdate()->firstOrFail();

            if ($order->status === 'dibatalkan'
                || $current->status === PaymentTransactionStatus::PAID->value
                || $existing?->status === 'verified') {
                throw new ApiException(__('messages.payment.proof_locked'), 422);
            }

            $onBehalf = $submittedBy !== null && $submittedBy->id !== $order->konsumen_id;
            $path = $proof->store(self::PROOF_DIRECTORY, self::PRIVATE_DISK);
            $payload = $current->raw_payload ?? [];

            $verification = BankTransferVerification::updateOrCreate(
                ['payment_transaction_id' => $current->id],
                [
                    'bank_name' => $payload['bank_name'] ?? '-',
                    'account_name' => $payload['account_name'] ?? '-',
                    'account_number' => $payload['account_number'] ?? '-',
                    'proof_image_path' => $path,
                    'proof_disk' => self::PRIVATE_DISK,
                    'submitted_amount' => $current->amount,
                    'status' => 'pending',
                    'verified_by' => null,
                    'verified_at' => null,
                    'rejection_reason' => null,
                    'submitted_by_user_id' => $submittedBy?->id,
                    'submitted_on_behalf' => $onBehalf,
                    'paid_by_role' => $paidBy,
                    'submitted_at' => now(),
                ]
            );

            $order->update(['payment_status' => 'pending_verification']);

            if ($submittedBy !== null) {
                ActivityLogger::log($submittedBy->id, $order, 'payment.proof_submitted', null, [
                    'actor_role' => $submittedBy->role?->slug,
                    'on_behalf_of_konsumen_id' => $onBehalf ? $order->konsumen_id : null,
                    'submitted_on_behalf' => $onBehalf,
                    'paid_by' => $paidBy,
                    'payment_transaction_id' => $current->id,
                    'amount' => (float) $current->amount,
                    'payment_method' => $current->paymentMethod?->code,
                    'order_payment_status' => 'pending_verification',
                ]);
            }

            return $verification;
        });
    }

    /**
     * Server-policy-authorized action (currently Super Admin/Keuangan) — the sole way a manual transfer becomes paid.
     *
     * Concurrency-hardened (audit finding): the verification row is LOCKED and re-read inside the
     * transaction, and the order row lock is taken on the same row OrderService::cancel serializes on.
     * Two requests that both passed the controller's pending check cannot both apply the money: the
     * second one re-reads the (now non-pending) persisted row under the lock and refuses — the amount
     * is applied exactly once.
     */
    public function verifyBankTransfer(BankTransferVerification $verification, User $actor, bool $approved, ?string $rejectionReason = null, ?float $verifiedAmount = null, ?string $adjustmentReason = null): BankTransferVerification
    {
        return DB::transaction(function () use ($verification, $actor, $approved, $rejectionReason, $verifiedAmount, $adjustmentReason) {
            // Locking re-read of the verification row: serialize on the SAME row the proof
            // submission (and a concurrent verify) already locks, so a stale in-memory model
            // can never be double-applied. Repeated processing of an already-finalized
            // verification has no financial effect.
            $verification = BankTransferVerification::query()
                ->whereKey($verification->id)->lockForUpdate()->firstOrFail();

            if ($verification->status !== 'pending') {
                throw new ApiException(__('messages.payment.already_verified'), 422);
            }

            // Separation of duties: whoever submitted the record or received the cash can never approve it.
            if ($approved && in_array($actor->id, array_filter([$verification->submitted_by_user_id, $verification->cash_received_by_user_id]), true)) {
                throw new ApiException(__('messages.payment.self_verification_forbidden'), 403);
            }

            $transaction = PaymentTransaction::query()->findOrFail($verification->payment_transaction_id);
            // Locking re-read of the ORDER too — same row cancel() locks — so a concurrent
            // cancellation cannot race between the money application and its status write.
            $order = Order::query()->whereKey($transaction->order_id)->lockForUpdate()->firstOrFail();

            // The submitted nominal is preserved untouched (payment_transactions.amount); what Keuangan
            // actually confirmed is stored separately. Validation runs BEFORE any write.
            $submitted = round((float) $transaction->amount, 2);
            $applied = $submitted;
            if ($approved && $verifiedAmount !== null) {
                $applied = round($verifiedAmount, 2);
                if ($applied <= 0) {
                    throw new ApiException(__('messages.system.validation_failed'), 422, ['verified_amount' => __('messages.system.field_invalid')]);
                }
                $outstanding = round((float) PaymentSummaryService::summarize($order)['remaining_balance'], 2);
                if ($applied > $outstanding + 0.004) {
                    // The locked Order is the current financial boundary. A submitted
                    // amount is evidence, not authority to exceed what is still owed.
                    throw new ApiException(__('messages.payment.verified_amount_exceeds_remaining'), 422, ['verified_amount' => __('messages.payment.verified_amount_exceeds_remaining')]);
                }
                if ($applied > $submitted + 0.004) {
                    throw new ApiException(__('messages.payment.verified_amount_exceeds_submitted'), 422, ['verified_amount' => __('messages.payment.verified_amount_exceeds_submitted')]);
                }
                if ($applied !== $submitted && trim((string) $adjustmentReason) === '') {
                    throw new ApiException(__('messages.payment.adjustment_reason_required'), 422, ['amount_adjustment_reason' => __('messages.system.field_required')]);
                }
            }

            $verification->update([
                'status' => $approved ? 'verified' : 'rejected',
                'verified_by' => $actor->id,
                'verified_at' => now(),
                'rejection_reason' => $approved ? null : $rejectionReason,
                'submitted_amount' => $verification->submitted_amount ?? $submitted,
                'verified_amount' => $approved ? $applied : null,
                'amount_adjustment_reason' => $approved && $applied !== $submitted ? $adjustmentReason : null,
            ]);

            $transaction->update([
                'status' => $approved ? PaymentTransactionStatus::PAID->value : PaymentTransactionStatus::FAILED->value,
                'paid_at' => $approved ? now() : null,
            ]);

            if ($approved) {
                // Accumulate the VERIFIED amount onto the order. remaining > 0 => 'partially_paid';
                // a DP only ever reaches 'partially_paid' until the outstanding balance is settled.
                $this->applyPaymentToOrder($order, $applied);
            } elseif ((float) $order->paid_amount > 0) {
                // A rejected *settlement* proof on an already partially-paid
                // order must not mark the order 'failed' — it just stops being
                // 'pending_verification' and remains partially paid.
                $this->recalculatePaymentStatus($order);
            } else {
                $order->update(['payment_status' => 'failed']);
            }

            ActivityLogger::log($actor->id, $order, 'payment.bank_transfer_verified', $rejectionReason, [
                'approved' => $approved, 'payment_transaction_id' => $transaction->id,
                'amount' => $submitted,
                'submitted_amount' => $submitted,
                'verified_amount' => $approved ? $applied : null,
                'amount_adjustment_reason' => $approved && $applied !== $submitted ? $adjustmentReason : null,
                'channel' => $verification->channel,
                'paid_amount' => (float) $order->paid_amount,
                'remaining_amount' => (float) $order->remaining_amount,
            ]);

            if ($approved) {
                // First verified payment issues the (single) invoice; later ones only append a snapshot.
                app(InvoiceIssuanceService::class)->syncAfterChange($order, 'payment_verified', $actor->id);
            }

            return $verification->fresh();
        });
    }

    /**
     * The only way a COD order's payment_status ever changes — always an
     * explicit, server-authorized Admin/Agen action (see PaymentController),
     * never something the frontend can trigger on its own initiative.
     */
    public function markCod(Order $order, User $actor, bool $paid): Order
    {
        return DB::transaction(function () use ($order, $actor, $paid) {
            $previous = $order->payment_status;
            // COD is collected in full on delivery: settling marks the whole
            // total paid; un-marking returns the order to owing everything.
            $this->recalculatePaymentStatus($order, $paid ? (float) $order->total_amount : 0.0);

            $transaction = $order->paymentTransactions()->orderByDesc('id')->first();
            $transaction?->update([
                'status' => $paid ? PaymentTransactionStatus::PAID->value : PaymentTransactionStatus::PENDING->value,
                'paid_at' => $paid ? now() : null,
            ]);

            ActivityLogger::log($actor->id, $order, 'payment.cod_status_changed', null, [
                'from' => $previous, 'to' => $order->payment_status,
            ]);

            return $order->fresh(['items', 'paymentMethod', 'paymentTransactions.bankTransferVerification']);
        });
    }

    /**
     * "Konsumen dapat memasukan bukti pembayaran COD dengan memasukan foto
     * pembayaran kepada kurir di tempat, konsumen dapat melakukan request
     * pembayaran lunas ke admin" — submitting this never flips payment_status
     * itself (only confirmCodPayment(), an Admin/Agen action, ever does);
     * this only puts the request in front of them.
     */
    public function submitCodPaymentProof(PaymentTransaction $transaction, UploadedFile $proof, User $actor, string $paidBy = 'konsumen'): CodPaymentProof
    {
        return DB::transaction(function () use ($transaction, $proof, $actor, $paidBy) {
            // Same lock order as confirmCodPayment (proof row → order): a confirmed proof is never overwritten.
            $existing = CodPaymentProof::query()
                ->where('payment_transaction_id', $transaction->id)->lockForUpdate()->first();
            // Locking re-read of the ORDER (the row OrderService::cancel serializes on) — the
            // cancelled/paid eligibility decision must reflect the CURRENT persisted row, never a
            // snapshot taken before a concurrent cancellation committed.
            $order = Order::query()->whereKey($transaction->order_id)->lockForUpdate()->firstOrFail();

            if ($order->status === 'dibatalkan' || $order->payment_status === 'paid' || $existing?->status === 'confirmed') {
                throw new ApiException(__('messages.payment.proof_locked'), 422);
            }

            $onBehalf = $actor->id !== $order->konsumen_id;
            $media = $this->mediaService->store($proof, 'cod_payment_proof', $transaction, $actor);

            $codProof = CodPaymentProof::updateOrCreate(
                ['payment_transaction_id' => $transaction->id],
                [
                    'proof_media_id' => $media->id, 'status' => 'pending',
                    'confirmed_by' => null, 'confirmed_at' => null, 'rejection_reason' => null,
                    'submitted_by_user_id' => $actor->id, 'submitted_on_behalf' => $onBehalf,
                    'paid_by_role' => $paidBy, 'submitted_at' => now(),
                ]
            );

            ActivityLogger::log($actor->id, $order, 'payment.cod_proof_submitted', null, [
                'actor_role' => $actor->role?->slug, 'payment_transaction_id' => $transaction->id,
                'on_behalf_of_konsumen_id' => $onBehalf ? $order->konsumen_id : null,
                'submitted_on_behalf' => $onBehalf, 'paid_by' => $paidBy,
                'amount' => (float) $transaction->amount,
                'payment_method' => $transaction->paymentMethod?->code,
                'order_payment_status' => $order->payment_status,
            ]);

            return $codProof;
        });
    }

    /** Admin/Agen-only (authorized in the controller) — the sole way a COD proof request ever actually marks the order paid. */
    public function confirmCodPayment(CodPaymentProof $proof, User $actor, bool $confirmed, ?string $rejectionReason = null): CodPaymentProof
    {
        return DB::transaction(function () use ($proof, $actor, $confirmed, $rejectionReason) {
            // Locking re-reads: the proof row first (concurrent confirmations serialize here),
            // then the ORDER row (the row OrderService::cancel serializes on) — a cancellation
            // that lands between them cannot be overwritten by this confirmation.
            $proof = CodPaymentProof::query()->whereKey($proof->id)->lockForUpdate()->firstOrFail();
            $transaction = PaymentTransaction::query()->findOrFail($proof->payment_transaction_id);
            $order = Order::query()->whereKey($transaction->order_id)->lockForUpdate()->firstOrFail();

            if ($proof->status !== 'pending') {
                throw new ApiException(__('messages.payment.already_verified'), 422);
            }

            $proof->update([
                'status' => $confirmed ? 'confirmed' : 'rejected',
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
                'rejection_reason' => $confirmed ? null : $rejectionReason,
            ]);

            if ($confirmed) {
                $this->markCod($order, $actor, true);
            }

            ActivityLogger::log($actor->id, $order, 'payment.cod_proof_confirmed', $rejectionReason, [
                'confirmed' => $confirmed, 'actor_role' => $actor->role?->slug,
            ]);

            return $proof->fresh();
        });
    }

/**
 * Keuangan requests settlement of a DP order's outstanding balance at the
 * end of the transaction. Creates an ordinary bank_transfer transaction for
 * exactly remaining_amount; the konsumen then uploads a second proof and
 * Keuangan verifies it through the normal verifyBankTransfer() path.
 *
 * IDEMPOTENT REPLAY (Human decision): a settlement is a request for a specific
 * outstanding amount, so a second, equivalent request for the SAME canonical
 * financial context (same order, same bank_transfer settlement transaction,
 * same nominal as the order's CURRENT remaining balance) must NOT create a
 * second transaction — the existing pending settlement is returned instead
 * (HTTP 200, `replay = true`). It is money-safe by construction: the replay
 * performs no insert, no ledger movement and no status write at all.
 *
 * CONCURRENCY: the whole decision runs inside one transaction that takes the
 * canonical **Order-first** lock — the same boundary verifyBankTransfer /
 * submitBankTransferProof / OrderService::cancel serialize on. Two simultaneous
 * requests therefore queue on the Order row, and the loser re-reads the
 * winner's committed settlement through a LOCKING read (ARCHITECTURE §7: never
 * a plain read that would use the pre-wait snapshot) and converges to the same
 * single pending settlement.
 *
 * MATERIAL MISMATCH: if the order's outstanding balance has MOVED since a
 * settlement was requested, the pending one no longer represents what is owed.
 * It is preserved untouched (never silently replaced — a proof may already
 * exist against it) and the new request is refused with the existing
 * duplicate-obligation rule `messages.payment.already_processed`, the same rule
 * `verifyBankTransfer` / `confirmCodPayment` / `markAdditionalPaymentPaid` use
 * when a pending financial row already occupies the record. Resolving it needs
 * no new workflow: the existing reject/verify action settles the stale request.
 *
 * @return array{0: PaymentTransaction, 1: bool} [transaction, wasReplay]
 */
public function requestSettlement(Order $order, User $actor): array
{
    // A refusal THROWS from inside the transaction, so an audit row written there would be rolled
    // back with it and the refusal would leave no trace. The conflict outcome is therefore decided
    // inside the transaction and reported after it, exactly like the decision it records.
    $conflict = null;

    $result = DB::transaction(function () use ($order, $actor, &$conflict) {
        // Canonical Order-first boundary. Never trust the route-bound model's remaining_amount:
        // it can be arbitrarily stale relative to a concurrent payment or total change.
        $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

        $remaining = round((float) $order->remaining_amount, 2);

        if ($remaining <= 0) {
            throw new ApiException(__('messages.payment.nothing_to_settle'), 422);
        }

        $pending = $this->pendingSettlementFor($order->id);

        if ($pending && round((float) $pending->amount, 2) === $remaining) {
            // Equivalent request for the same canonical financial context: replay, no side effect.
            ActivityLogger::log($actor->id, $order, 'payment.settlement_request_replayed', null, [
                'payment_transaction_id' => $pending->id,
                'amount' => $remaining,
                'remaining_amount' => $remaining,
                'idempotent_replay' => true,
                'actor_role' => $actor->role?->slug,
            ]);

            return [$pending, true];
        }

        if ($pending) {
            // Preserve the pending settlement untouched; refuse the materially different one.
            $conflict = [
                'pending' => $pending,
                'pending_amount' => (float) $pending->amount,
                'requested_amount' => $remaining,
            ];

            return [null, false];
        }

        $method = PaymentMethod::query()->where('code', 'bank_transfer')->firstOrFail();
        $config = $this->activeConfigFor($method, $order->agent_id);

        $transaction = PaymentTransaction::create([
            'order_id' => $order->id,
            'payment_method_id' => $method->id,
            'type' => 'payment',
            'amount' => $remaining,
            'status' => PaymentTransactionStatus::PENDING->value,
            'gateway_reference' => 'ST-'.strtoupper(Str::random(10)),
            'raw_payload' => [
                'bank_name' => $config->config['bank_name'] ?? null,
                'account_name' => $config->config['account_name'] ?? null,
                'account_number' => $config->config['account_number'] ?? null,
                'note' => self::SETTLEMENT_NOTE,
            ],
        ]);

        ActivityLogger::log($actor->id, $order, 'payment.settlement_requested', null, [
            'payment_transaction_id' => $transaction->id,
            'amount' => (float) $transaction->amount,
            'remaining_amount' => $remaining,
            'actor_role' => $actor->role?->slug,
        ]);

        return [$transaction, false];
    });

    if ($conflict !== null) {
        ActivityLogger::log($actor->id, $order, 'payment.settlement_request_rejected', 'A settlement for a different outstanding amount is already pending', [
            'pending_payment_transaction_id' => $conflict['pending']->id,
            'pending_amount' => $conflict['pending_amount'],
            'requested_amount' => $conflict['requested_amount'],
            'actor_role' => $actor->role?->slug,
        ]);

        throw new ApiException(__('messages.payment.already_processed'), 422);
    }

    return $result;
}

/**
 * The order's currently pending settlement request, or null. A settlement is
 * identified by its canonical persisted marker (`raw_payload.note`), not by the
 * `ST-` reference prefix, so a DP transaction or an ordinary bank transfer can
 * never be mistaken for a settlement. Locking read on `order_id` (indexed) —
 * narrow per-order range, and never a pre-wait snapshot.
 */
private function pendingSettlementFor(int $orderId): ?PaymentTransaction
{
    $methodId = PaymentMethod::query()->where('code', 'bank_transfer')->value('id');

    if ($methodId === null) {
        return null;
    }

    return PaymentTransaction::query()
        ->where('order_id', $orderId)
        ->where('payment_method_id', $methodId)
        ->where('type', 'payment')
        ->where('status', PaymentTransactionStatus::PENDING->value)
        ->where('raw_payload->note', self::SETTLEMENT_NOTE)
        ->orderByDesc('id')
        ->lockForUpdate()
        ->first();
}

    /**
     * Improvement 05/06/08 — post-order installment / payoff / cash receipt. Creates ONE pending
     * payment transaction for an explicit nominal; the money reaches the order only when Keuangan
     * verifies it (verifyBankTransfer), so a pending or rejected record never reduces the receivable.
     *
     * Rules (all re-checked under the canonical Order lock, never trusting the route-bound model):
     *  - not COD (COD is collected via the COD proof flow), not cancelled, and the order must already
     *    be in fulfilment (diproses/dikirim/terkirim) — the FIRST payment goes through the checkout flow;
     *  - 0 < amount <= remaining receivable (no silent overpayment);
     *  - at most one unresolved manual record per order (a pending installment with the same nominal is
     *    returned as an idempotent replay; any other pending record refuses with already_processed);
     *  - `cash` additionally records who physically received the money and when; the recorder can never
     *    verify it (see verifyBankTransfer).
     *
     * @param  array{receipt_reference?:?string, received_at?:?string, received_by_user_id?:?int}  $cash
     * @return array{0: PaymentTransaction, 1: bool} [transaction, wasReplay]
     */
    public function requestInstallment(Order $order, User $actor, float $amount, string $channel = 'transfer', array $cash = [], ?UploadedFile $cashPhoto = null): array
    {
        $amount = round($amount, 2);

        return DB::transaction(function () use ($order, $actor, $amount, $channel, $cash, $cashPhoto) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->paymentMethod?->type === 'cod') {
                throw new ApiException(__('messages.payment.installment_not_for_cod'), 422);
            }
            if (! in_array($order->status, ['diproses', 'dikirim', 'terkirim'], true)) {
                throw new ApiException(__('messages.payment.installment_not_allowed_yet'), 422);
            }

            $remaining = round((float) $order->remaining_amount, 2);
            if ($remaining <= 0) {
                throw new ApiException(__('messages.payment.nothing_to_settle'), 422);
            }
            if ($amount <= 0 || $amount > $remaining) {
                throw new ApiException(__('messages.payment.installment_amount_invalid'), 422, ['amount' => __('messages.payment.installment_amount_invalid')]);
            }

            $methodId = PaymentMethod::query()->where('code', 'bank_transfer')->value('id');
            $pending = PaymentTransaction::query()
                ->where('order_id', $order->id)->where('payment_method_id', $methodId)
                ->where('type', 'payment')->where('status', PaymentTransactionStatus::PENDING->value)
                ->orderByDesc('id')->lockForUpdate()->first();

            if ($pending) {
                $sameNominal = round((float) $pending->amount, 2) === $amount
                    && ($pending->raw_payload['note'] ?? null) === 'installment'
                    && ($pending->raw_payload['channel'] ?? 'transfer') === $channel
                    && (int) ($pending->raw_payload['requested_by'] ?? 0) === $actor->id;
                if ($sameNominal) {
                    return [$pending, true];
                }

                throw new ApiException(__('messages.payment.already_processed'), 422);
            }

            $method = PaymentMethod::query()->where('code', 'bank_transfer')->firstOrFail();
            $config = $this->activeConfigFor($method, $order->agent_id);

            $transaction = PaymentTransaction::create([
                'order_id' => $order->id,
                'payment_method_id' => $method->id,
                'type' => 'payment',
                'amount' => $amount,
                'status' => PaymentTransactionStatus::PENDING->value,
                'gateway_reference' => ($channel === 'cash' ? 'CS-' : 'IN-').strtoupper(Str::random(10)),
                'raw_payload' => [
                    'bank_name' => $config->config['bank_name'] ?? null,
                    'account_name' => $config->config['account_name'] ?? null,
                    'account_number' => $config->config['account_number'] ?? null,
                    'note' => 'installment',
                    'channel' => $channel,
                    'requested_by' => $actor->id,
                    'cash' => $channel === 'cash' ? $cash : null,
                ],
            ]);

            if ($channel === 'cash') {
                // The cash receipt is recorded together with the request: who physically received the money
                // and when. It is pending until Keuangan verifies it — the recorder/receiver can never do so.
                BankTransferVerification::create([
                    'payment_transaction_id' => $transaction->id,
                    'bank_name' => 'TUNAI', 'account_name' => '-', 'account_number' => '-',
                    'proof_image_path' => $cashPhoto ? $cashPhoto->store(self::PROOF_DIRECTORY, self::PRIVATE_DISK) : '',
                    'proof_disk' => self::PRIVATE_DISK,
                    'status' => 'pending',
                    'channel' => 'cash',
                    'submitted_amount' => $amount,
                    'submitted_by_user_id' => $actor->id,
                    'submitted_on_behalf' => $actor->id !== $order->konsumen_id,
                    'paid_by_role' => $actor->isRole('korsal') ? 'korsal' : ($actor->isRole('sales', 'sales-kurir-sub') ? 'sales' : 'konsumen'),
                    'submitted_at' => now(),
                    'cash_received_by_user_id' => $cash['received_by_user_id'] ?? $actor->id,
                    'cash_received_at' => $cash['received_at'] ?? now(),
                    'receipt_reference' => $cash['receipt_reference'] ?? null,
                ]);
                $order->update(['payment_status' => 'pending_verification']);
            }

            ActivityLogger::log($actor->id, $order, 'payment.installment_requested', null, [
                'payment_transaction_id' => $transaction->id, 'amount' => $amount, 'channel' => $channel,
                'remaining_amount' => $remaining, 'actor_role' => $actor->role?->slug,
            ]);

            return [$transaction, false];
        });
    }

    /** Per-order due date (Admin/Keuangan). Audited: old value, new value, reason, actor, timestamp. */
    public function setDueDate(Order $order, User $actor, string $dueDate, string $reason): Order
    {
        return DB::transaction(function () use ($order, $actor, $dueDate, $reason) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $new = \Illuminate\Support\Carbon::parse($dueDate)->toDateString();
            $old = $order->due_date?->toDateString();

            if ($old === $new) {
                return $order;
            }

            $order->forceFill(['due_date' => $new])->save();
            \App\Models\OrderDueDateChange::create([
                'order_id' => $order->id, 'old_due_date' => $old, 'new_due_date' => $new,
                'reason' => $reason, 'changed_by' => $actor->id,
            ]);
            ActivityLogger::log($actor->id, $order, 'order.due_date_changed', $reason, ['old' => $old, 'new' => $new]);

            return $order->fresh();
        });
    }

    /**
     * Adds a verified payment amount onto the order's running paid_amount and
     * re-derives remaining/payment_status from it. This is the single place
     * partial-vs-full payment is decided: remaining > 0 => 'partially_paid'
     * (never 'paid'), remaining == 0 => 'paid'.
     */
    public function applyPaymentToOrder(Order $order, float $amount): Order
    {
        return $this->recalculatePaymentStatus($order, (float) $order->paid_amount + $amount);
    }

    /**
     * Money actually leaving the business — a processed refund. Symmetric to
     * applyPaymentToOrder: never lets paid_amount go negative, then re-derives
     * remaining/payment_status the same way.
     */
    public function reverseAppliedPayment(Order $order, float $amount): Order
    {
        return $this->recalculatePaymentStatus($order, max(0.0, (float) $order->paid_amount - $amount));
    }

    /**
     * Re-derives remaining_amount/payment_status against the order's CURRENT
     * total_amount without adding or removing any money — the reconciliation
     * step OrderFulfillmentService calls after OrderTotalCalculator changes
     * total_amount, so a quantity change that doesn't itself involve a new
     * payment still gets its outstanding balance/status updated correctly.
     */
    public function reconcileTotals(Order $order): Order
    {
        return $this->recalculatePaymentStatus($order);
    }

    /**
     * Recompute paid/remaining/payment_status from an explicit paid amount
     * (or the order's current one). paid_amount is deliberately NEVER
     * clamped to total_amount here — capping it would silently fabricate a
     * "payment reduction" that never actually happened as real money
     * movement the moment total_amount shrinks below what was already paid
     * (an item reduction on an already-settled order — see
     * OrderTotalCalculator/OrderFulfillmentService). That state IS a real,
     * legitimate overpayment (Case 3 of the canonical payment formula) and
     * must stay visible as such — only remaining_amount is floored at 0.
     */
    private function recalculatePaymentStatus(Order $order, ?float $paidAmount = null): Order
    {
        $total = round((float) $order->total_amount, 2);
        $current = round((float) $order->paid_amount, 2);
        $paid = round(max(0.0, $paidAmount ?? $current), 2);
        $remaining = round(max(0.0, $total - $paid), 2);

        // Refund ledger: G is "all verified money in" and never decreases on a refund. Only a
        // positive payment delta grows it; the refund path reduces paid_amount alone.
        $gross = round((float) $order->gross_received_amount, 2);
        if ($paid > $current) {
            $gross = round($gross + ($paid - $current), 2);
        }

        $order->update([
            'paid_amount' => $paid,
            'gross_received_amount' => $gross,
            'remaining_amount' => $remaining,
            'payment_status' => $remaining <= 0 ? 'paid' : ($paid > 0 ? 'partially_paid' : 'unpaid'),
        ]);

        return $order->fresh();
    }
}
