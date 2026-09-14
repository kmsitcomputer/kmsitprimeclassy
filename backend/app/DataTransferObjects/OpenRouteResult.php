<?php

namespace App\DataTransferObjects;

final readonly class OpenRouteResult
{
    public function __construct(
        public float $distanceMeters,
        public float $distanceKm,
        public float $durationSeconds,
        public string $profile,
    ) {}
}
