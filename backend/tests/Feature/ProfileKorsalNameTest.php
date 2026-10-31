<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Akun Saya — a Sales / Sales-Kurir-Sub sees the name of their assigned Korsal
 * (UserResource `korsal_name`): own record only, from the canonical korsal_id,
 * only when that user is a Korsal of the same branch, never writable.
 */
class ProfileKorsalNameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** @return array{agen:User, korsal:User} */
    private function branch(string $korsalName = 'Korsal Bandung'): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        $korsal = User::factory()->korsal()->create(['name' => $korsalName, 'agent_id' => $agen->id, 'parent_id' => $agen->id]);

        return compact('agen', 'korsal');
    }

    private function seller(string $state, User $agen, ?User $korsal): User
    {
        return User::factory()->{$state}()->create([
            'agent_id' => $agen->id, 'parent_id' => $korsal?->id ?? $agen->id, 'korsal_id' => $korsal?->id,
        ]);
    }

    public function test_sales_sees_their_assigned_korsal_name(): void
    {
        $b = $this->branch();
        $sales = $this->seller('sales', $b['agen'], $b['korsal']);

        $this->actingAs($sales)->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.user.korsal_name', 'Korsal Bandung');
    }

    public function test_sales_kurir_sub_sees_their_assigned_korsal_name(): void
    {
        $b = $this->branch('Korsal Cimahi');
        $sub = $this->seller('salesKurirSub', $b['agen'], $b['korsal']);

        $this->actingAs($sub)->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.user.korsal_name', 'Korsal Cimahi');
    }

    public function test_sales_and_sales_kurir_sub_without_korsal_get_null(): void
    {
        $b = $this->branch();

        foreach (['sales', 'salesKurirSub'] as $state) {
            $seller = $this->seller($state, $b['agen'], null);
            $user = $this->actingAs($seller)->getJson('/api/v1/auth/me')->assertOk()->json('data.user');
            $this->assertArrayHasKey('korsal_name', $user, $state);
            $this->assertNull($user['korsal_name'], $state);
        }
    }

    public function test_korsal_id_that_is_not_a_korsal_of_the_same_branch_never_leaks_a_name(): void
    {
        $b = $this->branch();
        $other = $this->branch('Korsal Cabang Lain');

        // Cross-branch Korsal.
        $sales = $this->seller('sales', $b['agen'], $other['korsal']);
        $this->actingAs($sales)->getJson('/api/v1/auth/me')->assertJsonPath('data.user.korsal_name', null);

        // Same branch, but the referenced user is not a Korsal.
        $peer = User::factory()->sales()->create(['name' => 'Bukan Korsal', 'agent_id' => $b['agen']->id, 'parent_id' => $b['agen']->id]);
        $sales2 = User::factory()->sales()->create(['agent_id' => $b['agen']->id, 'parent_id' => $b['agen']->id, 'korsal_id' => $peer->id]);
        $this->actingAs($sales2)->getJson('/api/v1/auth/me')->assertJsonPath('data.user.korsal_name', null);
    }

    public function test_other_roles_never_receive_korsal_name(): void
    {
        $b = $this->branch();
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $b['agen']->id, 'korsal_id' => $b['korsal']->id]);

        foreach (['agen' => $b['agen'], 'korsal' => $b['korsal'], 'konsumen' => $konsumen] as $role => $user) {
            $payload = $this->actingAs($user)->getJson('/api/v1/auth/me')->assertOk()->json('data.user');
            $this->assertArrayNotHasKey('korsal_name', $payload, $role);
        }
    }

    public function test_korsal_name_is_only_on_the_own_record_not_when_staff_view_a_seller(): void
    {
        $b = $this->branch();
        $sales = $this->seller('sales', $b['agen'], $b['korsal']);

        $viewed = $this->actingAs($b['agen'])->getJson("/api/v1/users/{$sales->id}")->assertOk()->json('data');
        $this->assertSame($sales->id, $viewed['id']);
        $this->assertArrayNotHasKey('korsal_name', $viewed);
    }

    public function test_profile_update_never_changes_the_korsal_assignment(): void
    {
        $b = $this->branch();
        $other = $this->branch('Korsal Cabang Lain');
        $sameBranchKorsal = User::factory()->korsal()->create(['name' => 'Korsal Kedua', 'agent_id' => $b['agen']->id, 'parent_id' => $b['agen']->id]);
        $sales = $this->seller('sales', $b['agen'], $b['korsal']);

        $this->actingAs($sales)->patchJson('/api/v1/profile', [
            'name' => 'Sales Baru',
            'korsal_id' => $sameBranchKorsal->id,
            'korsal_name' => 'Palsu',
            'parent_id' => $other['korsal']->id,
            'agent_id' => $other['agen']->id,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Sales Baru')
            ->assertJsonPath('data.korsal_name', 'Korsal Bandung');

        $sales->refresh();
        $this->assertSame($b['korsal']->id, $sales->korsal_id);
        $this->assertSame($b['korsal']->id, $sales->parent_id);
        $this->assertSame($b['agen']->id, $sales->agent_id);
    }
}
