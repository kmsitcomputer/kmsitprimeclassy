<?php

namespace App\Services\Geocode;

/** Explicit disabled/unknown-provider fallback; the live default is Nominatim. */
class NoopReverseGeocoder implements ReverseGeocoder
{
    public function reverse(float $latitude, float $longitude): ?array
    {
        return null;
    }
}