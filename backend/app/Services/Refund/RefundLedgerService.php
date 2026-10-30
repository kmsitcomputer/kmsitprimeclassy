<?php

namespace App\Services\Refund;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderSalesReduction;
use App\Models\RefundAttempt;
use App\Models\RefundObligation;
use App\Models\RefundPayout;
use App\Models\User;
use App\Services\Payment\PaymentService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Three-layer refund ledger.
 *
 * Obligation (money lawfully owed) -> attempt (every disbursement attempt) -> payout (verified
 * money that actually left). All money math is integer cents; every amount mutation is
 * conditional and guarded by the Order-first lock held by the caller.
 */
class RefundLedgerService
{
    public function __construct(
        private readonly RefundAudit $audit,
        private readonly RefundMetrics $metrics,
        private readonly RefundAuthorization $authorization,
        private readonly PaymentService $paymentService,
    ) {}

    /**
     * Create a forward obligation from a source event. amount = min(event_value, C); the remainder
     * becomes a deferred entitlement (RD-01). A zero-amount container is still a real row.
     *
     * @param  array{source_type:string, source_id?:?int, obligation_key:string, reason?:?string,
     *               snapshot_id?:?int, deferred_reason?:?string, approval_source?:?string}  $attrs
     */
    public function createForwardObligation(
        Order $order,
        array $attrs,
        int $eventValueCents,
        ?User $actor = null,
        bool $autoApprove = false,
        string $provenance = 'forward',
    ): RefundObligation {
        if ($eventValueCents <= 0) {
            throw new ApiException('Nilai event refund harus lebih dari 0.', 422, [
                'code' => 'invalid_refund_event_value',
            ]);
        }

        $existing = RefundObligation::query()
            ->where('obligation_key', $attrs['obligation_key'])
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($order, $attrs, $eventValueCents, $actor, $autoApprove, $provenance) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertNotFinanciallyFinalized($order);

            $available = $this->metrics->forOrder($order)['available_cents'];
            $amount = min($eventValueCents, max(0, $available));
            $deferred = $eventValueCents - $amount;

            $obligation = RefundObligation::create([
                'obligation_no' => 'RO-'.Str::ulid(),
                'order_id' => $order->id,
                'source_type' => $attrs['source_type'],
                'source_id' => $attrs['source_id'] ?? null,
                'obligation_key' => $attrs['obligation_key'],
                'amount' => Money::amount($amount),
                'status' => $autoApprove ? 'approved' : 'pending',
                'provenance' => $provenance,
                'reason' => $attrs['reason'] ?? null,
                'approved_at' => $autoApprove ? now() : null,
                'approval_source' => $autoApprove ? ($attrs['approval_source'] ?? 'manual') : null,
                'snapshot_id' => $attrs['snapshot_id'] ?? null,
                'deferred_amount' => Money::amount($deferred),
                'deferred_remaining' => Money::amount($deferred),
                'deferred_reason' => $deferred > 0 ? ($attrs['deferred_reason'] ?? 'Insufficient available cash at creation') : null,
                'created_by' => $actor?->id,
                'metadata' => [
                    'event_value_cents' => $eventValueCents,
                    'available_cents_at_creation' => $available,
                ],
            ]);

            $this->audit->record(
                'obligation.created',
                $order,
                obligationId: $obligation->id,
                eventKey: 'obligation_created:'.$obligation->id,
                actor: $actor,
                payload: [
                    'amount_cents' => $amount,
                    'deferred_cents' => $deferred,
                    'source_type' => $attrs['source_type'],
                    'obligation_key' => $attrs['obligation_key'],
                ],
            );

            return $obligation->fresh();
        });
    }

    /**
     * A needs_verification obligation is never capped by C: it represents a claim that must be
     * reconciled, not cash we are about to pay (design row 11 / §6.1.1).
     *
     * @param  array{source_type:string, source_id?:?int, obligation_key:string, reason?:?string,
     *               snapshot_id?:?int}  $attrs
     */
    public function createVerificationObligation(
        Order $order,
        array $attrs,
        int $eventValueCents,
        ?User $actor = null,
        string $provenance = 'forward',
        string $reason = 'Evidence ambiguous — reconciliation required',
    ): RefundObligation {
        if ($eventValueCents <= 0) {
            throw new ApiException('Nilai event refund harus lebih dari 0.', 422, [
                'code' => 'invalid_refund_event_value',
            ]);
        }

        $existing = RefundObligation::query()
            ->where('obligation_key', $attrs['obligation_key'])
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($order, $attrs, $eventValueCents, $actor, $provenance, $reason) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertNotFinanciallyFinalized($order);

            $obligation = RefundObligation::create([
                'obligation_no' => 'RO-'.Str::ulid(),
                'order_id' => $order->id,
                'source_type' => $attrs['source_type'],
                'source_id' => $attrs['source_id'] ?? null,
                'obligation_key' => $attrs['obligation_key'],
                'amount' => Money::amount($eventValueCents),
                'status' => 'needs_verification',
                'provenance' => $provenance,
                'reason' => $attrs['reason'] ?? $reason,
                'snapshot_id' => $attrs['snapshot_id'] ?? null,
                'deferred_amount' => '0.00',
                'deferred_remaining' => '0.00',
                'created_by' => $actor?->id,
                'metadata' => ['event_value_cents' => $eventValueCents, 'requires_evidence' => true],
            ]);

            $this->audit->record(
                'obligation.needs_verification',
                $order,
                obligationId: $obligation->id,
                eventKey: 'obligation_created:'.$obligation->id,
                actor: $actor,
                reason: $reason,
                payload: ['amount_cents' => $eventValueCents, 'provenance' => $provenance],
            );

            return $obligation->fresh();
        });
    }

    public function approveObligation(RefundObligation $obligation, User $actor, string $approvalSource = 'manual'): RefundObligation
    {
        return DB::transaction(function () use ($obligation, $actor, $approvalSource) {
            $order = Order::query()->whereKey($obligation->order_id)->lockForUpdate()->firstOrFail();
            $obligation = RefundObligation::query()->whereKey($obligation->id)->lockForUpdate()->firstOrFail();

            if ($obligation->status === 'approved') {
                return $obligation;
            }

            if ($obligation->status !== 'pending') {
                throw new ApiException('Obligation tidak berada pada status yang dapat disetujui.', 422, [
                    'code' => 'obligation_not_approvable',
                ]);
            }

            // Approval does not add a new reservation: pending obligations are already counted in
            // R_r (I18), so I10 was enforced at creation time. Flipping pending -> approved only
            // makes the existing reservation payable.
            $obligation->update([
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'approval_source' => $approvalSource,
            ]);

            $this->audit->record(
                'obligation.approved',
                $order,
                obligationId: $obligation->id,
                eventKey: 'obligation_approved:'.$obligation->id,
                actor: $actor,
                payload: ['approval_source' => $approvalSource],
            );

            return $obligation->fresh();
        });
    }

    /**
     * Create an attempt under the reservation formula §6.2.1. Idempotency-key replay returns the
     * existing attempt unchanged.
     *
     * @param  array{method:string, amount_cents:int, idempotency_key:string, destination_snapshot?:?array,
     *               payment_transaction_id?:?int, external_reference?:?string}  $attrs
     */
    public function createAttempt(Order $order, RefundObligation $obligation, array $attrs, User $actor): RefundAttempt
    {
        RefundRecipientGuard::assertReady($order);

        $amount = (int) $attrs['amount_cents'];

        if ($amount <= 0) {
            throw new ApiException('Nominal attempt harus lebih dari 0.', 422, [
                'code' => 'invalid_attempt_amount',
            ]);
        }

        try {
            return DB::transaction(function () use ($order, $obligation, $attrs, $actor, $amount) {
                $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                $obligation = RefundObligation::query()->whereKey($obligation->id)->lockForUpdate()->firstOrFail();

                // The replay lookup is deliberately scoped to THIS obligation and happens only
                // after the resource is locked. A global lookup would answer a colliding client
                // key with another Agent's attempt — leaking obligation id, destination snapshot,
                // payment reference and amount across tenants (RF-01).
                $existing = RefundAttempt::query()
                    ->where('obligation_id', $obligation->id)
                    ->where('idempotency_key', $attrs['idempotency_key'])
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $this->assertAttemptReplay($existing, $attrs);
                }

                if ($obligation->status === 'cancelled' || $obligation->status === 'completed') {
                    throw new ApiException('Obligation tidak dapat menerima attempt baru.', 422, [
                        'code' => 'obligation_not_payable',
                    ]);
                }

                if (! in_array($obligation->status, ['approved', 'partially_paid'], true)) {
                    throw new ApiException('Obligation belum disetujui.', 422, [
                        'code' => 'obligation_not_approved',
                    ]);
                }

                if (Money::cents($obligation->amount) <= 0) {
                    throw new ApiException('Obligation bernilai nol tidak dapat menerima attempt.', 422, [
                        'code' => 'zero_amount_obligation_no_attempt',
                    ]);
                }

                $paid = Money::cents(RefundPayout::query()
                    ->where('obligation_id', $obligation->id)->sum('amount'));
                $inFlight = Money::cents(RefundAttempt::query()
                    ->where('obligation_id', $obligation->id)
                    ->whereIn('status', RefundAttempt::IN_FLIGHT_STATUSES)
                    ->sum('amount'));

                $remaining = Money::cents($obligation->amount) - $paid - $inFlight;

                if ($amount > $remaining) {
                    throw new ApiException('Nominal attempt melebihi sisa obligation.', 422, [
                        'code' => 'attempt_exceeds_remaining',
                        'remaining_cents' => $remaining,
                    ]);
                }

                $attemptNo = (int) RefundAttempt::query()
                    ->where('obligation_id', $obligation->id)->max('attempt_no') + 1;

                $attempt = RefundAttempt::create([
                    'obligation_id' => $obligation->id,
                    'attempt_no' => $attemptNo,
                    'method' => $attrs['method'],
                    'amount' => Money::amount($amount),
                    'status' => 'created',
                    'idempotency_key' => $attrs['idempotency_key'],
                    'external_reference' => $attrs['external_reference'] ?? null,
                    'payment_transaction_id' => $attrs['payment_transaction_id'] ?? null,
                    'destination_snapshot' => $attrs['destination_snapshot'] ?? null,
                    'processed_by' => $actor->id,
                ]);

                $this->audit->record(
                    'attempt.created',
                    $order,
                    obligationId: $obligation->id,
                    attemptId: $attempt->id,
                    eventKey: 'attempt_created:'.$attempt->id,
                    actor: $actor,
                    payload: ['attempt_no' => $attemptNo, 'amount_cents' => $amount, 'method' => $attrs['method']],
                );

                return $attempt->fresh();
            });
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent duplicate (or a key already used by another obligation/tenant) lost the
            // unique-key race. Re-read plainly: replay only this obligation's own matching attempt,
            // otherwise refuse with a safe conflict that carries no foreign resource detail.
            $existing = RefundAttempt::query()
                ->where('idempotency_key', $attrs['idempotency_key'])
                ->first();

            if ($existing && (int) $existing->obligation_id === (int) $obligation->id) {
                return $this->assertAttemptReplay($existing, $attrs);
            }

            throw new ApiException('Idempotency-Key sudah digunakan untuk operasi refund lain.', 409, [
                'code' => 'idempotency_key_conflict',
            ]);
        }
    }

    /**
     * A key is a legitimate replay only when it addresses the same obligation with the same
     * money and method. Anything else is a collision and must never answer with the stored
     * attempt's payload (RF-01).
     *
     * @param  array{method:string, amount_cents:int, idempotency_key:string}  $attrs
     */
    private function assertAttemptReplay(RefundAttempt $existing, array $attrs): RefundAttempt
    {
        $sameAmount = Money::cents($existing->amount) === (int) $attrs['amount_cents'];
        $sameMethod = $existing->method === $attrs['method'];

        if (! $sameAmount || ! $sameMethod) {
            throw new ApiException('Idempotency-Key sudah digunakan untuk attempt dengan nominal atau metode berbeda.', 409, [
                'code' => 'idempotency_key_conflict',
            ]);
        }

        return $existing;
    }

    /** created -> processing. Money may now be out; the initiation is mandatory and audited. */
    public function recordInitiation(RefundAttempt $attempt, User $actor, ?string $externalReference = null): RefundAttempt
    {
        return DB::transaction(function () use ($attempt, $actor, $externalReference) {
            $obligation = RefundObligation::query()->whereKey($attempt->obligation_id)->firstOrFail();
            $order = Order::query()->whereKey($obligation->order_id)->lockForUpdate()->firstOrFail();
            $attempt = RefundAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ($attempt->status === 'processing') {
                return $attempt;
            }

            if ($attempt->status !== 'created') {
                throw new ApiException('Attempt tidak berada pada status created.', 422, [
                    'code' => 'invalid_attempt_state',
                ]);
            }

            $attempt->update([
                'status' => 'processing',
                'initiated_at' => now(),
                'started_at' => now(),
                'external_reference' => $externalReference ?? $attempt->external_reference,
                'processed_by' => $actor->id,
            ]);

            $this->audit->record(
                'attempt.initiated',
                $order,
                obligationId: $obligation->id,
                attemptId: $attempt->id,
                eventKey: 'attempt_initiated:'.$attempt->id,
                actor: $actor,
                payload: ['external_reference' => $externalReference],
            );

            return $attempt->fresh();
        });
    }

    public function cancelAttempt(RefundAttempt $attempt, User $actor, string $reason): RefundAttempt
    {
        return DB::transaction(function () use ($attempt, $actor, $reason) {
            $obligation = RefundObligation::query()->whereKey($attempt->obligation_id)->firstOrFail();
            $order = Order::query()->whereKey($obligation->order_id)->lockForUpdate()->firstOrFail();
            $attempt = RefundAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ($attempt->status !== 'created') {
                throw new ApiException('Hanya attempt pra-inisiasi yang dapat dibatalkan.', 422, [
                    'code' => 'attempt_cancel_window_closed',
                ]);
            }

            $attempt->update(['status' => 'cancelled', 'finished_at' => now()]);

            $this->audit->record(
                'attempt.cancelled',
                $order,
                obligationId: $obligation->id,
                attemptId: $attempt->id,
                eventKey: 'attempt_cancelled:'.$attempt->id,
                actor: $actor,
                reason: $reason,
            );

            return $attempt->fresh();
        });
    }

    public function markFailed(RefundAttempt $attempt, User $actor, string $code, string $message): RefundAttempt
    {
        return DB::transaction(function () use ($attempt, $actor, $code, $message) {
            $obligation = RefundObligation::query()->whereKey($attempt->obligation_id)->firstOrFail();
            $order = Order::query()->whereKey($obligation->order_id)->lockForUpdate()->firstOrFail();
            $attempt = RefundAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ($attempt->status === 'failed') {
                return $attempt;
            }

            if ($attempt->status !== 'processing') {
                throw new ApiException('Attempt tidak berada pada status processing.', 422, [
                    'code' => 'invalid_attempt_state',
                ]);
            }

            $attempt->update([
                'status' => 'failed',
                'failure_code' => $code,
                'failure_message' => $message,
                'finished_at' => now(),
            ]);

            $this->audit->record(
                'attempt.failed',
                $order,
                obligationId: $obligation->id,
                attemptId: $attempt->id,
                eventKey: 'attempt_failed:'.$attempt->id,
                actor: $actor,
                payload: ['failure_code' => $code],
            );

            $this->reenterCancellationFinalization($order);

            return $attempt->fresh();
        });
    }

    public function markUnknown(RefundAttempt $attempt, User $actor, ?string $note = null): RefundAttempt
    {
        return DB::transaction(function () use ($attempt, $actor, $note) {
            $obligation = RefundObligation::query()->whereKey($attempt->obligation_id)->firstOrFail();
            $order = Order::query()->whereKey($obligation->order_id)->lockForUpdate()->firstOrFail();
            $attempt = RefundAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ($attempt->status === 'unknown') {
                return $attempt;
            }

            if ($attempt->status !== 'processing') {
                throw new ApiException('Attempt tidak berada pada status processing.', 422, [
                    'code' => 'invalid_attempt_state',
                ]);
            }

            $attempt->update([
                'status' => 'unknown',
                'unknown_since' => now(),
                'failure_message' => $note,
            ]);

            $this->audit->record(
                'attempt.unknown',
                $order,
                obligationId: $obligation->id,
                attemptId: $attempt->id,
                eventKey: 'attempt_unknown:'.$attempt->id,
                actor: $actor,
                reason: $note,
            );

            return $attempt->fresh();
        });
    }

    /**
     * processing -> succeeded with the self-exclusion reservation check (§6.2.3), payout insert and
     * paid_amount effect in ONE transaction.
     */
    public function completeAttempt(
        RefundAttempt $attempt,
        User $actor,
        ?int $proofMediaId = null,
        ?string $proofType = null,
        ?string $gatewayReference = null,
        ?string $verificationStatus = null,
    ): RefundPayout {
        return DB::transaction(function () use ($attempt, $actor, $proofMediaId, $proofType, $gatewayReference, $verificationStatus) {
            $obligation = RefundObligation::query()->whereKey($attempt->obligation_id)->firstOrFail();
            $order = Order::query()->whereKey($obligation->order_id)->lockForUpdate()->firstOrFail();
            $obligation = RefundObligation::query()->whereKey($obligation->id)->lockForUpdate()->firstOrFail();
            $attempt = RefundAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            $existingPayout = RefundPayout::query()->where('attempt_id', $attempt->id)->first();

            if ($existingPayout) {
                return $existingPayout;
            }

            if ($attempt->status !== 'processing') {
                throw new ApiException('Attempt belum diinisiasi; penyelesaian tidak diizinkan.', 422, [
                    'code' => $attempt->status === 'created' ? 'attempt_not_initiated' : 'invalid_attempt_state',
                ]);
            }

            $selfExclusion = $this->selfExclusionRemaining($obligation, $attempt);

            if (Money::cents($attempt->amount) > $selfExclusion) {
                throw new ApiException('Nominal payout melebihi sisa obligation setelah self-exclusion.', 422, [
                    'code' => 'attempt_exceeds_remaining',
                    'remaining_cents' => $selfExclusion,
                ]);
            }

            $affected = RefundAttempt::query()
                ->whereKey($attempt->id)
                ->where('status', 'processing')
                ->update(['status' => 'succeeded', 'finished_at' => now(), 'processed_by' => $actor->id]);

            if ($affected !== 1) {
                $fresh = RefundAttempt::query()->whereKey($attempt->id)->first();
                $payout = RefundPayout::query()->where('attempt_id', $attempt->id)->first();

                if ($payout) {
                    return $payout;
                }

                throw new ApiException('Attempt sudah diselesaikan oleh proses lain.', 409, [
                    'code' => 'attempt_already_settled',
                    'status' => $fresh?->status,
                ]);
            }

            $recordedOnCancelled = $obligation->status === 'cancelled' || $order->status === 'dibatalkan';
            $verification = $verificationStatus ?? 'proven';

            $payout = RefundPayout::create([
                'payout_no' => 'RP-'.Str::ulid(),
                'obligation_id' => $obligation->id,
                'order_id' => $order->id,
                'attempt_id' => $attempt->id,
                'payout_key' => $attempt->idempotency_key,
                'amount' => Money::amount(Money::cents($attempt->amount)),
                'method' => $attempt->method,
                'paid_at' => now(),
                'proof_media_id' => $proofMediaId ?? $attempt->proof_media_id,
                'proof_type' => $proofType ?? $attempt->proof_type,
                'gateway_reference' => $gatewayReference ?? $attempt->external_reference,
                'verification_status' => $verification,
                'affects_paid_amount' => true,
                'paid_effect_applied_at' => now(),
                'recorded_on_cancelled_obligation' => $recordedOnCancelled,
                'created_by' => $actor->id,
            ]);

            $this->paymentService->reverseAppliedPayment($order->fresh(), Money::float(Money::cents($attempt->amount)));

            $this->refreshObligationStatus($obligation->fresh());

            $this->audit->record(
                'payout.recorded',
                $order,
                obligationId: $obligation->id,
                attemptId: $attempt->id,
                payoutId: $payout->id,
                eventKey: 'payout:'.$attempt->id,
                actor: $actor,
                payload: [
                    'amount_cents' => Money::cents($payout->amount),
                    'verification_status' => $verification,
                    'recorded_on_cancelled_obligation' => $recordedOnCancelled,
                ],
            );

            $this->reenterCancellationFinalization($order);

            return $payout->fresh();
        });
    }

    /**
     * Settle a refund confirmed by a legacy endpoint (order-refund processing / return item
     * refunded) through the one money writer. A manual confirmation carries no gateway proof, so
     * without an attached proof it is recorded as `legacy_unverified` (counts as applied but not
     * as proven, design D-01); with proof it is `proven`.
     *
     * The caller must already hold the Order lock. Returns null when the obligation has nothing
     * payable left (already settled / deferred-only).
     */
    public function settleLegacyConfirmed(
        RefundObligation $obligation,
        User $actor,
        ?int $proofMediaId = null,
        string $method = 'cash',
        ?string $reference = null,
    ): ?RefundPayout {
        RefundRecipientGuard::assertReady($obligation->order()->withoutGlobalScopes()->firstOrFail());

        if ($obligation->status === 'needs_verification') {
            throw new ApiException('Obligation menunggu verifikasi; selesaikan verifikasi terlebih dahulu.', 422, [
                'code' => 'obligation_needs_verification',
            ]);
        }

        if (! in_array($obligation->status, ['approved', 'partially_paid'], true)) {
            throw new ApiException('Obligation belum disetujui.', 422, [
                'code' => 'obligation_not_approved',
            ]);
        }

        $remaining = Money::cents($obligation->remainingPayable());

        if ($remaining <= 0) {
            return null;
        }

        $order = Order::query()->whereKey($obligation->order_id)->lockForUpdate()->firstOrFail();

        $attempt = $this->createAttempt($order, $obligation, [
            'amount_cents' => $remaining,
            'method' => $method,
            'idempotency_key' => 'legacy-settle:'.$obligation->obligation_key,
            'external_reference' => $reference,
        ], $actor);

        if ($attempt->status === 'created') {
            $attempt = $this->recordInitiation($attempt, $actor, $reference);
        }

        return $this->completeAttempt(
            $attempt,
            $actor,
            $proofMediaId,
            $proofMediaId ? 'manual_proof' : 'legacy_manual_confirmation',
            $reference,
            $proofMediaId ? 'proven' : 'legacy_unverified',
        );
    }

    /**
     * Reconcile an unknown attempt with mandatory evidence: success settles (through processing),
     * failure is terminal. Bare status flips are refused.
     */
    public function reconcileUnknown(RefundAttempt $attempt, User $actor, bool $succeeded, string $evidence): RefundAttempt|RefundPayout
    {
        if (trim($evidence) === '') {
            throw new ApiException('Rekonsiliasi attempt unknown memerlukan bukti.', 422, [
                'code' => 'reconciliation_evidence_required',
            ]);
        }

        return DB::transaction(function () use ($attempt, $actor, $succeeded, $evidence) {
            $obligation = RefundObligation::query()->whereKey($attempt->obligation_id)->firstOrFail();
            $order = Order::query()->whereKey($obligation->order_id)->lockForUpdate()->firstOrFail();
            $attempt = RefundAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ($attempt->status !== 'unknown') {
                throw new ApiException('Attempt bukan berstatus unknown.', 422, [
                    'code' => 'invalid_attempt_state',
                ]);
            }

            if ($succeeded) {
                $attempt->update([
                    'status' => 'processing',
                    'reconciliation_evidence' => $evidence,
                    'reconciled_by' => $actor->id,
                    'reconciled_at' => now(),
                ]);

                return $this->completeAttempt($attempt->fresh(), $actor);
            }

            $attempt->update([
                'status' => 'failed',
                'failure_code' => 'reconciled_failed',
                'failure_message' => 'Reconciled as failed: '.$evidence,
                'reconciliation_evidence' => $evidence,
                'reconciled_by' => $actor->id,
                'reconciled_at' => now(),
                'finished_at' => now(),
            ]);

            $this->audit->record(
                'attempt.failed',
                $order,
                obligationId: $obligation->id,
                attemptId: $attempt->id,
                eventKey: 'attempt_failed:'.$attempt->id,
                actor: $actor,
                reason: $evidence,
                payload: ['reconciled' => true],
            );

            $this->reenterCancellationFinalization($order);

            return $attempt->fresh();
        });
    }

    /**
     * needs_verification exits A (approved) and B (cancelled/write-off). Exit C is performed by
     * the cancellation finalizer and explicitly excludes needs_verification rows.
     */
    public function resolveNeedsVerification(
        RefundObligation $obligation,
        User $actor,
        string $exit,
        string $evidence,
        ?string $reason = null,
    ): RefundObligation {
        if (trim($evidence) === '') {
            throw new ApiException('Resolusi needs_verification memerlukan bukti.', 422, [
                'code' => 'reconciliation_evidence_required',
            ]);
        }

        if (! in_array($exit, ['A', 'B'], true)) {
            throw new ApiException('Exit needs_verification tidak valid.', 422, [
                'code' => 'invalid_needs_verification_exit',
            ]);
        }

        return DB::transaction(function () use ($obligation, $actor, $exit, $evidence, $reason) {
            $obligation = RefundObligation::query()->whereKey($obligation->id)->lockForUpdate()->firstOrFail();
            $order = Order::query()->whereKey($obligation->order_id)->lockForUpdate()->firstOrFail();

            if ($obligation->status !== 'needs_verification') {
                return $obligation;
            }

            $historical = in_array($obligation->provenance, ['legacy', 'provisional_legacy'], true)
                || in_array($obligation->source_type, ['legacy', 'manual'], true);

            if ($exit === 'A') {
                $this->authorization->assertFinance($actor);

                if ($historical || in_array($obligation->source_type, ['legacy'], true)) {
                    $this->authorization->assertOwner($actor, $order);
                }

                $obligation->update([
                    'status' => 'approved',
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                    'approval_source' => 'manual',
                    'reason' => $reason ?? $obligation->reason,
                ]);
            } else {
                $this->authorization->assertOwner($actor, $order);

                $obligation->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancel_reason' => $reason ?? 'needs_verification_exit_b',
                ]);
            }

            $this->audit->record(
                'needs_verification.resolved',
                $order,
                obligationId: $obligation->id,
                eventKey: 'nv_resolved:'.$obligation->id,
                actor: $actor,
                reason: $reason,
                payload: ['exit' => $exit, 'evidence' => $evidence, 'historical' => $historical],
            );

            return $obligation->fresh();
        });
    }

    /**
     * D2: Finance initiates a materialization request (audited, no state change). Execution still
     * requires the Owner via materializeDeferred().
     */
    public function requestDeferredMaterialization(
        RefundObligation $origin,
        int $amountCents,
        User $actor,
        string $clientKey,
    ): void {
        DB::transaction(function () use ($origin, $amountCents, $actor, $clientKey) {
            $origin = RefundObligation::query()->whereKey($origin->id)->lockForUpdate()->firstOrFail();
            $order = Order::query()->whereKey($origin->order_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertFinance($actor);

            if ($amountCents <= 0) {
                throw new ApiException('Nominal materialisasi harus lebih dari 0.', 422, [
                    'code' => 'invalid_materialization_amount',
                ]);
            }

            $this->audit->record(
                'deferred.materialization_requested',
                $order,
                obligationId: $origin->id,
                eventKey: 'deferred_request:'.$origin->id.':'.$clientKey,
                actor: $actor,
                payload: ['amount_cents' => $amountCents, 'deferred_remaining_cents' => Money::cents($origin->deferred_remaining)],
            );
        });
    }

    /**
     * The only path from a deferred entitlement to cash. Replay-first ordering (§6.4, C-01).
     */
    public function materializeDeferred(
        RefundObligation $origin,
        int $amountCents,
        User $actor,
        string $clientKey,
    ): RefundObligation {
        return DB::transaction(function () use ($origin, $amountCents, $actor, $clientKey) {
            $origin = RefundObligation::query()->whereKey($origin->id)->lockForUpdate()->firstOrFail();
            $order = Order::query()->whereKey($origin->order_id)->lockForUpdate()->firstOrFail();

            $this->authorization->assertOwner($actor, $order);

            if ($origin->source_type !== 'deferred') {
                // The origin is the container source (return/shortfall/manual) that carries the
                // remaining deferred entitlement; a deferred child can itself not be an origin.
                if (Money::cents($origin->deferred_amount) <= 0) {
                    throw new ApiException('Obligation ini tidak memiliki entitlement deferred.', 422, [
                        'code' => 'not_a_deferred_origin',
                    ]);
                }
            }

            $childKey = 'deferred:'.$origin->id.':'.$clientKey;
            $existing = RefundObligation::query()->where('obligation_key', $childKey)->lockForUpdate()->first();

            if ($existing) {
                if (Money::cents($existing->amount) !== $amountCents || (int) $existing->parent_obligation_id !== (int) $origin->id) {
                    throw new ApiException('Idempotency key sudah digunakan dengan parameter berbeda.', 409, [
                        'code' => 'idempotency_key_conflict',
                    ]);
                }

                $this->audit->record(
                    'deferred.materialization_replayed',
                    $order,
                    obligationId: $origin->id,
                    eventKey: 'deferred_replay:'.$clientKey,
                    actor: $actor,
                    payload: ['child_obligation_id' => $existing->id],
                );

                return $existing;
            }

            if ($origin->status === 'cancelled') {
                throw new ApiException('Origin deferred sudah dibatalkan/superseded.', 422, [
                    'code' => 'deferred_origin_not_materializable',
                ]);
            }

            if (Money::cents($origin->deferred_remaining) <= 0) {
                throw new ApiException('Entitlement deferred sudah habis.', 422, [
                    'code' => 'deferred_exhausted',
                ]);
            }

            if ($amountCents <= 0) {
                throw new ApiException('Nominal materialisasi harus lebih dari 0.', 422, [
                    'code' => 'invalid_materialization_amount',
                ]);
            }

            $available = $this->metrics->forOrder($order)['available_cents'];
            $cap = min(Money::cents($origin->deferred_remaining), $available);

            if ($amountCents > $cap) {
                throw new ApiException('Nominal materialisasi melebihi batas deferred/kas tersedia.', 422, [
                    'code' => 'deferred_materialization_exceeds_cap',
                    'cap_cents' => $cap,
                ]);
            }

            $child = RefundObligation::create([
                'obligation_no' => 'RO-'.Str::ulid(),
                'order_id' => $order->id,
                'source_type' => 'deferred',
                'source_id' => $origin->id,
                'obligation_key' => $childKey,
                'amount' => Money::amount($amountCents),
                'status' => 'approved',
                'provenance' => $origin->provenance,
                'reason' => 'Deferred materialization',
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'approval_source' => 'owner_manual_correction',
                'snapshot_id' => $origin->snapshot_id,
                'deferred_amount' => '0.00',
                'deferred_remaining' => '0.00',
                'parent_obligation_id' => $origin->id,
                'created_by' => $actor->id,
                'metadata' => ['client_idempotency_key' => $clientKey],
            ]);

            $affected = RefundObligation::query()
                ->whereKey($origin->id)
                ->where('deferred_remaining', '>=', Money::amount($amountCents))
                ->update([
                    'deferred_remaining' => DB::raw('deferred_remaining - '.Money::amount($amountCents)),
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException('Konflik materialisasi deferred; ulangi.', 409, [
                    'code' => 'deferred_materialization_conflict',
                ]);
            }

            $this->audit->record(
                'deferred.materialized',
                $order,
                obligationId: $child->id,
                eventKey: 'deferred_materialized:'.$clientKey,
                actor: $actor,
                payload: ['origin_obligation_id' => $origin->id, 'amount_cents' => $amountCents],
            );

            $this->refreshObligationStatus($origin->fresh());

            return $child->fresh();
        });
    }

    /** RD-06 supersede: zero the remaining deferred entitlement, keep all history. */
    public function supersedeDeferred(RefundObligation $origin, ?User $actor, string $reason): void
    {
        $origin = RefundObligation::query()->whereKey($origin->id)->lockForUpdate()->firstOrFail();

        if (Money::cents($origin->deferred_amount) <= 0) {
            return;
        }

        if (Money::cents($origin->deferred_remaining) <= 0) {
            return;
        }

        $origin->update([
            'deferred_remaining' => '0.00',
            'cancelled_at' => $origin->cancelled_at ?? now(),
        ]);

        $this->audit->record(
            'deferred.superseded',
            Order::query()->find($origin->order_id),
            obligationId: $origin->id,
            eventKey: 'deferred_superseded:'.$origin->id,
            actor: $actor,
            reason: $reason,
            payload: ['deferred_amount_cents' => Money::cents($origin->deferred_amount)],
        );

        $this->refreshObligationStatus($origin->fresh());
    }

    /**
     * RD-05 / I21 reinstatement: only with evidence + Owner approval under the Order lock, only
     * when no obligation of the order has a non-terminal attempt, and only if the post-state
     * bounds hold.
     */
    public function reinstateReduction(
        OrderSalesReduction $reduction,
        User $actor,
        string $evidence,
        ?string $reason = null,
    ): OrderSalesReduction {
        if (trim($evidence) === '') {
            throw new ApiException('Reinstatement memerlukan bukti rekonsiliasi.', 422, [
                'code' => 'reconciliation_evidence_required',
            ]);
        }

        return DB::transaction(function () use ($reduction, $actor, $evidence, $reason) {
            $order = Order::query()->whereKey($reduction->order_id)->lockForUpdate()->firstOrFail();
            $reduction = OrderSalesReduction::query()->whereKey($reduction->id)->lockForUpdate()->firstOrFail();

            $this->authorization->assertOwner($actor, $order);

            if ($reduction->reinstated_at !== null) {
                return $reduction;
            }

            $hasInFlight = RefundAttempt::query()
                ->whereIn('obligation_id', RefundObligation::query()
                    ->where('order_id', $order->id)->select('id'))
                ->whereIn('status', RefundAttempt::IN_FLIGHT_STATUSES)
                ->exists();

            if ($hasInFlight) {
                throw new ApiException('Reinstatement diblokir oleh attempt yang masih berjalan.', 422, [
                    'code' => 'reinstatement_blocked_by_inflight_attempt',
                ]);
            }

            $meter = $this->metrics->forOrder($order);
            $billableAfter = $meter['billable_cents'] + Money::cents($reduction->amount);
            $availableForBounds = $meter['paid_cents'] - $billableAfter;

            if ($meter['gross_cents'] < $meter['applied_cents'] + $meter['reserved_cents']) {
                throw new ApiException('Reinstatement melanggar batas A + R_r ≤ G.', 422, [
                    'code' => 'reinstatement_exceeds_bounds',
                ]);
            }

            if ($billableAfter <= $meter['paid_cents']
                && $meter['reserved_cents'] > max(0, $availableForBounds)) {
                throw new ApiException('Reinstatement melanggar batas R_r ≤ P − B.', 422, [
                    'code' => 'reinstatement_exceeds_bounds',
                ]);
            }

            $reduction->update([
                'reinstated_at' => now(),
                'reinstated_by' => $actor->id,
                'reinstatement_reason' => $reason,
                'reinstatement_reconciliation_ref' => $evidence,
            ]);

            $this->audit->record(
                'reinstatement.applied',
                $order,
                eventKey: 'reinstatement:'.$reduction->id,
                actor: $actor,
                reason: $reason,
                payload: ['amount_cents' => Money::cents($reduction->amount), 'evidence' => $evidence],
            );

            return $reduction->fresh();
        });
    }

    /**
     * I17: after cancellation financial finalization, no NEW obligation may be created for the
     * order. The only permitted exception is an Owner-approved manual correction, which is why
     * materializeDeferred() (assertOwner + approval_source=owner_manual_correction) is not routed
     * through this guard. Forward return/shortfall/verification events are always refused.
     */
    private function assertNotFinanciallyFinalized(Order $order): void
    {
        if ($order->cancellation_financial_status === 'finalized') {
            throw new ApiException('Order sudah difinalisasi secara finansial; obligation baru memerlukan koreksi manual Owner.', 422, [
                'code' => 'order_financially_finalized',
            ]);
        }
    }

    public function refreshObligationStatus(RefundObligation $obligation): RefundObligation
    {
        if ($obligation->status === 'cancelled') {
            return $obligation;
        }

        $paid = Money::cents(RefundPayout::query()->where('obligation_id', $obligation->id)->sum('amount'));
        $amount = Money::cents($obligation->amount);
        $deferredRemaining = Money::cents($obligation->deferred_remaining);

        if ($amount === 0) {
            $status = $deferredRemaining === 0
                ? 'completed'
                : ($obligation->status === 'pending' ? 'pending' : 'approved');

            $obligation->update([
                'status' => $status,
                'completed_at' => $status === 'completed' ? ($obligation->completed_at ?? now()) : null,
            ]);

            return $obligation->fresh();
        }

        if ($paid >= $amount) {
            $status = $deferredRemaining === 0 ? 'completed' : 'approved';
            $obligation->update([
                'status' => $status,
                'completed_at' => $status === 'completed' ? ($obligation->completed_at ?? now()) : null,
            ]);

            return $obligation->fresh();
        }

        if ($paid > 0) {
            $obligation->update(['status' => 'partially_paid']);
        } elseif (in_array($obligation->status, ['partially_paid'], true)) {
            $obligation->update(['status' => 'approved']);
        }

        return $obligation->fresh();
    }

    /** @return int cents still payable to this obligation, excluding this attempt's own reservation */
    private function selfExclusionRemaining(RefundObligation $obligation, RefundAttempt $attempt): int
    {
        $payouts = Money::cents(RefundPayout::query()
            ->where('obligation_id', $obligation->id)->sum('amount'));

        $inFlight = Money::cents(RefundAttempt::query()
            ->where('obligation_id', $obligation->id)
            ->whereIn('status', RefundAttempt::IN_FLIGHT_STATUSES)
            ->whereKeyNot($attempt->id)
            ->sum('amount'));

        return Money::cents($obligation->amount) - $payouts - $inFlight;
    }

    private function selfReservation(RefundObligation $obligation): int
    {
        $paid = Money::cents(RefundPayout::query()->where('obligation_id', $obligation->id)->sum('amount'));

        return max(0, Money::cents($obligation->amount) - $paid);
    }

    private function reenterCancellationFinalization(Order $order): void
    {
        if ($order->fresh()->status !== 'dibatalkan') {
            return;
        }

        app(CancellationFinancialService::class)->finalize($order->fresh());
    }
}
