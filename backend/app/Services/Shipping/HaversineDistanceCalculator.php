<?php

namespace App\Services\Shipping;

/** Straight-line great-circle distance — always available, needs no external API/credentials. */
class HaversineDistanceCalculator implements DistanceCalculatorInterface
{
    private const EARTH_RADIUS_KM = 6371.0;

    public function calculate(float $originLat, float $originLng, float $destLat, float $destLng): float
    {
        $originLatRad = deg2rad($originLat);
        $destLatRad = deg2rad($destLat);
        $deltaLat = deg2rad($destLat - $originLat);
        $deltaLng = deg2rad($destLng - $originLng);

        $a = sin($deltaLat / 2) ** 2
            + cos($originLatRad) * cos($destLatRad) * sin($deltaLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round(self::EARTH_RADIUS_KM * $c, 2);
    }
}
