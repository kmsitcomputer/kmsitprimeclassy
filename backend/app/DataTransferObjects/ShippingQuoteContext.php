<?php

namespace App\DataTransferObjects;

/** Everything a shipping provider might need to quote a delivery — never a trusted cost. */
final readonly class ShippingQuoteContext
{
    public function __construct(
        public float $originLat,
        public float $originLng,
        public float $destLat,
        public float $destLng,
        public ?string $destRegencyId,
        public int $weightGrams,
        public float $subtotal,
        public int $agentId,
        /**
         * The konsumen's explicit shipping-method choice ('rajaongkir' =
         * "Ekspedisi", 'openroute' = "Kurir Online") — only meaningful when
         * both providers are active at once. Null means "no explicit choice
         * yet / only one provider active", which uses the automatic
         * precedence in ShippingQuoteService.
         */
        public ?string $preferredProvider = null,
    ) {}
}
