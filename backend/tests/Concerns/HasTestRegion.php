<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * One minimal, obviously-test-only region chain (province -> regency ->
 * district -> village) — the real reference tables ship empty (Blueprint:
 * "Jangan membuat data dummy"; Super Admin populates them via CSV import),
 * but checkout now requires a village_id, so tests need at least one row to
 * exercise that path against.
 */
trait HasTestRegion
{
    protected function seedTestVillage(): string
    {
        if (DB::table('villages')->where('id', '9999999999')->exists()) {
            return '9999999999';
        }

        DB::table('provinces')->insertOrIgnore(['id' => '99', 'name' => 'Provinsi Uji Coba']);
        DB::table('regencies')->insertOrIgnore([
            'id' => '9999', 'province_id' => '99', 'name' => 'Kota Uji Coba', 'rajaongkir_city_id' => '501',
        ]);
        DB::table('districts')->insertOrIgnore(['id' => '999999', 'regency_id' => '9999', 'name' => 'Kecamatan Uji Coba']);
        DB::table('villages')->insertOrIgnore(['id' => '9999999999', 'district_id' => '999999', 'name' => 'Kelurahan Uji Coba']);

        return '9999999999';
    }
}
