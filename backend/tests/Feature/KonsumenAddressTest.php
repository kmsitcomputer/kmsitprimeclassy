<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

class KonsumenAddressTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_konsumen_can_create_list_update_and_delete_their_own_address(): void
    {
        $konsumen = User::factory()->konsumen()->create(['agent_id' => User::factory()->agen()->create()->id]);
        $villageId = $this->seedTestVillage();

        $create = $this->actingAs($konsumen)->postJson('/api/v1/addresses', [
            'label' => 'Rumah', 'recipient_name' => 'Budi', 'phone' => '0811',
            'address_line' => 'Jl. Melati No. 1', 'village_id' => $villageId,
            'latitude' => -6.9, 'longitude' => 107.6, 'is_default' => true,
        ]);
        $create->assertCreated();
        $this->assertSame('Kelurahan Uji Coba', $create->json('data.village.name'));
        $this->assertSame('Kota Uji Coba', $create->json('data.village.regency'));
        $addressId = $create->json('data.id');

        $this->actingAs($konsumen)->getJson('/api/v1/addresses')->assertOk()->assertJsonCount(1, 'data');

        $update = $this->actingAs($konsumen)->patchJson("/api/v1/addresses/{$addressId}", [
            'recipient_name' => 'Budi Santoso', 'phone' => '0811', 'address_line' => 'Jl. Melati No. 2',
            'village_id' => $villageId, 'latitude' => -6.9, 'longitude' => 107.6,
        ]);
        $update->assertOk()->assertJsonPath('data.recipient_name', 'Budi Santoso');

        $this->actingAs($konsumen)->deleteJson("/api/v1/addresses/{$addressId}")->assertOk();
        $this->actingAs($konsumen)->getJson('/api/v1/addresses')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_konsumen_cannot_read_update_or_delete_another_konsumens_address(): void
    {
        $owner = User::factory()->konsumen()->create(['agent_id' => User::factory()->agen()->create()->id]);
        $other = User::factory()->konsumen()->create(['agent_id' => User::factory()->agen()->create()->id]);
        $villageId = $this->seedTestVillage();

        $addressId = $this->actingAs($owner)->postJson('/api/v1/addresses', [
            'recipient_name' => 'Budi', 'phone' => '0811', 'address_line' => 'Jl. Melati',
            'village_id' => $villageId, 'latitude' => -6.9, 'longitude' => 107.6,
        ])->json('data.id');

        $this->actingAs($other)->getJson('/api/v1/addresses')->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($other)->patchJson("/api/v1/addresses/{$addressId}", [
            'recipient_name' => 'Hacked', 'phone' => '0811', 'address_line' => 'X',
            'village_id' => $villageId, 'latitude' => -6.9, 'longitude' => 107.6,
        ])->assertStatus(404);

        $this->actingAs($other)->deleteJson("/api/v1/addresses/{$addressId}")->assertStatus(404);
    }

    public function test_setting_a_new_default_address_unsets_the_previous_default(): void
    {
        $konsumen = User::factory()->konsumen()->create(['agent_id' => User::factory()->agen()->create()->id]);
        $villageId = $this->seedTestVillage();

        $first = $this->actingAs($konsumen)->postJson('/api/v1/addresses', [
            'recipient_name' => 'A', 'phone' => '0811', 'address_line' => 'Jl. A',
            'village_id' => $villageId, 'latitude' => -6.9, 'longitude' => 107.6, 'is_default' => true,
        ])->json('data.id');

        $this->actingAs($konsumen)->postJson('/api/v1/addresses', [
            'recipient_name' => 'B', 'phone' => '0812', 'address_line' => 'Jl. B',
            'village_id' => $villageId, 'latitude' => -6.9, 'longitude' => 107.6, 'is_default' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('konsumen_addresses', ['id' => $first, 'is_default' => false]);
        $this->assertDatabaseHas('konsumen_addresses', ['recipient_name' => 'B', 'is_default' => true]);
    }

    public function test_address_requires_a_valid_village(): void
    {
        $konsumen = User::factory()->konsumen()->create(['agent_id' => User::factory()->agen()->create()->id]);

        $this->actingAs($konsumen)->postJson('/api/v1/addresses', [
            'recipient_name' => 'Budi', 'phone' => '0811', 'address_line' => 'Jl. Melati',
            'village_id' => '0000000000', 'latitude' => -6.9, 'longitude' => 107.6,
        ])->assertStatus(422)->assertJsonValidationErrors('village_id');
    }
}
