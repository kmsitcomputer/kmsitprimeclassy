<?php

namespace App\Services\Region;

use App\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The single CSV contract for Indonesia's 4-level region hierarchy —
 * export() and import() are exact inverses of each other, so a file
 * downloaded from this system (or hand-built to the same columns) always
 * re-imports cleanly (Blueprint: "pastikan hasil export tersebut terinput ke
 * database sesuai kebutuhan input checkout").
 *
 * Wide/denormalized format — one row per lowest-populated level, with every
 * ancestor's own code+name as its own column, so an admin editing the file in
 * a spreadsheet sees the full Provinsi/Kota/Kecamatan/Kelurahan address on
 * every row instead of a single mixed level/parent_code column. Each level's
 * *_code column is still the real primary key (Kemendagri-style string
 * code) — required so re-importing links province_id/regency_id/district_id
 * correctly, and so two different regencies that happen to share a name
 * (e.g. two "Kabupaten Bandung") are never confused with each other.
 *
 * Columns: province_code,province_name,regency_code,regency_name,
 *          district_code,district_name,village_code,village_name,
 *          rajaongkir_city_id
 *   - rajaongkir_city_id: only meaningful alongside a regency, blank
 *     otherwise (RajaOngkir's own city-id mapping — see RajaOngkirProvider).
 *   - A row may leave regency/district/village columns blank to represent a
 *     level that has no children yet (e.g. a province with no regencies
 *     entered) — only province_code is ever required.
 */
class RegionCsvService
{
    private const HEADER = [
        'province_code', 'province_name', 'regency_code', 'regency_name',
        'district_code', 'district_name', 'village_code', 'village_name', 'rajaongkir_city_id',
    ];

    public function export(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::HEADER);

            DB::table('provinces')
                ->leftJoin('regencies', 'regencies.province_id', '=', 'provinces.id')
                ->leftJoin('districts', 'districts.regency_id', '=', 'regencies.id')
                ->leftJoin('villages', 'villages.district_id', '=', 'districts.id')
                ->orderBy('provinces.id')->orderBy('regencies.id')->orderBy('districts.id')->orderBy('villages.id')
                ->select([
                    'provinces.id as province_code', 'provinces.name as province_name',
                    'regencies.id as regency_code', 'regencies.name as regency_name', 'regencies.rajaongkir_city_id',
                    'districts.id as district_code', 'districts.name as district_name',
                    'villages.id as village_code', 'villages.name as village_name',
                ])
                ->each(function ($row) use ($out) {
                    fputcsv($out, [
                        $row->province_code, $row->province_name,
                        $row->regency_code, $row->regency_name,
                        $row->district_code, $row->district_name,
                        $row->village_code, $row->village_name,
                        $row->rajaongkir_city_id,
                    ]);
                });

            fclose($out);
        }, 'regions.csv', ['Content-Type' => 'text/csv']);
    }

    /** @return array{provinces:int, regencies:int, districts:int, villages:int} */
    public function import(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if (! $handle) {
            throw new ApiException(__('messages.region.invalid_csv'), 422);
        }

        $header = fgetcsv($handle);

        if ($header === false || array_map('strtolower', array_map('trim', $header)) !== self::HEADER) {
            fclose($handle);
            throw new ApiException(__('messages.region.invalid_csv'), 422);
        }

        $provinces = [];
        $regencies = [];
        $districts = [];
        $villages = [];
        $rowNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (count($row) < 9) {
                fclose($handle);
                throw new ApiException(__('messages.region.invalid_row', ['row' => $rowNumber, 'reason' => 'column count']), 422);
            }

            [$provinceCode, $provinceName, $regencyCode, $regencyName, $districtCode, $districtName, $villageCode, $villageName, $rajaongkirCityId]
                = array_map('trim', $row);

            if ($provinceCode === '') {
                fclose($handle);
                throw new ApiException(__('messages.region.invalid_row', ['row' => $rowNumber, 'reason' => 'missing province_code']), 422);
            }

            $provinces[$provinceCode] = $provinceName;

            if ($regencyCode !== '') {
                $regencies[$regencyCode] = ['province_id' => $provinceCode, 'name' => $regencyName, 'rajaongkir_city_id' => $rajaongkirCityId ?: null];
            }
            if ($districtCode !== '') {
                $districts[$districtCode] = ['regency_id' => $regencyCode, 'name' => $districtName];
            }
            if ($villageCode !== '') {
                $villages[$villageCode] = ['district_id' => $districtCode, 'name' => $villageName];
            }
        }

        fclose($handle);

        $counts = ['provinces' => 0, 'regencies' => 0, 'districts' => 0, 'villages' => 0];

        DB::transaction(function () use ($provinces, $regencies, $districts, $villages, &$counts) {
            foreach ($provinces as $code => $name) {
                DB::table('provinces')->updateOrInsert(['id' => $code], ['name' => $name]);
                $counts['provinces']++;
            }
            foreach ($regencies as $code => $r) {
                DB::table('regencies')->updateOrInsert(['id' => $code], $r);
                $counts['regencies']++;
            }
            foreach ($districts as $code => $r) {
                DB::table('districts')->updateOrInsert(['id' => $code], $r);
                $counts['districts']++;
            }
            foreach ($villages as $code => $r) {
                DB::table('villages')->updateOrInsert(['id' => $code], $r);
                $counts['villages']++;
            }
        });

        return $counts;
    }
}
