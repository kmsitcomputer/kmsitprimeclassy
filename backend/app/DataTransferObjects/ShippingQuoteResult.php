<?php

namespace App\DataTransferObjects;

/**
 * What every shipping provider (and the free-shipping fallback) returns —
 * everything OrderService needs to snapshot onto orders/shipments so a later
 * config change can never alter a past order's numbers.
 */
final readonly class ShippingQuoteResult
{
    public function __construct(
        public float $cost,
        public ?float $distanceKm,
        public ?float $ratePerKm,
        public string $providerCode,
        public array $meta = [],
    ) {}

    public static function free(string $reason): self
    {
        return new self(cost: 0.0, distanceKm: null, ratePerKm: null, providerCode: 'free', meta: ['reason' => $reason]);
    }
}
