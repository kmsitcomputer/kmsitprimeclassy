<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `?ref=CODE` referral-link flow (Blueprint "REFERRAL LINK OTOMATIS"):
 * registration works with no referral at all, an omitted/empty code never
 * crashes, a typed-lowercase code still resolves, and a code belonging to a
 * non-referral role (admin/kurir/konsumen) can never be used as a referrer
 * even if one somehow ended up on such an account.
 */
class ReferralLinkRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function makeAgentBranch(): array
    {
        $agen = User::factory()->agen()->create(['referral_code' => 'AG-'.uniqid()]);
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko QA', 'address' => 'Jl. QA',
            'latitude' => -6.2, 'longitude' => 106.8,
        ]);

        return compact('agen');
    }

    public function test_registration_without_any_referral_code_still_succeeds(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Konsumen Tanpa Referral', 'email' => 'no-ref-'.uniqid().'@x.com', 'phone' => '0812',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);

        $response->assertCreated();
        $konsumen = User::query()->where('email', $response->json('data.user.email'))->firstOrFail();
        $this->assertNull($konsumen->agent_id);
        $this->assertNull($konsumen->korsal_id);
        $this->assertNull($konsumen->sales_id);
        $this->assertNull($konsumen->parent_id);
        $this->assertSame('konsumen', $konsumen->role->slug);
    }

    public function test_registration_with_an_empty_referral_code_string_still_succeeds(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Konsumen Kosong', 'email' => 'empty-ref-'.uniqid().'@x.com', 'phone' => '0812',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'referral_code' => '',
        ]);

        $response->assertCreated();
    }

    public function test_referral_code_lookup_is_case_insensitive(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Konsumen Lowercase', 'email' => 'lower-ref-'.uniqid().'@x.com', 'phone' => '0812',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'referral_code' => strtolower($agen->referral_code),
        ]);

        $response->assertCreated();
        $konsumen = User::query()->where('email', $response->json('data.user.email'))->firstOrFail();
        $this->assertSame($agen->id, $konsumen->agent_id);
    }

    public function test_a_code_accidentally_present_on_a_non_referral_role_cannot_be_used(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();
        // Not achievable through normal app flows (ProfileController gates this
        // to agen/korsal/sales) — simulated here to prove the LOOKUP itself,
        // not just the write-path, rejects any other role as a referrer.
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id, 'referral_code' => 'ADMIN-CODE']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'X', 'email' => 'x-'.uniqid().'@x.com', 'phone' => '0812',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'referral_code' => $admin->referral_code,
        ])->assertStatus(422);
    }

    public function test_referral_does_not_change_the_registering_users_role(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Konsumen Role Check', 'email' => 'role-'.uniqid().'@x.com', 'phone' => '0812',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'referral_code' => $agen->referral_code,
        ]);

        $response->assertCreated();
        $this->assertSame('konsumen', $response->json('data.user.role'));
        $this->assertSame('agen', $agen->fresh()->role->slug);
    }

    public function test_logged_in_user_visiting_a_referral_link_is_not_reassigned(): void
    {
        ['agen' => $agenA] = $this->makeAgentBranch();
        $agenB = User::factory()->agen()->create(['referral_code' => 'AG-B-'.uniqid()]);
        $agenB->update(['agent_id' => $agenB->id]);

        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agenA->id]);

        // Referral onboarding is a registration-time concern only — merely
        // previewing/knowing another agent's code while logged in must never
        // touch the existing account's hierarchy fields.
        $this->actingAs($konsumen)->getJson("/api/v1/referral/{$agenB->referral_code}")->assertOk();

        $this->assertSame($agenA->id, $konsumen->fresh()->agent_id);
    }
}
