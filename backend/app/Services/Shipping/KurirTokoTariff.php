<?php

namespace App\Services\Shipping;

use App\Models\ShippingConfiguration;
use App\Models\ShippingProvider;

/**
 * The ONE per-Agen Kurir Toko tariff (price per km, minimum distance, minimum charge, free-shipping override).
 *
 * It is independent of every routing provider: ORS and KMSIT only supply distance. Storage is the existing `shipping_configurations`
 * row (one per Agen) which has always been anchored to the `openroute` shipping_providers row — that row id is just the stable anchor,
 * its activation flag, the Agen's ORS toggle and the ORS credentials are never consulted when reading or writing the tariff.
 */
class KurirTokoTariff
{
    public static function anchorProviderId(): ?int
    {
        return ShippingProvider::query()->where('code', 'openroute')->value('id');
    }

    /** The tariff the quote engine must use (active row only). */
    public static function activeFor(int $agentId): ?ShippingConfiguration
    {
        $anchor = self::anchorProviderId();

        return $anchor ? ShippingConfiguration::query()->where('agent_id', $agentId)->where('shipping_provider_id', $anchor)->where('is_active', true)->first() : null;
    }

    public static function for(int $agentId): ?ShippingConfiguration
    {
        $anchor = self::anchorProviderId();

        return $anchor ? ShippingConfiguration::query()->where('agent_id', $agentId)->where('shipping_provider_id', $anchor)->first() : null;
    }

    /** @return array<string,mixed>|null */
    public static function present(?ShippingConfiguration $c): ?array
    {
        return $c ? [
            'price_per_km' => (float) $c->price_per_km, 'minimum_distance_km' => (float) $c->minimum_distance_km, 'minimum_charge' => (float) $c->minimum_charge,
            'free_shipping_enabled' => (bool) $c->free_shipping_enabled,
            'free_shipping_min_amount' => $c->free_shipping_min_amount !== null ? (float) $c->free_shipping_min_amount : null,
            'is_active' => (bool) $c->is_active,
        ] : null;
    }

    /**
     * Create/update the Agen's tariff. Only the keys present in $values change (free-shipping settings are preserved when not sent).
     *
     * @param  array<string,mixed>  $values
     */
    public static function save(int $agentId, array $values): ShippingConfiguration
    {
        $anchor = self::anchorProviderId() ?? throw new \RuntimeException('Kurir Toko provider row is missing.');
        $row = ShippingConfiguration::query()->firstOrNew(['agent_id' => $agentId]);
        $row->shipping_provider_id = $anchor;
        foreach (['price_per_km', 'minimum_distance_km', 'minimum_charge', 'free_shipping_enabled', 'free_shipping_min_amount'] as $k) {
            if (array_key_exists($k, $values)) {
                $row->{$k} = $values[$k] ?? ($k === 'minimum_charge' ? 0 : ($k === 'free_shipping_enabled' ? false : null));
            }
        }
        if ($row->minimum_charge === null) {
            $row->minimum_charge = 0;
        }
        $row->is_active = true;
        $row->save();

        return $row;
    }
}
