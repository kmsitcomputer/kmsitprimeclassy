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

    public function test_login_requires_correct_credentials(): void
    {
        $agen = User::factory()->agen()->create();
        $sales = User::factory()->sales()->create(['agent_id' => $agen->id, 'referral_code' => 'S1']);
        $konsumen = User::factory()->konsumen()->create([
            'agent_id' => $agen->id, 'sales_id' => $sales->id, 'password' => bcrypt('correct-password'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $konsumen->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);

        $this->postJson('/api/v1/auth/login', [
            'email' => $konsumen->email,
            'password' => 'correct-password',
        ])->assertOk()->assertJsonPath('success', true);
    }
}
