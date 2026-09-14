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
        public ?string $destVillageId,
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
        /**
         * The konsumen's specific courier+service pick under "Ekspedisi"
         * (RajaOngkir aggregates several couriers — JNE, TIKI, POS, etc. —
         * each with several service tiers/prices). Shape: ['courier' =>
         * 'jne', 'service' => 'REG']. Null means "no explicit pick yet /
         * not applicable", which falls back to the cheapest option across
         * every configured courier (RajaOngkirProvider's existing default).
         * Never a trusted cost — only the courier+service identifiers; the
         * price is always re-resolved server-side from a fresh quote.
         *
         * @var array{courier: string, service: string}|null
         */
        public ?array $selectedCourierOption = null,
    ) {}
}
