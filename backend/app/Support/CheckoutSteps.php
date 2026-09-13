<?php

namespace App\Support;

/**
 * The canonical, ordered list of checkout wizard steps — "seperti installer
 * step-by-step". A step's `active` flag is decided per-request by
 * CheckoutStepResolver from real conditions (auth state, whether the actor
 * is placing the order for themselves or a downline konsumen, the
 * 'shipping.enabled' setting) — never hard-coded into the frontend. Adding a
 * step later is a new entry here (plus a matching frontend component), not a
 * rewrite of the wizard.
 *
 * Payment-method-specific sub-flows (COD reminder, bank-transfer proof
 * upload + verification, gateway payment instructions) are NOT separate
 * wizard steps — they can't be, since they depend on data (a VA number, a
 * bank account) that only exists once the order/payment transaction has
 * actually been created. They are instead variants of the CONFIRMATION step,
 * keyed by the chosen PaymentMethod's `type` (see PaymentService and
 * OrderResource::payment).
 */
class CheckoutSteps
{
    public const ACCOUNT = 'account';

    public const ADDRESS = 'address';

    public const REFERRAL = 'referral';

    public const PRODUCTS = 'products';

    public const SHIPPING = 'shipping';

    public const DELIVERY_DATE = 'delivery_date';

    public const PAYMENT = 'payment';

    public const REVIEW = 'review';

    public const CONFIRMATION = 'confirmation';

    public static function order(): array
    {
        return [
            self::ACCOUNT,
            self::ADDRESS,
            self::REFERRAL,
            self::PRODUCTS,
            self::SHIPPING,
            self::DELIVERY_DATE,
            self::PAYMENT,
            self::REVIEW,
            self::CONFIRMATION,
        ];
    }

    public static function labelKey(string $step): string
    {
        return "checkout.step.{$step}";
    }
}
