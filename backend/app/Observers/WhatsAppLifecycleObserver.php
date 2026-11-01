<?php

namespace App\Observers;

use App\Models\BankTransferVerification;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\RefundObligation;
use App\Models\RefundPayout;
use App\Models\ReturnRequest;
use App\Services\WhatsApp\OrderNotifier;
use App\Services\WhatsApp\WhatsAppEvents;
use Illuminate\Support\Facades\Log;

/**
 * Listens to the REAL lifecycle writes (no new status, no new hook in the order services) and hands them to the
 * notifier, which defers to after-commit. Wrapped so that nothing here can ever surface as a business error.
 *
 * Mapped facts (all pre-existing):
 *   Order created                      -> order_created
 *   Order.payment_status -> paid       -> payment_paid
 *   Order.payment_status -> partially_paid -> payment_partial (HA-04: verified DP, never full settlement)
 *   Order.status -> diproses/dikirim/terkirim/dibatalkan -> order_processed/order_shipped/order_delivered/order_cancelled
 *   ReturnRequest created / -> approved -> return_requested / return_approved
 *   ReturnRequest -> completed with EVERY item rejected -> return_rejected
 *   RefundPayout created (proven)      -> refund_completed
 *   RefundObligation created pending / created approved -> refund_requested / refund_approved (root obligations only)
 *   RefundObligation pending|needs_verification -> approved -> refund_approved
 *   RefundObligation needs_verification -> cancelled (Owner exit B) -> refund_rejected
 *   BankTransferVerification -> rejected -> payment_failed
 */
class WhatsAppLifecycleObserver
{
    private const ORDER_STATUS_EVENT = [
        'diproses' => WhatsAppEvents::ORDER_PROCESSED,
        'dikirim' => WhatsAppEvents::ORDER_SHIPPED,
        'terkirim' => WhatsAppEvents::ORDER_DELIVERED,
        'dibatalkan' => WhatsAppEvents::ORDER_CANCELLED,
    ];

    // NO constructor dependencies on purpose: Eloquent builds this class through the container on EVERY model event, so
    // anything that can fail while being constructed would surface as an error in the business operation. The notifier is
    // resolved lazily INSIDE safely() instead.

    public function orderCreated(Order $order): void
    {
        $this->safely(fn () => $this->notifier()->orderEvent($order, WhatsAppEvents::ORDER_CREATED));
    }

    public function orderUpdated(Order $order): void
    {
        $this->safely(function () use ($order) {
            if ($order->wasChanged('status') && isset(self::ORDER_STATUS_EVENT[$order->status])) {
                $this->notifier()->orderEvent($order, self::ORDER_STATUS_EVENT[$order->status]);
            }
            // HA-04: full settlement notifies `payment_paid`; a verified PARTIAL (DP) notifies `payment_partial`.
            // One transition ⇒ exactly one of them (the `wasChanged` guard means a partial→paid path emits once per
            // transition, never both for the same write). Order status is never consulted for payment truth.
            if ($order->wasChanged('payment_status') && $order->payment_status === 'paid') {
                $this->notifier()->orderEvent($order, WhatsAppEvents::PAYMENT_PAID);
            } elseif ($order->wasChanged('payment_status') && $order->payment_status === 'partially_paid') {
                $this->notifier()->orderEvent($order, WhatsAppEvents::PAYMENT_PARTIAL);
            }
        });
    }

    public function returnCreated(ReturnRequest $return): void
    {
        $this->safely(fn () => $this->notifier()->returnEvent($return, WhatsAppEvents::RETURN_REQUESTED));
    }

    public function returnUpdated(ReturnRequest $return): void
    {
        $this->safely(function () use ($return) {
            if ($return->wasChanged('status') && $return->status === 'approved') {
                $this->notifier()->returnEvent($return, WhatsAppEvents::RETURN_APPROVED);
            }
            // Item decisions are written before the request completes (same transaction, visible to this read).
            if ($return->wasChanged('status') && $return->status === 'completed'
                && $return->items()->exists() && ! $return->items()->where('status', '!=', 'rejected')->exists()) {
                $this->notifier()->returnEvent($return, WhatsAppEvents::RETURN_REJECTED);
            }
        });
    }

    public function obligationCreated(RefundObligation $obligation): void
    {
        $this->safely(function () use ($obligation) {
            // Derived (deferred-materialization) children are bookkeeping of an already-notified root obligation.
            if ($obligation->parent_obligation_id !== null) {
                return;
            }
            match ($obligation->status) {
                RefundObligation::STATUS_PENDING => $this->notifier()->obligationEvent($obligation, WhatsAppEvents::REFUND_REQUESTED),
                RefundObligation::STATUS_APPROVED => $this->notifier()->obligationEvent($obligation, WhatsAppEvents::REFUND_APPROVED),
                default => null,
            };
        });
    }

    public function obligationUpdated(RefundObligation $obligation): void
    {
        $this->safely(function () use ($obligation) {
            if ($obligation->parent_obligation_id !== null || ! $obligation->wasChanged('status')) {
                return;
            }
            $from = $obligation->getOriginal('status');
            // partially_paid -> approved is a payment regression, not a new approval: never re-announced.
            if ($obligation->status === RefundObligation::STATUS_APPROVED
                && in_array($from, [RefundObligation::STATUS_PENDING, RefundObligation::STATUS_NEEDS_VERIFICATION], true)) {
                $this->notifier()->obligationEvent($obligation, WhatsAppEvents::REFUND_APPROVED);
            } elseif ($obligation->status === RefundObligation::STATUS_CANCELLED && $from === RefundObligation::STATUS_NEEDS_VERIFICATION) {
                $this->notifier()->obligationEvent($obligation, WhatsAppEvents::REFUND_REJECTED);
            }
        });
    }

    public function verificationUpdated(BankTransferVerification $verification): void
    {
        $this->safely(function () use ($verification) {
            if (! $verification->wasChanged('status') || $verification->status !== 'rejected') {
                return;
            }
            $orderId = PaymentTransaction::query()->whereKey($verification->payment_transaction_id)->value('order_id');
            if ($orderId !== null) {
                $this->notifier()->paymentRejected($verification, (int) $orderId);
            }
        });
    }

    public function refundCreated(RefundPayout $payout): void
    {
        $this->safely(function () use ($payout) {
            // Only money that is PROVEN to have left counts as "refund berhasil".
            if ($payout->verification_status === 'proven') {
                $this->notifier()->refundEvent($payout);
            }
        });
    }

    private function notifier(): OrderNotifier
    {
        return app(OrderNotifier::class);
    }

    private function safely(\Closure $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('whatsapp.observer.failed', ['error' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    /** Eloquent observer entry points (one class, thin dispatch by model). */
    public function created(object $model): void
    {
        match (true) {
            $model instanceof Order => $this->orderCreated($model),
            $model instanceof ReturnRequest => $this->returnCreated($model),
            $model instanceof RefundPayout => $this->refundCreated($model),
            $model instanceof RefundObligation => $this->obligationCreated($model),
            default => null,
        };
    }

    public function updated(object $model): void
    {
        match (true) {
            $model instanceof Order => $this->orderUpdated($model),
            $model instanceof ReturnRequest => $this->returnUpdated($model),
            $model instanceof RefundObligation => $this->obligationUpdated($model),
            $model instanceof BankTransferVerification => $this->verificationUpdated($model),
            default => null,
        };
    }
}
