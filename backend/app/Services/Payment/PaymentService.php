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
use App\Models\PaymentWebhookLog;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use App\Services\Media\MediaService;
use App\Services\Payment\Gateways\PaymentGatewayClientFactory;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
 *     Admin/Agen verifyBankTransfer() call can move it to 'paid'/'failed'.
 *     The order cannot advance to 'diproses' until then.
 *   - gateway (Xendit/Tripay/Stripe): delegates to the method's
 *     PaymentGatewayClientInterface implementation (see
 *     PaymentGatewayClientFactory) using whichever environment
 *     (sandbox/production) the Super Admin has set as active. The gateway's
 *     webhook is the only thing that ever marks these paid — never the
 *     frontend, never a redirect callback alone.
 */
class PaymentService
{
    private const DISK = 'public';

    private const PROOF_DIRECTORY = 'payments/bank-transfer-proofs';

    public function __construct(
        private readonly PaymentGatewayClientFactory $gatewayFactory,
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
            'gateway' => $this->initiateGateway($order, $method),
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

    private function initiateGateway(Order $order, PaymentMethod $method): PaymentTransaction
    {
        $config = $this->activeConfigFor($method, $order->agent_id);

        $transaction = PaymentTransaction::create([
            'order_id' => $order->id,
            'payment_method_id' => $method->id,
            'type' => 'payment',
            'amount' => $order->total_amount,
            'status' => PaymentTransactionStatus::PENDING->value,
        ]);

        $client = $this->gatewayFactory->make($method->code);
        $result = $client->createPayment($order, $transaction, [...$config->config, 'environment' => $config->environment]);

        $transaction->update([
            'gateway_reference' => $result['reference'],
            'raw_payload' => $result['instructions'],
        ]);

        return $transaction->fresh();
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
     * Konsumen-submitted proof of a completed bank transfer. Snapshots the
     * destination bank details as they were on the transaction at initiate()
     * time (raw_payload), not whatever the config holds now, since an admin
     * could change the destination account between order and payment. Never
     * pays the order itself — only verifyBankTransfer() (Admin/Agen) does.
     */
    public function submitBankTransferProof(PaymentTransaction $transaction, UploadedFile $proof): BankTransferVerification
    {
        return DB::transaction(function () use ($transaction, $proof) {
            $path = $proof->store(self::PROOF_DIRECTORY, self::DISK);
            $payload = $transaction->raw_payload ?? [];

            $verification = BankTransferVerification::updateOrCreate(
                ['payment_transaction_id' => $transaction->id],
                [
                    'bank_name' => $payload['bank_name'] ?? '-',
                    'account_name' => $payload['account_name'] ?? '-',
                    'account_number' => $payload['account_number'] ?? '-',
                    'proof_image_path' => $path,
                    'status' => 'pending',
                    'verified_by' => null,
                    'verified_at' => null,
                    'rejection_reason' => null,
                ]
            );

            $transaction->order->update(['payment_status' => 'pending_verification']);

            return $verification;
        });
    }

    /** Admin/Agen-only action (authorized in the controller) — the sole way a manual transfer ever becomes 'paid'. */
    public function verifyBankTransfer(BankTransferVerification $verification, User $actor, bool $approved, ?string $rejectionReason = null): BankTransferVerification
    {
        return DB::transaction(function () use ($verification, $actor, $approved, $rejectionReason) {
            $verification->update([
                'status' => $approved ? 'verified' : 'rejected',
                'verified_by' => $actor->id,
                'verified_at' => now(),
                'rejection_reason' => $approved ? null : $rejectionReason,
            ]);

            $transaction = $verification->paymentTransaction;
            $order = $transaction->order;

            $transaction->update([
                'status' => $approved ? PaymentTransactionStatus::PAID->value : PaymentTransactionStatus::FAILED->value,
                'paid_at' => $approved ? now() : null,
            ]);

            if ($approved) {
                // Accumulate the verified amount onto the order. A full
                // bank_transfer settles it in one step (paid == total => PAID);
                // a DP only ever reaches 'partially_paid' until the outstanding
                // balance is settled — DP is never marked PAID here.
                $this->applyPaymentToOrder($order, (float) $transaction->amount);
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
                'amount' => (float) $transaction->amount,
                'paid_amount' => (float) $order->paid_amount,
                'remaining_amount' => (float) $order->remaining_amount,
            ]);

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
    public function submitCodPaymentProof(PaymentTransaction $transaction, UploadedFile $proof, User $actor): CodPaymentProof
    {
        return DB::transaction(function () use ($transaction, $proof, $actor) {
            $media = $this->mediaService->store($proof, 'cod_payment_proof', $transaction, $actor);

            $codProof = CodPaymentProof::updateOrCreate(
                ['payment_transaction_id' => $transaction->id],
                [
                    'proof_media_id' => $media->id, 'status' => 'pending',
                    'confirmed_by' => null, 'confirmed_at' => null, 'rejection_reason' => null,
                ]
            );

            ActivityLogger::log($actor->id, $transaction->order, 'payment.cod_proof_submitted', null, [
                'actor_role' => $actor->role?->slug, 'payment_transaction_id' => $transaction->id,
            ]);

            return $codProof;
        });
    }

    /** Admin/Agen-only (authorized in the controller) — the sole way a COD proof request ever actually marks the order paid. */
    public function confirmCodPayment(CodPaymentProof $proof, User $actor, bool $confirmed, ?string $rejectionReason = null): CodPaymentProof
    {
        return DB::transaction(function () use ($proof, $actor, $confirmed, $rejectionReason) {
            $proof->update([
                'status' => $confirmed ? 'confirmed' : 'rejected',
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
                'rejection_reason' => $confirmed ? null : $rejectionReason,
            ]);

            $order = $proof->paymentTransaction->order;

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
     */
    public function requestSettlement(Order $order, User $actor): PaymentTransaction
    {
        if ((float) $order->remaining_amount <= 0) {
            throw new ApiException(__('messages.payment.nothing_to_settle'), 422);
        }

        $method = PaymentMethod::query()->where('code', 'bank_transfer')->firstOrFail();
        $config = $this->activeConfigFor($method, $order->agent_id);

        $transaction = PaymentTransaction::create([
            'order_id' => $order->id,
            'payment_method_id' => $method->id,
            'type' => 'payment',
            'amount' => $order->remaining_amount,
            'status' => PaymentTransactionStatus::PENDING->value,
            'gateway_reference' => 'ST-'.strtoupper(Str::random(10)),
            'raw_payload' => [
                'bank_name' => $config->config['bank_name'] ?? null,
                'account_name' => $config->config['account_name'] ?? null,
                'account_number' => $config->config['account_number'] ?? null,
                'note' => 'dp_settlement',
            ],
        ]);

        ActivityLogger::log($actor->id, $order, 'payment.settlement_requested', null, [
            'payment_transaction_id' => $transaction->id,
            'amount' => (float) $transaction->amount,
            'remaining_amount' => (float) $order->remaining_amount,
            'actor_role' => $actor->role?->slug,
        ]);

        return $transaction;
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
        $paid = round(max(0.0, $paidAmount ?? (float) $order->paid_amount), 2);
        $remaining = round(max(0.0, $total - $paid), 2);

        $order->update([
            'paid_amount' => $paid,
            'remaining_amount' => $remaining,
            'payment_status' => $remaining <= 0 ? 'paid' : ($paid > 0 ? 'partially_paid' : 'unpaid'),
        ]);

        return $order->fresh();
    }

    /**
     * Entry point for POST /webhooks/payment/{method}. Every call is logged
     * (payment_webhook_logs) BEFORE anything else happens — signature
     * failures and processing outcomes alike — so the full history is
     * auditable even for attacks that never got past verification.
     * Idempotent via the (payment_method_id, event_id) unique index: the
     * same event delivered twice short-circuits on the second delivery
     * without reprocessing.
     *
     * @return array{status: 'processed'|'duplicate'|'invalid_signature'|'unknown_reference', message: string}
     */
    /**
     * Signature verification requires the credentials of whichever agen this
     * transaction belongs to, but the webhook URL carries no agent identifier
     * — so the gateway's own reference is extracted from the (still
     * unverified) payload first, purely to look up which agen's config to
     * check the signature against. parseWebhookPayload() never touches
     * secrets (it's plain JSON/body parsing for all three gateways), so
     * nothing here is trusted to affect a transaction/order until the
     * signature check below passes.
     */
    public function handleWebhook(PaymentMethod $method, Request $request): array
    {
        $client = $this->gatewayFactory->make($method->code);
        $parsed = $client->parseWebhookPayload($request);

        $transaction = PaymentTransaction::query()
            ->where('payment_method_id', $method->id)
            ->where('gateway_reference', $parsed->reference)
            ->first();

        if (! $transaction) {
            PaymentWebhookLog::create([
                'payment_method_id' => $method->id,
                'event_id' => 'unknown-'.Str::uuid(),
                'gateway_reference' => $parsed->reference,
                'headers' => $request->headers->all(),
                'payload' => (array) $request->json()->all(),
                'signature_valid' => false,
                'processed' => false,
                'note' => 'No matching transaction for this reference — cannot resolve which agen to verify against.',
            ]);
            Log::warning('payment.webhook_unknown_reference', ['method' => $method->code, 'reference' => $parsed->reference]);

            return ['status' => 'unknown_reference', 'message' => 'No matching transaction.'];
        }

        $agentId = $transaction->order->agent_id;

        try {
            $config = $this->activeConfigFor($method, $agentId);
        } catch (ApiException) {
            $config = null;
        }

        $signatureValid = $config && $client->verifyWebhookSignature($request, $config->config);

        if (! $signatureValid) {
            PaymentWebhookLog::create([
                'payment_method_id' => $method->id,
                'event_id' => 'invalid-'.Str::uuid(),
                'gateway_reference' => $parsed->reference,
                'headers' => $request->headers->all(),
                'payload' => (array) $request->json()->all(),
                'signature_valid' => false,
                'processed' => false,
                'note' => 'Signature verification failed.',
            ]);
            Log::warning('payment.webhook_signature_invalid', ['method' => $method->code, 'agent_id' => $agentId]);

            return ['status' => 'invalid_signature', 'message' => 'Invalid signature.'];
        }

        // Idempotency: a row already existing for this (method, event_id) means
        // this exact event was already handled — never process it twice.
        $existingLog = PaymentWebhookLog::query()
            ->where('payment_method_id', $method->id)->where('event_id', $parsed->eventId)->first();

        if ($existingLog) {
            return ['status' => 'duplicate', 'message' => 'Event already processed.'];
        }

        // Two near-simultaneous deliveries of the same event can both pass the
        // check above before either inserts — the (payment_method_id, event_id)
        // unique index is the real guard; a race here throws instead of
        // silently double-processing, so catch it and treat it the same as
        // the already-existing-row case above.
        try {
            $log = PaymentWebhookLog::create([
                'payment_method_id' => $method->id,
                'event_id' => $parsed->eventId,
                'gateway_reference' => $parsed->reference,
                'headers' => $request->headers->all(),
                'payload' => $parsed->raw,
                'signature_valid' => true,
                'processed' => false,
            ]);
        } catch (QueryException $e) {
            return ['status' => 'duplicate', 'message' => 'Event already processed.'];
        }

        // Cross-check the amount the gateway reports against our own record —
        // the webhook body is never trusted to redefine what was actually owed.
        if ($parsed->amount !== null && abs($parsed->amount - (float) $transaction->amount) > 0.01) {
            $log->update(['note' => 'Amount mismatch: expected '.$transaction->amount.' got '.$parsed->amount]);
            Log::warning('payment.webhook_amount_mismatch', [
                'transaction_id' => $transaction->id, 'expected' => (float) $transaction->amount, 'got' => $parsed->amount,
            ]);

            return ['status' => 'unknown_reference', 'message' => 'Amount mismatch.'];
        }

        DB::transaction(function () use ($transaction, $parsed, $log) {
            $order = $transaction->order;
            $previousStatus = $transaction->status;

            $transaction->update([
                'status' => $parsed->status->value,
                'paid_at' => $parsed->status === PaymentTransactionStatus::PAID ? now() : $transaction->paid_at,
                'raw_payload' => $parsed->raw,
            ]);

            match ($parsed->status) {
                PaymentTransactionStatus::PAID => $this->applyPaymentToOrder($order, (float) $transaction->amount),
                PaymentTransactionStatus::REFUNDED => $order->update(['payment_status' => 'refunded']),
                PaymentTransactionStatus::FAILED,
                PaymentTransactionStatus::EXPIRED,
                PaymentTransactionStatus::CANCELLED => $order->update(['payment_status' => 'failed']),
                // A still-pending gateway event never changes our own state.
                PaymentTransactionStatus::PENDING => null,
            };

            ActivityLogger::log(null, $order, 'payment.webhook_processed', null, [
                'payment_transaction_id' => $transaction->id,
                'event_id' => $parsed->eventId,
                'from' => $previousStatus,
                'to' => $parsed->status->value,
            ]);

            $log->update(['processed' => true]);
        });

        return ['status' => 'processed', 'message' => 'Webhook processed.'];
    }
}
