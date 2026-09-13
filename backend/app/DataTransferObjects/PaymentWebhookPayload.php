<?php

namespace App\DataTransferObjects;

use App\Enums\PaymentTransactionStatus;

/**
 * What every gateway client's parseWebhookPayload() normalizes a raw,
 * provider-specific callback body into — PaymentService never reads a
 * Xendit/Tripay/Stripe-shaped array directly, only this.
 */
final readonly class PaymentWebhookPayload
{
    public function __construct(
        /** The provider's own event id — Stripe 'evt_...', Xendit invoice id, Tripay reference. Idempotency key. */
        public string $eventId,
        /** Matches PaymentTransaction::gateway_reference — how we find OUR row for this event. */
        public string $reference,
        public PaymentTransactionStatus $status,
        /** Amount the gateway reports paid, when it tells us — cross-checked against our own record, never trusted alone. */
        public ?float $amount,
        public array $raw,
    ) {}
}
