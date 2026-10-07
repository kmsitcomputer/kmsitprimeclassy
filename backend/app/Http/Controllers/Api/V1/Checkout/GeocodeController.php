<?php

namespace App\Http\Controllers\Api\V1\Checkout;

use App\Http\Controllers\Controller;
use App\Services\Geocode\GeocodeService;
use Illuminate\Http\Request;

/**
 * IMP-002 — "Gunakan Lokasi Saya".
 *
 * Accepts a browser-geolocation lat/lng; GeocodeService normalizes + matches
 * it against the canonical regional master. A null response (or 422 with
 * `matched: false`) means "no confident match — keep the manual selectors".
 * The frontend never persists external text; it only prefills the region
 * dropdowns with the returned canonical ids (which the user may edit).
 */
class GeocodeController extends Controller
{
    public function __construct(private readonly GeocodeService $geocodeService) {}

    public function locate(Request $request)
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $match = $this->geocodeService->locate((float) $data['latitude'], (float) $data['longitude']);

        if (! $match) {
            return $this->ok(['matched' => false, 'result' => null], 'Lokasi tidak dapat ditemukan — silakan pilih manual.');
        }

        return $this->ok(['matched' => true, 'result' => $match]);
    }
}