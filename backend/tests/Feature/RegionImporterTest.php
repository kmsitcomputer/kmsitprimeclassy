<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Province;
use App\Models\Regency;
use App\Models\Village;
use App\Services\Region\RegionImporter;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegionImporterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function importer(): RegionImporter
    {
        return new RegionImporter(base_path('tests/Fixtures/regions'));
    }

    /* ---------------- Hierarchy relationships ---------------- */

    public function test_province_can_have_multiple_regencies_and_a_regency_belongs_to_its_province(): void
    {
        $this->importer()->import();

        $jabar = Province::find('32');
        $this->assertCount(1, $jabar->regencies);
        $this->assertSame('Kota Bandung', $jabar->regencies->first()->name);
        $this->assertSame('32', Regency::find('3273')->province->id);
    }

    public function test_district_belongs_to_its_regency_and_village_belongs_to_its_district(): void
    {
        $this->importer()->import();

        $this->assertSame('3273', District::find('327301')->regency->id);
        $this->assertSame('327301', Village::find('3273011001')->district->id);
    }

    public function test_invalid_parent_is_rejected_by_the_foreign_key(): void
    {
        $this->expectException(QueryException::class);

        DB::table('districts')->insert(['id' => '999999', 'regency_id' => '0000', 'name' => 'Nowhere']);
    }

    /* ---------------- Importer behaviour ---------------- */

    public function test_importer_can_run_twice_without_creating_duplicates(): void
    {
        $this->importer()->import();
        $this->importer()->import();

        $this->assertSame(2, Province::count());
        $this->assertSame(2, Regency::count());
        $this->assertSame(2, District::count());
        // 3 source rows minus 1 orphan (see below) = 2 valid villages.
        $this->assertSame(2, Village::count());
    }

    public function test_importer_preserves_relationships_across_levels(): void
    {
        $counts = $this->importer()->import();

        $this->assertSame(2, $counts['provinces']);
        $this->assertSame(2, $counts['regencies']);
        $this->assertSame(2, $counts['districts']);
        $this->assertSame(2, $counts['villages']);

        $this->assertDatabaseHas('villages', ['id' => '5171011001', 'district_id' => '517101']);
        $this->assertDatabaseHas('districts', ['id' => '517101', 'regency_id' => '5171']);
        $this->assertDatabaseHas('regencies', ['id' => '5171', 'province_id' => '51']);
    }

    /** A village whose district_code has no matching district row is skipped, never inserted with a fabricated parent. */
    public function test_importer_skips_villages_whose_district_has_no_matching_row(): void
    {
        $counts = $this->importer()->import();

        $this->assertSame(1, $counts['villages_skipped']);
        $this->assertDatabaseMissing('villages', ['id' => '9999999999']);
    }

    public function test_importer_never_overwrites_an_already_populated_rajaongkir_city_id(): void
    {
        $this->importer()->import();
        DB::table('regencies')->where('id', '3273')->update(['rajaongkir_city_id' => '23']);

        $this->importer()->import();

        $this->assertSame('23', Regency::find('3273')->rajaongkir_city_id);
    }

    public function test_reset_deletes_existing_region_rows_and_nulls_but_does_not_delete_referencing_addresses(): void
    {
        $this->importer()->import();

        $user = \App\Models\User::factory()->konsumen()->create();
        DB::table('konsumen_addresses')->insert([
            'user_id' => $user->id, 'label' => 'Rumah', 'recipient_name' => 'X', 'phone' => '08',
            'address_line' => 'Jl. Test', 'province_id' => '32', 'regency_id' => '3273',
            'district_id' => '327301', 'village_id' => '3273011001',
            'latitude' => -6.9, 'longitude' => 107.6, 'is_default' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->importer()->import(reset: true);

        $this->assertDatabaseHas('konsumen_addresses', [
            'user_id' => $user->id, 'address_line' => 'Jl. Test', 'village_id' => null,
        ]);
        // Re-imported cleanly after the reset.
        $this->assertSame(2, Province::count());
    }
}
