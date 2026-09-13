<?php

namespace App\Services\Shipping;

interface DistanceCalculatorInterface
{
    /** Road/straight-line distance in kilometers between two coordinates. */
    public function calculate(float $originLat, float $originLng, float $destLat, float $destLng): float;
}
