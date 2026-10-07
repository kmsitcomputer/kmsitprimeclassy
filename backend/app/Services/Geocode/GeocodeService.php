<?php

namespace App\Services\Geocode;

use App\Models\Village;

/**
 * IMP-002 — normalized GPS geolocation → regional-master matcher.
 *
 * Takes a provider's normalized labels and matches them against the
 * canonical PrimeClassy region master (exact labels constrained by the ancestor chain). Returns stable region IDs + names + postal code when a match is
 * found; returns null on any ambiguity/failure so the frontend falls back to
 * manual selection. NEVER stores the provider's raw text as canonical id.
 */
class GeocodeService
{
    public function __construct(private readonly ReverseGeocoder $geocoder) {}

    /**
     * @return array{province_id:?string, regency_id:?string, district_id:?string, village_id:?string,
     *               province_name:?string, regency_name:?string, district_name:?string, village_name:?string,
     *               postal_code:?string}|null
     */
    public function locate(float $latitude, float $longitude): ?array
    {
        $result = $this->geocoder->reverse($latitude, $longitude);

        if (! $result || empty($result['province']) || empty($result['regency']) || empty($result['village'])) {
            return null;
        }

        $village = $this->matchVillage($result);
        if (! $village) {
            return null;
        }

        $district = $village->district;
        $regency = $district?->regency;
        $province = $regency?->province;

        return [
            'province_id' => $province?->id,
            'regency_id' => $regency?->id,
            'district_id' => $district?->id,
            'village_id' => $village->id,
            'province_name' => $province?->name,
            'regency_name' => $regency?->name,
            'district_name' => $district?->name,
            'village_name' => $village->name,
            'postal_code' => $result['postal_code'] ?? null,
        ];
    }

    /** Exact canonical chain match; duplicates or incomplete ancestry are manual fallback. */
    private function matchVillage(array $labels): ?Village
    {
        $query = Village::query()->with('district.regency.province')
            ->whereRaw('LOWER(TRIM(name)) = ?', [self::normalize($labels['village'])])
            ->whereHas('district', function ($district) use ($labels) {
                if (! empty($labels['district'])) {
                    $district->whereRaw('LOWER(TRIM(name)) = ?', [self::normalize($labels['district'])]);
                }
                $district->whereHas('regency', fn ($regency) => $regency
                    ->whereRaw('LOWER(TRIM(name)) = ?', [self::normalize($labels['regency'])])
                    ->whereHas('province', fn ($province) => $province
                        ->whereRaw('LOWER(TRIM(name)) = ?', [self::normalize($labels['province'])])));
            });
        $matches = $query->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    public static function normalize(string $value): string
    {
        return preg_replace('/^(provinsi|kecamatan|kelurahan|desa)\s+/u', '', mb_strtolower(trim($value)));
    }
}
