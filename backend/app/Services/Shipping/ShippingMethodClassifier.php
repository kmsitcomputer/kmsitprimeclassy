<?php

namespace App\Services\Shipping;

/**
 * LOCKED canonical shipping-method classifier (Package C production UAT, round 5).
 *
 * This is the ONLY place a canonical `shipping_provider_code` is turned into a business classification.
 * There is NO implicit fallback: anything that is not an explicitly recognised canonical code is UNKNOWN,
 * and UNKNOWN fails closed for every delivery-date mutation. Never infer the method from UI labels — the
 * server-side provider code/domain representation is authoritative.
 *
 * Human-locked matrix:
 *   rajaongkir -> EKSPEDISI    : delivery-date reschedule DENY (domain 422 before mutation)
 *   openroute  -> KURIR_ONLINE : reschedule ALLOW while lifecycle mutable; unchanged Order fee is divided
 *                                evenly across all active delivery-date groups (deterministic integers)
 *   free       -> FREE         : reschedule ALLOW while lifecycle mutable; every active group stays zero
 *   pickup     -> PICKUP       : reschedule DENY (domain 422 before mutation)
 *   null/empty/unknown/legacy -> UNKNOWN : reschedule DENY / FAIL CLOSED (domain 422 before mutation)
 */
final class ShippingMethodClassifier
{
    public const EKSPEDISI = 'EKSPEDISI';

    public const KURIR_ONLINE = 'KURIR_ONLINE';

    public const FREE = 'FREE';

    public const PICKUP = 'PICKUP';

    public const UNKNOWN = 'UNKNOWN';

    /** Canonical normalisation for a provider code (whitespace + case only; never invents a code). */
    public static function normalize(?string $code): string
    {
        return strtolower(trim((string) $code));
    }

    /** Map ONE canonical provider code to its classification — no fallback, no inference. */
    public static function classify(?string $code): string
    {
        return match (self::normalize($code)) {
            'rajaongkir' => self::EKSPEDISI,
            'openroute' => self::KURIR_ONLINE,
            'free' => self::FREE,
            'pickup' => self::PICKUP,
            default => self::UNKNOWN,
        };
    }

    /** Only Kurir Online and Free delivery may have their delivery date rescheduled. */
    public static function allowsDeliveryDateChange(string $classification): bool
    {
        return in_array($classification, [self::KURIR_ONLINE, self::FREE], true);
    }
}
