<?php

namespace App\Services\Region;

use Illuminate\Support\Facades\DB;

/**
 * Bulk-loads Indonesia's official 4-level administrative hierarchy
 * (Provinsi/Kabupaten-Kota/Kecamatan/Kelurahan-Desa) from the CSV files in
 * database/data/regions/ — the canonical source dataset, not a hand-typed
 * seeder (see database/data/regions/SOURCE.md for provenance).
 *
 * Codes are the real Kemendagri hierarchical codes (2/4/6/10 digits) already
 * used as this project's primary keys (see the 2026_09_08_090000 migration)
 * — re-running this command never creates duplicates, it upserts by code.
 *
 * `rajaongkir_city_id` on regencies is deliberately NEVER touched on update
 * (only set on first insert, and left null even then — this dataset has no
 * legitimate way to know RajaOngkir's own numbering): a Super Admin who has
 * since mapped it via the CSV admin importer (RegionCsvService) must never
 * have that overwritten by a re-run of this command.
 */
class RegionImporter
{
    private const CHUNK_SIZE = 1000;

    /** @var array<int, array{code:string, district_code:string, name:string}> Villages skipped because their district_code has no district row anywhere in the source — a small, known data-quality gap upstream (see database/data/regions/SOURCE.md), never papered over with a fabricated parent. */
    private array $skippedVillages = [];

    /** @param  string|null  $basePath  Directory holding provinces.csv/regencies.csv/districts.csv/villages.csv — defaults to the real dataset; tests point this at a small fixture directory instead. */
    public function __construct(private readonly ?string $basePath = null) {}

    /** @return array{provinces:int, regencies:int, districts:int, villages:int, villages_skipped:int} */
    public function import(bool $reset = false): array
    {
        if ($reset) {
            $this->resetAll();
        }

        $this->skippedVillages = [];

        return [
            'provinces' => $this->importProvinces(),
            'regencies' => $this->importRegencies(),
            'districts' => $this->importDistricts(),
            'villages' => $this->importVillages(),
            'villages_skipped' => count($this->skippedVillages),
        ];
    }

    /** @return array<int, array{code:string, district_code:string, name:string}> */
    public function skippedVillages(): array
    {
        return $this->skippedVillages;
    }

    /**
     * A plain DELETE (never TRUNCATE) so every foreign-key action defined on
     * the schema actually fires: regencies/districts/villages cascade-delete
     * as children of provinces, and any konsumen_addresses row pointing at
     * one of them has its region columns set to null (nullOnDelete) rather
     * than the address itself being destroyed or the delete being blocked.
     */
    private function resetAll(): void
    {
        DB::table('provinces')->delete();
    }

    private function importProvinces(): int
    {
        $count = 0;

        foreach (array_chunk($this->readCsv('provinces.csv'), self::CHUNK_SIZE) as $chunk) {
            DB::table('provinces')->upsert(
                array_map(fn (array $r) => ['id' => $r['code'], 'name' => $r['name']], $chunk),
                ['id'],
                ['name'],
            );
            $count += count($chunk);
        }

        return $count;
    }

    private function importRegencies(): int
    {
        $count = 0;

        foreach (array_chunk($this->readCsv('regencies.csv'), self::CHUNK_SIZE) as $chunk) {
            DB::table('regencies')->upsert(
                array_map(fn (array $r) => [
                    'id' => $r['code'],
                    'province_id' => $r['province_code'],
                    'name' => $r['name'],
                    'rajaongkir_city_id' => $r['rajaongkir_city_id'] !== '' ? $r['rajaongkir_city_id'] : null,
                ], $chunk),
                ['id'],
                // 'rajaongkir_city_id' intentionally excluded — see class docblock.
                ['province_id', 'name'],
            );
            $count += count($chunk);
        }

        return $count;
    }

    private function importDistricts(): int
    {
        $count = 0;

        foreach (array_chunk($this->readCsv('districts.csv'), self::CHUNK_SIZE) as $chunk) {
            DB::table('districts')->upsert(
                array_map(fn (array $r) => ['id' => $r['code'], 'regency_id' => $r['regency_code'], 'name' => $r['name']], $chunk),
                ['id'],
                ['regency_id', 'name'],
            );
            $count += count($chunk);
        }

        return $count;
    }

    private function importVillages(): int
    {
        // Loaded once (7,265 short strings) so every chunk can be filtered
        // in memory instead of a query-per-row — never insert a village
        // whose district_code has no district row anywhere in the source.
        $validDistrictIds = DB::table('districts')->pluck('id')->flip();

        $count = 0;

        foreach (array_chunk($this->readCsv('villages.csv'), self::CHUNK_SIZE) as $chunk) {
            $valid = [];

            foreach ($chunk as $r) {
                if (isset($validDistrictIds[$r['district_code']])) {
                    $valid[] = $r;
                } else {
                    $this->skippedVillages[] = ['code' => $r['code'], 'district_code' => $r['district_code'], 'name' => $r['name']];
                }
            }

            if (empty($valid)) {
                continue;
            }

            DB::table('villages')->upsert(
                array_map(fn (array $r) => ['id' => $r['code'], 'district_id' => $r['district_code'], 'name' => $r['name']], $valid),
                ['id'],
                ['district_id', 'name'],
            );
            $count += count($valid);
        }

        return $count;
    }

    /** @return array<int, array<string, string>> */
    private function readCsv(string $filename): array
    {
        $path = ($this->basePath ?? database_path('data/regions')).'/'.$filename;
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = array_combine($header, $row);
        }

        fclose($handle);

        return $rows;
    }
}
