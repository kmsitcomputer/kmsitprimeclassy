<?php

namespace App\Enums;

/**
 * The complete, closed set of states a PaymentTransaction can be in —
 * identical regardless of which of the three gateways (or manual transfer,
 * or COD) produced it, so callers never branch on provider-specific status
 * strings.
 */
enum PaymentTransactionStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case FAILED = 'failed';
    case EXPIRED = 'expired';
    case CANCELLED = 'cancelled';
    case REFUNDED = 'refunded';

    /** True once the transaction can no longer change state through normal processing. */
    public function isFinal(): bool
    {
        return in_array($this, [self::PAID, self::FAILED, self::EXPIRED, self::CANCELLED, self::REFUNDED], true);
    }
}
