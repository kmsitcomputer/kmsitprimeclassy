<?php

namespace App\Services\Geocode;

/**
 * IMP-002 — reverse-geocode provider abstraction.
 *
 * The GPS "Gunakan Lokasi Saya" flow only ever depends on this interface,
 * never on a concrete provider. A provider is expected to take lat/lng and
 * return a normalized result:
 *
 *   ['province' => 'Jawa Barat', 'regency' => 'Bandung', 'district' => 'Coblong', 'village' => 'Dago', 'postal_code' => '40135']
 *
 * Consumers (GeocodeService) then match those labels against the canonical
 * PrimeClassy regional master — they NEVER store the provider's raw text as
 * canonical identity. If a provider is unavailable/fails/ambiguous, the
 * caller falls back to manual address selection (frontend remains usable).
 *
 * Nominatim/OSM is the Human-approved default live provider. Configuration,
 * shared rate limiting and caching stay behind this interface. Noop is an
 * explicit safe fallback when a provider is disabled/unknown.
 */
interface ReverseGeocoder
{
    /** @return array{province:?string, regency:?string, district:?string, village:?string, postal_code:?string}|null when unavailable/ambiguous */
    public function reverse(float $latitude, float $longitude): ?array;
}