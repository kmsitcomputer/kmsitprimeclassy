<?php

namespace App\Services\Refund;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderSalesReduction;
use App\Models\RefundAttempt;
use App\Models\RefundObligation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cancellation Step 1 (sales reduction) and Step 2 (financial finalization), split because the
 * cash picture may still be moving when the order is cancelled (design §6.5).
 *
 * finalize() is idempotent and always runs under the Order lock. While any open obligation has a
 * non-terminal attempt, the order is marked pending_reconciliation and nothing is closed.
 */
class CancellationFinancialService
{
    public function __construct(
        private readonly RefundAudit $audit,
        private readonly RefundMetrics $metrics,
        private readonly RefundLedgerService $ledger,
        private readonly OrderFinancialSnapshotService $snapshots,
    ) {}

    /** Step 1 helper: B -> 0 via a post-invoice sales reduction (no paid_amount change). */
    public function bookCancellationReduction(Order $order, ?User $actor = null): void
    {
        $existing = OrderSalesReduction::query()
            ->where('order_id', $order->id)
            ->where('source_type', 'cancellation')
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return;
        }

        // Cancellation is a basis-requiring event too: freeze the basis if no earlier event did.
        // An order cancelled before any unit became billable has nothing to snapshot (and nothing
        // to refund): skip instead of refusing the cancellation.
        $hasBillableUnits = $order->items()->where('fulfilled_quantity', '>', 0)->exists();

        if ($hasBillableUnits) {
            $this->snapshots->ensure($order, OrderFinancialSnapshotService::TRIGGER_ORDER_CANCELLATION, $actor);
        }

        $billable = $this->metrics->forOrder($order)['billable_cents'];

        if ($billable <= 0) {
            return;
        }

        OrderSalesReduction::create([
            'order_id' => $order->id,
            'source_type' => 'cancellation',
            'source_id' => $order->id,
            'amount' => Money::amount($billable),
            'applied_to_order_total' => false,
            'provenance' => 'forward',
        ]);

        $this->audit->record(
            'cancellation.sales_reduction_booked',
            $order,
            eventKey: 'cancellation_reduction:'.$order->id,
            actor: $actor,
            payload: ['amount_cents' => $billable],
        );
    }

    /**
     * Step 2 — must be called while the caller holds the Order lock (SQL FOR UPDATE).
     */
    public function finalize(Order $order, ?User $actor = null): Order
    {
        return DB::transaction(function () use ($order, $actor) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->cancellation_financial_status === 'finalized') {
                return $order;
            }

            $obligations = RefundObligation::query()
                ->where('order_id', $order->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $openObligationIds = $obligations->whereIn('status', RefundObligation::OPEN_STATUSES)->pluck('id');

            $hasInFlight = $openObligationIds->isNotEmpty()
                && RefundAttempt::query()
                    ->whereIn('obligation_id', $openObligationIds)
                    ->whereIn('status', RefundAttempt::IN_FLIGHT_STATUSES)
                    ->exists();

            if ($hasInFlight) {
                $order->update(['cancellation_financial_status' => 'pending_reconciliation']);

                $this->audit->record(
                    'cancellation.finalization_deferred',
                    $order,
                    eventKey: 'cancellation_deferred:'.$order->id,
                    actor: $actor,
                    payload: ['reason' => 'in_flight_attempt'],
                );

                return $order->fresh();
            }

            foreach ($obligations as $obligation) {
                $this->closeIfSupersedable($obligation, $actor);
            }

            $meter = $this->metrics->forOrder($order->fresh());
            $amountCents = min($meter['paid_cents'], $meter['available_cents']);

            if ($amountCents > 0) {
                $key = 'cancel:'.$order->id;
                $existing = RefundObligation::query()
                    ->where('obligation_key', $key)
                    ->lockForUpdate()
                    ->first();

                if (! $existing) {
                    $obligation = RefundObligation::create([
                        'obligation_no' => 'RO-'.Str::ulid(),
                        'order_id' => $order->id,
                        'source_type' => 'cancellation',
                        'source_id' => $order->id,
                        'obligation_key' => $key,
                        'amount' => Money::amount($amountCents),
                        'status' => 'approved',
                        'provenance' => 'forward',
                        'reason' => 'Cancellation refund',
                        'approved_at' => now(),
                        'approval_source' => 'auto_cancellation_policy',
                        'deferred_amount' => '0.00',
                        'deferred_remaining' => '0.00',
                        'created_by' => $actor?->id,
                        'metadata' => [
                            'paid_cents' => $meter['paid_cents'],
                            'available_cents' => $meter['available_cents'],
                        ],
                    ]);

                    $this->audit->record(
                        'obligation.created',
                        $order,
                        obligationId: $obligation->id,
                        eventKey: 'obligation_created:'.$obligation->id,
                        actor: $actor,
                        payload: ['amount_cents' => $amountCents, 'source_type' => 'cancellation'],
                    );
                }
            }

            $order->update([
                'cancellation_financial_status' => 'finalized',
                'cancellation_finalized_at' => now(),
            ]);

            $this->audit->record(
                'cancellation.finalized',
                $order,
                eventKey: 'cancellation_finalized:'.$order->id,
                actor: $actor,
                payload: [
                    'paid_cents' => $meter['paid_cents'],
                    'reserved_cents' => $meter['reserved_cents'],
                    'available_cents' => $meter['available_cents'],
                ],
            );

            return $order->fresh();
        });
    }

    /**
     * Guarded closure set (design §6.5 4a). Everything else — needs_verification, legacy,
     * provisional_legacy, manual, partially_paid/completed/cancelled, and origins with an open
     * child path — stays open and reserved (D3/D4).
     */
    private function closeIfSupersedable(RefundObligation $obligation, ?User $actor): void
    {
        if (! in_array($obligation->status, ['pending', 'approved'], true)) {
            return;
        }

        if ($obligation->provenance !== 'forward') {
            return;
        }

        if (! in_array($obligation->source_type, ['return_stage', 'fulfillment_shortfall'], true)) {
            return;
        }

        $payouts = Money::cents($obligation->payouts()->sum('amount'));

        if ($payouts > 0) {
            return;
        }

        $hasInFlight = $obligation->attempts()
            ->whereIn('status', RefundAttempt::IN_FLIGHT_STATUSES)
            ->exists();

        if ($hasInFlight) {
            return;
        }

        $hasOpenChild = RefundObligation::query()
            ->where('parent_obligation_id', $obligation->id)
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->exists();

        if ($hasOpenChild) {
            return;
        }

        if (Money::cents($obligation->deferred_remaining) > 0) {
            $this->ledger->supersedeDeferred($obligation, $actor, 'superseded_by_order_cancellation');
        }

        // A zero-amount container has nothing payable; superseding it above is the closure.
        if (Money::cents($obligation->amount) === 0) {
            $obligation->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancel_reason' => 'superseded_by_order_cancellation',
            ]);

            return;
        }

        $obligation->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancel_reason' => 'superseded_by_order_cancellation',
        ]);

        $this->audit->record(
            'obligation.superseded',
            Order::query()->find($obligation->order_id),
            obligationId: $obligation->id,
            eventKey: 'obligation_superseded:'.$obligation->id,
            actor: $actor,
            reason: 'superseded_by_order_cancellation',
        );
    }
}
