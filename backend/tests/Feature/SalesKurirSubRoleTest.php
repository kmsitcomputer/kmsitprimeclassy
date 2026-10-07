<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Referral\ReferralService;
use App\Services\User\UserManagementService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-01 — sales-kurir evolves into sales-kurir-sub without a new role, a user rewrite or a referral rewrite. */
class SalesKurirSubRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function runRenameMigration(): void
    {
        (require database_path('migrations/2026_09_29_090000_rename_sales_kurir_role_to_sales_kurir_sub.php'))->up();
    }

    /** Puts the roles table into its exact pre-R-01 production shape (role id kept, legacy slug/name). */
    private function revertToLegacyRole(): int
    {
        $id = (int) DB::table('roles')->where('slug', 'sales-kurir-sub')->value('id');
        DB::table('roles')->where('id', $id)->update(['slug' => 'sales-kurir', 'name' => 'Sales-Kurir']);

        return $id;
    }

    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);

        return [$agen, $korsal];
    }

    public function test_migration_renames_the_existing_role_row_in_place(): void
    {
        [$agen, $korsal] = $this->branch();
        $roleId = $this->revertToLegacyRole();
        $legacyUser = User::factory()->create(['role_id' => $roleId, 'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'parent_id' => $korsal->id, 'referral_code' => 'SK-OLD001']);

        $this->runRenameMigration();
        $this->runRenameMigration(); // idempotent

        $this->assertSame(11, Role::query()->count());
        $this->assertDatabaseMissing('roles', ['slug' => 'sales-kurir']);
        $this->assertDatabaseHas('roles', ['id' => $roleId, 'slug' => 'sales-kurir-sub', 'name' => 'Sales-Kurir-Sub']);
        $legacyUser->refresh();
        $this->assertSame($roleId, $legacyUser->role_id, 'existing user keeps the very same role relationship');
        $this->assertSame('SK-OLD001', $legacyUser->referral_code, 'historical SK-* code is not rewritten');
        $this->assertTrue($legacyUser->isRole('sales-kurir-sub'));
    }

    public function test_migration_folds_a_duplicate_legacy_row_instead_of_leaving_an_eleventh_role(): void
    {
        [$agen, $korsal] = $this->branch();
        $canonicalId = (int) DB::table('roles')->where('slug', 'sales-kurir-sub')->value('id');
        $legacyId = DB::table('roles')->insertGetId(['slug' => 'sales-kurir', 'name' => 'Sales-Kurir', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create(['role_id' => $legacyId, 'agent_id' => $agen->id, 'korsal_id' => $korsal->id]);

        $this->runRenameMigration();

        $this->assertSame(11, Role::query()->count());
        $this->assertSame($canonicalId, $user->fresh()->role_id);
    }

    public function test_seeder_renames_the_legacy_row_and_never_creates_an_eleventh_role(): void
    {
        $roleId = $this->revertToLegacyRole();

        $this->seed(RoleSeeder::class);

        $this->assertSame(11, Role::query()->count());
        $this->assertDatabaseHas('roles', ['id' => $roleId, 'slug' => 'sales-kurir-sub']);
    }

    public function test_legacy_slug_is_still_recognised_before_the_migration_has_run(): void
    {
        [$agen, $korsal] = $this->branch();
        $this->revertToLegacyRole();
        $legacyUser = User::factory()->create(['role_id' => Role::query()->where('slug', 'sales-kurir')->value('id'), 'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'parent_id' => $korsal->id]);

        $this->assertTrue($legacyUser->isRole('sales-kurir-sub'));
        $this->assertTrue($legacyUser->isRole('sales-kurir'));
        $this->assertFalse($legacyUser->isRole('sales'));
        $this->actingAs($legacyUser)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.role', 'sales-kurir-sub');
    }

    public function test_new_sales_kurir_sub_gets_an_ss_referral_code_and_agen_and_korsal_can_create_it(): void
    {
        [$agen, $korsal] = $this->branch();
        $service = app(UserManagementService::class);
        $data = fn () => ['name' => 'Sub', 'email' => uniqid().'@example.com', 'phone' => '0812', 'password' => 'password123', 'korsal_id' => $korsal->id];

        $byAgen = $service->create($agen, 'sales-kurir-sub', $data());
        $byKorsal = $service->create($korsal, 'sales-kurir-sub', ['korsal_id' => $korsal->id] + $data());
        $legacyPayload = $service->create($agen, 'sales-kurir', $data());

        foreach ([$byAgen, $byKorsal, $legacyPayload] as $user) {
            $this->assertStringStartsWith('SS-', $user->referral_code);
            $this->assertSame('sales-kurir-sub', $user->role->slug);
            $this->assertSame($agen->id, $user->agent_id);
            $this->assertSame($korsal->id, $user->korsal_id);
        }
    }

    public function test_conversion_preserves_a_historical_sales_referral_code_and_a_missing_code_becomes_ss(): void
    {
        [$agen, $korsal] = $this->branch();
        $withCode = User::factory()->sales()->create(['agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'parent_id' => $korsal->id, 'referral_code' => 'SA-HIST01']);
        $withoutCode = User::factory()->sales()->create(['agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'parent_id' => $korsal->id, 'referral_code' => null]);

        $this->actingAs($agen)->patchJson("/api/v1/users/{$withCode->id}/convert-to-sales-kurir-sub")->assertOk();
        $this->actingAs($agen)->patchJson("/api/v1/users/{$withoutCode->id}/convert-to-sales-kurir")->assertOk(); // legacy alias route

        $this->assertSame('SA-HIST01', $withCode->fresh()->referral_code);
        $this->assertStringStartsWith('SS-', $withoutCode->fresh()->referral_code);
    }

    public function test_historical_sk_and_sa_codes_still_resolve_referral_chains(): void
    {
        [$agen, $korsal] = $this->branch();
        $sk = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'parent_id' => $korsal->id, 'referral_code' => 'SK-HIST01']);
        $sa = User::factory()->sales()->create(['agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'parent_id' => $korsal->id, 'referral_code' => 'SA-HIST02']);

        $skChain = app(ReferralService::class)->resolveChainByCode('sk-hist01');
        $saChain = app(ReferralService::class)->resolveChainByCode('SA-HIST02');

        $this->assertSame($sk->id, $skChain['sales_id']);
        $this->assertSame($sa->id, $saChain['sales_id']);
        $this->assertSame($sk->id, $sk->fresh()->id);
        $this->assertSame('SK-HIST01', $sk->fresh()->referral_code);
    }

    public function test_role_taxonomy_is_exactly_the_eleven_business_roles(): void
    {
        $this->assertSame(
            ['admin', 'agen', 'gudang', 'keuangan', 'konsumen', 'koordinator-kurir', 'korsal', 'kurir', 'sales', 'sales-kurir-sub', 'super_admin'],
            Role::query()->pluck('slug')->sort()->values()->all()
        );
    }
}
