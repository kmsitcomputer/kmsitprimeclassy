<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'super_admin')->value('id')]);
    }

    private function seedRegionChain(): void
    {
        DB::table('provinces')->insert(['id' => '32', 'name' => 'Jawa Barat']);
        DB::table('regencies')->insert(['id' => '3273', 'province_id' => '32', 'name' => 'Kota Bandung', 'rajaongkir_city_id' => '23']);
        DB::table('districts')->insert(['id' => '327301', 'regency_id' => '3273', 'name' => 'Sukasari']);
        DB::table('villages')->insert(['id' => '3273011001', 'district_id' => '327301', 'name' => 'Isola']);
    }

    public function test_cascading_lookups_return_children_scoped_to_their_parent(): void
    {
        $this->seedRegionChain();

        $this->getJson('/api/v1/regions/provinces')->assertOk()->assertJsonFragment(['name' => 'Jawa Barat']);
        $this->getJson('/api/v1/regions/regencies?province_id=32')->assertOk()->assertJsonFragment(['name' => 'Kota Bandung']);
        $this->getJson('/api/v1/regions/districts?regency_id=3273')->assertOk()->assertJsonFragment(['name' => 'Sukasari']);
        $this->getJson('/api/v1/regions/villages?district_id=327301')->assertOk()->assertJsonFragment(['name' => 'Isola']);

        // An unknown province_id is rejected outright, never silently treated as "all".
        $this->getJson('/api/v1/regions/regencies?province_id=99')->assertStatus(422);
    }

    public function test_export_produces_a_csv_that_reimports_to_the_same_data(): void
    {
        $this->seedRegionChain();
        $admin = $this->superAdmin();

        $csv = $this->actingAs($admin)->get('/api/v1/admin/regions/export');
        $csv->assertOk();
        $content = $csv->streamedContent();
        $this->assertStringStartsWith(
            "province_code,province_name,regency_code,regency_name,district_code,district_name,village_code,village_name,rajaongkir_city_id\n",
            $content
        );
        // Wide format: Provinsi/Kota/Kecamatan/Kelurahan all present on the same row.
        $this->assertStringContainsString('32,"Jawa Barat",3273,"Kota Bandung",327301,Sukasari,3273011001,Isola,23', $content);

        // Wipe and re-import from the exported content — must reproduce identical rows.
        DB::table('villages')->delete();
        DB::table('districts')->delete();
        DB::table('regencies')->delete();
        DB::table('provinces')->delete();

        $file = UploadedFile::fake()->createWithContent('regions.csv', $content);
        $response = $this->actingAs($admin)->postJson('/api/v1/admin/regions/import', ['file' => $file]);
        $response->assertOk();
        $this->assertSame(1, $response->json('data.provinces'));
        $this->assertSame(1, $response->json('data.regencies'));
        $this->assertSame(1, $response->json('data.districts'));
        $this->assertSame(1, $response->json('data.villages'));

        $this->assertDatabaseHas('provinces', ['id' => '32', 'name' => 'Jawa Barat']);
        $this->assertDatabaseHas('regencies', ['id' => '3273', 'province_id' => '32', 'rajaongkir_city_id' => '23']);
        $this->assertDatabaseHas('districts', ['id' => '327301', 'regency_id' => '3273']);
        $this->assertDatabaseHas('villages', ['id' => '3273011001', 'district_id' => '327301']);

        $this->assertDatabaseHas('activity_logs', ['event' => 'region.csv_imported']);
    }

    public function test_import_rejects_a_malformed_csv(): void
    {
        $file = UploadedFile::fake()->createWithContent('bad.csv', "not,the,right,header\n1,2,3,4");

        $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/admin/regions/import', ['file' => $file])
            ->assertStatus(422);
    }

    public function test_only_super_admin_can_export_or_import_regions(): void
    {
        $konsumen = User::factory()->konsumen()->create();

        $this->actingAs($konsumen)->get('/api/v1/admin/regions/export')->assertStatus(403);
        $this->actingAs($konsumen)->postJson('/api/v1/admin/regions/import', [
            'file' => UploadedFile::fake()->createWithContent('r.csv', "province_code,province_name,regency_code,regency_name,district_code,district_name,village_code,village_name,rajaongkir_city_id\n"),
        ])->assertStatus(403);
    }
}
