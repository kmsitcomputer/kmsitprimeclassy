<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_response_envelope_has_the_standard_shape(): void
    {
        $response = $this->getJson('/api/v1/products');

        $response->assertOk()->assertJsonStructure([
            'success', 'message', 'data', 'errors', 'meta',
        ]);

        $this->assertTrue($response->json('success'));
        $this->assertNull($response->json('errors'));
    }

    public function test_konsumen_can_register_using_a_valid_sales_referral_code(): void
    {
        $agen = User::factory()->agen()->create();
        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id]);
        $sales = User::factory()->sales()->create([
            'agent_id' => $agen->id,
            'korsal_id' => $korsal->id,
            'referral_code' => 'SALES001',
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'phone' => '081234567890',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'referral_code' => 'SALES001',
        ]);

        $response->assertCreated();
        $this->assertTrue($response->json('success'));

        $konsumen = User::query()->where('email', 'budi@example.com')->firstOrFail();
        $this->assertSame($sales->id, $konsumen->sales_id);
        $this->assertSame($korsal->id, $konsumen->korsal_id);
        $this->assertSame($agen->id, $konsumen->agent_id);
    }

    public function test_registration_without_referral_uses_active_agen_role(): void
    {
        $sales = User::factory()->sales()->create([
            'status' => 'active',
        ]);

        $inactiveAgen = User::factory()->agen()->create([
            'status' => 'inactive',
        ]);

        $activeAgen = User::factory()->agen()->create([
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Konsumen Tanpa Referral',
            'email' => 'tanpa.referral@example.com',
            'phone' => '081298765432',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated();

        $konsumen = User::query()
            ->where('email', 'tanpa.referral@example.com')
            ->firstOrFail();

        $this->assertSame('konsumen', $konsumen->role->slug);
        $this->assertSame($activeAgen->id, $konsumen->agent_id);
        $this->assertSame($activeAgen->id, $konsumen->parent_id);
        $this->assertNull($konsumen->korsal_id);
        $this->assertNull($konsumen->sales_id);
        $this->assertNotEquals($sales->id, $konsumen->agent_id);
        $this->assertNotEquals($inactiveAgen->id, $konsumen->agent_id);
    }

    public function test_registration_fails_with_invalid_referral_code(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Budi Santoso',
            'email' => 'budi2@example.com',
            'phone' => '081234567890',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'referral_code' => 'DOES-NOT-EXIST',
        ]);

        $response->assertStatus(422);
        $this->assertFalse($response->json('success'));
        $this->assertDatabaseMissing('users', ['email' => 'budi2@example.com']);
    }

    public function test_registration_rejects_duplicate_phone_after_normalization(): void
    {
        User::factory()->konsumen()->create([
            'phone' => '081234567890',
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Budi Santoso',
            'email' => 'budi.duplicate@example.com',
            'phone' => '+6281234567890',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['phone']);
        $this->assertDatabaseMissing('users', [
            'email' => 'budi.duplicate@example.com',
        ]);
    }

    public function test_login_accepts_normalized_phone_identifiers(): void
    {
        $konsumen = User::factory()->konsumen()->create([
            'phone' => '081234567890',
            'password' => bcrypt('correct-password'),
        ]);

        foreach ([
            '081234567890',
            '6281234567890',
            '+6281234567890',
        ] as $identifier) {
            $this->postJson('/api/v1/auth/login', [
                'identifier' => $identifier,
                'password' => 'correct-password',
            ])->assertOk()->assertJsonPath('success', true);
        }

        $this->assertSame(
            '081234567890',
            $konsumen->fresh()->phone
        );
    }

    public function test_login_requires_correct_credentials(): void
    {
        $agen = User::factory()->agen()->create();
        $sales = User::factory()->sales()->create(['agent_id' => $agen->id, 'referral_code' => 'S1']);
        $konsumen = User::factory()->konsumen()->create([
            'agent_id' => $agen->id, 'sales_id' => $sales->id, 'password' => bcrypt('correct-password'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => $konsumen->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => $konsumen->email,
            'password' => 'correct-password',
        ])->assertOk()->assertJsonPath('success', true);
    }
}
