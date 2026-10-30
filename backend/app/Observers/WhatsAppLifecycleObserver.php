<?php

namespace App\Observers;

use App\Models\Order;
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
 *   Order.status -> diproses/dikirim/terkirim/dibatalkan -> order_processed/order_shipped/order_delivered/order_cancelled
 *   ReturnRequest created / -> approved -> return_requested / return_approved
 *   RefundPayout created (proven)      -> refund_completed
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
            if ($order->wasChanged('payment_status') && $order->payment_status === 'paid') {
                $this->notifier()->orderEvent($order, WhatsAppEvents::PAYMENT_PAID);
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
            default => null,
        };
    }

    public function updated(object $model): void
    {
        match (true) {
            $model instanceof Order => $this->orderUpdated($model),
            $model instanceof ReturnRequest => $this->returnUpdated($model),
            default => null,
        };
    }
}
