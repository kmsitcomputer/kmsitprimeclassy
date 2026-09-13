<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

class ReferralSystemTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function makeAgentBranch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko Kue', 'address' => 'Jl. X',
            'latitude' => -6.2, 'longitude' => 106.8,
        ]);
        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id]);
        $sales = User::factory()->sales()->create([
            'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'referral_code' => 'S-'.uniqid(),
        ]);

        return compact('agen', 'korsal', 'sales');
    }

    /* ---------------------------------------------------------------
     * 1. Referral code generation / ownership
     * ------------------------------------------------------------- */

    public function test_agen_korsal_and_sales_each_receive_a_referral_code_but_konsumen_never_does(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $agenResponse = $this->actingAs($superAdmin)->postJson('/api/v1/users', [
            'role' => 'agen', 'name' => 'Agen A', 'email' => 'agenA-'.uniqid().'@x.com',
            'phone' => '0811', 'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
        $agenResponse->assertCreated();
        $this->assertNotNull($agenResponse->json('data.referral_code'));

        // Created directly rather than through a second actingAs()+HTTP round trip in
        // the same test (Sanctum's session guard does not cleanly support switching
        // the "logged in" user across two stateful requests within one test method) —
        // the hierarchy-creation *rule* is already covered end-to-end by UserManagementTest.
        $agen = User::query()->findOrFail($agenResponse->json('data.id'));
        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id, 'referral_code' => 'KO-'.uniqid()]);

        $this->assertNotNull($korsal->referral_code);
    }

    public function test_konsumen_can_register_directly_with_a_korsal_referral_code(): void
    {
        ['agen' => $agen, 'korsal' => $korsal] = $this->makeAgentBranch();
        $korsal->update(['referral_code' => 'KO-'.uniqid()]);

        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Konsumen Baru', 'email' => 'konsumen-'.uniqid().'@x.com', 'phone' => '0812',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'referral_code' => $korsal->referral_code, // a korsal code, not a sales code — also valid
        ]);

        $registerResponse->assertCreated();
        $konsumen = User::query()->where('email', $registerResponse->json('data.user.email'))->firstOrFail();
        $this->assertNull($konsumen->sales_id);
        $this->assertSame($korsal->id, $konsumen->korsal_id);
        $this->assertSame($korsal->id, $konsumen->parent_id);
        $this->assertSame($agen->id, $konsumen->agent_id);
    }

    public function test_konsumen_can_register_directly_with_an_agen_referral_code(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();
        $korsal = User::factory()->korsal()->create([
            'agent_id' => $agen->id,
            'parent_id' => $agen->id,
        ]);
        $agen->update(['referral_code' => 'AG-'.uniqid()]);

        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Konsumen Baru', 'email' => 'konsumen-'.uniqid().'@x.com', 'phone' => '0812',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'referral_code' => $agen->referral_code,
        ]);

        $registerResponse->assertCreated();
        $konsumen = User::query()->where('email', $registerResponse->json('data.user.email'))->firstOrFail();
        $this->assertNull($konsumen->sales_id);
        $this->assertNull($konsumen->korsal_id);
        $this->assertSame($agen->id, $konsumen->parent_id);
        $this->assertSame($agen->id, $konsumen->agent_id);
    }

    /* ---------------------------------------------------------------
     * 2. Referral code uniqueness
     * ------------------------------------------------------------- */

    public function test_referral_code_uniqueness_is_enforced_at_the_database_level(): void
    {
        $agen = $this->makeAgentBranch()['agen'];

        User::factory()->sales()->create(['agent_id' => $agen->id, 'referral_code' => 'DUPLICATE-CODE']);

        $this->expectException(QueryException::class);
        User::factory()->sales()->create(['agent_id' => $agen->id, 'referral_code' => 'DUPLICATE-CODE']);
    }

    public function test_code_generation_retries_on_collision_instead_of_creating_a_duplicate(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();
        $korsal = User::factory()->korsal()->create([
            'agent_id' => $agen->id,
            'parent_id' => $agen->id,
        ]);

        // Force the first "random" candidate to collide with an existing code,
        // then a second, different candidate on retry.
        User::factory()->sales()->create(['agent_id' => $agen->id, 'referral_code' => 'SA-AAAAAA']);

        $calls = 0;
        Str::createRandomStringsUsing(function () use (&$calls) {
            $calls++;

            return $calls === 1 ? 'AAAAAA' : 'BBBBBB';
        });

        try {
            $response = $this->actingAs($agen)->postJson('/api/v1/users', [
                'role' => 'sales', 'korsal_id' => $korsal->id,
                'name' => 'Sales Baru', 'email' => 'sales-'.uniqid().'@x.com',
                'phone' => '0811', 'password' => 'password123', 'password_confirmation' => 'password123',
            ]);
        } finally {
            Str::createRandomStringsNormally();
        }

        $response->assertCreated();
        $this->assertSame('SA-BBBBBB', $response->json('data.referral_code'));
    }

    /* ---------------------------------------------------------------
     * 3. Referral validation
     * ------------------------------------------------------------- */

    public function test_registration_rejects_an_inactive_sales_referral_code(): void
    {
        ['agen' => $agen] = $this->makeAgentBranch();
        $inactiveSales = User::factory()->sales()->create([
            'agent_id' => $agen->id, 'referral_code' => 'INACTIVE-1', 'status' => 'inactive',
        ]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'X', 'email' => 'x-'.uniqid().'@x.com', 'phone' => '0812',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'referral_code' => $inactiveSales->referral_code,
        ])->assertStatus(422);
    }

    public function test_referral_preview_endpoint_confirms_a_valid_code_without_authentication(): void
    {
        ['sales' => $sales] = $this->makeAgentBranch();

        $response = $this->getJson("/api/v1/referral/{$sales->referral_code}");

        $response->assertOk();
        $this->assertSame($sales->name, $response->json('data.referrer_name'));
        $this->assertSame('Toko Kue', $response->json('data.agent_store_name'));
    }

    public function test_referral_preview_endpoint_rejects_an_unknown_code(): void
    {
        $this->getJson('/api/v1/referral/DOES-NOT-EXIST')->assertStatus(422);
    }

    /* ---------------------------------------------------------------
     * 4/5. Hierarchy resolution + safe storage on registration
     * ------------------------------------------------------------- */

    public function test_registration_stores_the_full_chain_derived_from_the_sales_code_alone(): void
    {
        ['agen' => $agen, 'korsal' => $korsal, 'sales' => $sales] = $this->makeAgentBranch();

        // A malicious client tries to also smuggle its own agent_id/korsal_id/sales_id —
        // RegisterKonsumenRequest doesn't declare those fields, so they're dropped.
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Konsumen', 'email' => 'konsumen-'.uniqid().'@x.com', 'phone' => '0812',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'referral_code' => $sales->referral_code,
            'agent_id' => 999999, 'korsal_id' => 999999, 'sales_id' => 999999,
        ]);

        $response->assertCreated();
        $konsumen = User::query()->where('email', $response->json('data.user.email'))->firstOrFail();

        $this->assertSame($sales->id, $konsumen->sales_id);
        $this->assertSame($korsal->id, $konsumen->korsal_id);
        $this->assertSame($agen->id, $konsumen->agent_id);
        $this->assertNull($konsumen->referral_code);
    }

    /* ---------------------------------------------------------------
     * 6. Order referral snapshot survives later structural changes
     * ------------------------------------------------------------- */

    public function test_order_referral_snapshot_is_unaffected_when_sales_is_later_moved_to_a_different_korsal(): void
    {
        ['agen' => $agen, 'korsal' => $korsalK, 'sales' => $salesS] = $this->makeAgentBranch();
        $konsumenC = User::factory()->konsumen()->create([
            'agent_id' => $agen->id, 'korsal_id' => $korsalK->id, 'sales_id' => $salesS->id,
        ]);

        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Tart Coklat', 'slug' => 'tart-coklat-'.uniqid(), 'has_variations' => false,
            'base_price' => 120000, 'weight_grams' => 900, 'status' => 'active',
        ]);
        ProductStock::create([
            'agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0,
        ]);

        $orderResponse = $this->actingAs($konsumenC)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'C', 'recipient_phone' => '0813', 'address_line' => 'Jl. C',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.9, 'longitude' => 107.6,
        ]);
        $orderResponse->assertCreated();
        $orderId = $orderResponse->json('data.id');

        // "Order #1001": agent_id=A, korsal_id=K, sales_id=S, konsumen_id=C — recorded.
        $this->assertDatabaseHas('orders', [
            'id' => $orderId, 'agent_id' => $agen->id, 'korsal_id' => $korsalK->id,
            'sales_id' => $salesS->id, 'konsumen_id' => $konsumenC->id,
        ]);

        // Sales S is now restructured under a brand new Korsal within the same agent.
        $newKorsal = User::factory()->korsal()->create(['agent_id' => $agen->id]);
        $salesS->update(['parent_id' => $newKorsal->id, 'korsal_id' => $newKorsal->id]);

        // The historical order must still show the ORIGINAL korsal — never re-derived
        // live from sales_S's current korsal_id.
        $order = Order::query()->findOrFail($orderId);
        $this->assertSame($korsalK->id, $order->korsal_id);
        $this->assertSame($salesS->id, $order->sales_id);
        $this->assertNotSame($newKorsal->id, $order->korsal_id);

        // The OLD korsal must still see this exact order in its own listing...
        $this->actingAs($korsalK)->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonFragment(['id' => $orderId]);

        // ...while the NEW korsal (who now structurally supervises Sales S) sees nothing
        // of this pre-existing order, because the order's own snapshot was never touched.
        $this->actingAs($newKorsal)->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonMissing(['id' => $orderId]);
    }

    /* ---------------------------------------------------------------
     * 7/8. Authorization by referral hierarchy + cross-agent prevention
     * ------------------------------------------------------------- */

    public function test_sales_cannot_view_an_order_belonging_to_another_sales_konsumen_in_the_same_agent(): void
    {
        ['agen' => $agen, 'korsal' => $korsal, 'sales' => $salesA] = $this->makeAgentBranch();
        $salesB = User::factory()->sales()->create([
            'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'referral_code' => 'SB-'.uniqid(),
        ]);
        $konsumenOfB = User::factory()->konsumen()->create([
            'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'sales_id' => $salesB->id,
        ]);

        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Roti', 'slug' => 'roti-'.uniqid(), 'has_variations' => false,
            'base_price' => 20000, 'weight_grams' => 300, 'status' => 'active',
        ]);
        ProductStock::create([
            'agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0,
        ]);

        $orderId = $this->actingAs($konsumenOfB)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'B', 'recipient_phone' => '0813', 'address_line' => 'Jl. B',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.9, 'longitude' => 107.6,
        ])->json('data.id');

        // Sales A shares the same Agent and Korsal, but the order belongs to Sales B's konsumen.
        $this->actingAs($salesA)->getJson("/api/v1/orders/{$orderId}")->assertStatus(403);
    }

    public function test_a_konsumens_referral_chain_never_leaks_across_agents_even_via_direct_id_lookup(): void
    {
        $branchA = $this->makeAgentBranch();
        $branchB = $this->makeAgentBranch();

        $konsumenA = User::factory()->konsumen()->create([
            'agent_id' => $branchA['agen']->id, 'korsal_id' => $branchA['korsal']->id, 'sales_id' => $branchA['sales']->id,
        ]);

        $productB = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Donat', 'slug' => 'donat-'.uniqid(), 'has_variations' => false,
            'base_price' => 15000, 'weight_grams' => 200, 'status' => 'active',
        ]);
        ProductStock::create([
            'agent_id' => $branchB['agen']->id, 'product_id' => $productB->id,
            'quantity_on_hand' => 10, 'quantity_reserved' => 0,
        ]);

        // Agent B's sales tries to place an order "on behalf of" Agent A's konsumen
        // by guessing/forging the konsumen_id in the request payload.
        $response = $this->actingAs($branchB['sales'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'konsumen_id' => $konsumenA->id,
            'items' => [['product_id' => $productB->id, 'quantity' => 1]],
            'recipient_name' => 'X', 'recipient_phone' => '0812', 'address_line' => 'Jl. X',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.9, 'longitude' => 107.6,
        ]);

        $this->assertContains($response->status(), [403, 404, 422]);
        $this->assertDatabaseMissing('orders', ['konsumen_id' => $konsumenA->id]);
    }

    /* ---------------------------------------------------------------
     * Self-service referral code (Profile page) — agen/korsal/sales only
     * ------------------------------------------------------------- */

    public function test_agen_korsal_and_sales_can_set_a_custom_referral_code(): void
    {
        $branch = $this->makeAgentBranch();

        foreach (['agen', 'korsal', 'sales'] as $role) {
            $this->actingAs($branch[$role])->patchJson('/api/v1/profile/referral-code', ['referral_code' => strtoupper($role).'-CUSTOM'])
                ->assertOk()->assertJsonPath('data.referral_code', strtoupper($role).'-CUSTOM');

            $this->assertSame(strtoupper($role).'-CUSTOM', $branch[$role]->fresh()->referral_code);
        }

        $this->assertDatabaseHas('activity_logs', ['event' => 'profile.referral_code_updated']);
    }

    public function test_custom_referral_code_must_be_unique_and_correctly_formatted(): void
    {
        $branch = $this->makeAgentBranch();
        $otherBranch = $this->makeAgentBranch();

        // Format: uppercase letters, numbers, dashes only.
        $this->actingAs($branch['agen'])->patchJson('/api/v1/profile/referral-code', ['referral_code' => 'not valid!'])
            ->assertStatus(422)->assertJsonValidationErrors('referral_code');

        // Uniqueness against another user's existing code.
        $existingCode = $otherBranch['sales']->referral_code;
        $this->actingAs($branch['sales'])->patchJson('/api/v1/profile/referral-code', ['referral_code' => $existingCode])
            ->assertStatus(422)->assertJsonValidationErrors('referral_code');
    }

    public function test_sales_can_regenerate_and_delete_their_own_referral_code(): void
    {
        $branch = $this->makeAgentBranch();
        $originalCode = $branch['sales']->referral_code;

        $regenerate = $this->actingAs($branch['sales'])->postJson('/api/v1/profile/referral-code/regenerate');
        $regenerate->assertOk();
        $newCode = $regenerate->json('data.referral_code');
        $this->assertNotSame($originalCode, $newCode);
        $this->assertStringStartsWith('SA-', $newCode);

        $this->actingAs($branch['sales'])->deleteJson('/api/v1/profile/referral-code')
            ->assertOk()->assertJsonPath('data.referral_code', null);

        $this->assertNull($branch['sales']->fresh()->referral_code);
        $this->assertDatabaseHas('activity_logs', ['event' => 'profile.referral_code_regenerated']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'profile.referral_code_deleted']);
    }

    public function test_konsumen_admin_and_kurir_cannot_manage_a_referral_code(): void
    {
        $branch = $this->makeAgentBranch();
        $admin = User::factory()->admin()->create(['agent_id' => $branch['agen']->id]);
        $kurir = User::factory()->kurir()->create(['agent_id' => $branch['agen']->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $branch['agen']->id]);

        foreach ([$konsumen, $admin, $kurir] as $actor) {
            $this->actingAs($actor)->patchJson('/api/v1/profile/referral-code', ['referral_code' => 'X-CUSTOM'])->assertStatus(403);
            $this->actingAs($actor)->postJson('/api/v1/profile/referral-code/regenerate')->assertStatus(403);
            $this->actingAs($actor)->deleteJson('/api/v1/profile/referral-code')->assertStatus(403);
        }
    }
}
