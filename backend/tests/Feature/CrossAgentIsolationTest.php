<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * "Pastikan user dari Agent A tidak dapat membaca data Agent B walaupun user
 * tersebut mencoba memanipulasi parameter API" — every case here attempts
 * exactly that: reaching another agent's data via a guessed/manipulated ID.
 */
class CrossAgentIsolationTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function makeAgentWithStaff(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko', 'address' => 'Jl. X',
            'latitude' => -6.2, 'longitude' => 106.8,
        ]);
        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id]);
        $sales = User::factory()->sales()->create([
            'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'referral_code' => 'S-'.uniqid(),
        ]);
        $konsumen = User::factory()->konsumen()->create([
            'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'sales_id' => $sales->id,
        ]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);

        return compact('agen', 'korsal', 'sales', 'konsumen', 'admin');
    }

    public function test_agen_cannot_fetch_another_agents_user_by_guessing_the_id(): void
    {
        $branchA = $this->makeAgentWithStaff();
        $branchB = $this->makeAgentWithStaff();

        // User carries no auto agent-scope (see BelongsToAgentScope's docblock:
        // applying it to User itself recurses infinitely during auth resolution),
        // so the record resolves and UserPolicy::view is the layer that denies it.
        $this->actingAs($branchA['agen'])
            ->getJson("/api/v1/users/{$branchB['sales']->id}")
            ->assertStatus(403);
    }

    public function test_agen_cannot_see_another_agents_users_in_the_network_listing(): void
    {
        $branchA = $this->makeAgentWithStaff();
        $branchB = $this->makeAgentWithStaff();

        $response = $this->actingAs($branchA['agen'])->getJson('/api/v1/users?per_page=100');

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($branchA['sales']->id, $ids);
        $this->assertNotContains($branchB['sales']->id, $ids);
        $this->assertNotContains($branchB['konsumen']->id, $ids);
    }

    public function test_admin_cannot_view_another_agents_stock_record(): void
    {
        $branchA = $this->makeAgentWithStaff();
        $branchB = $this->makeAgentWithStaff();

        $product = Product::create(['sku' => 'TEST-'.\Illuminate\Support\Str::uuid(), 
            'name' => 'Nastar', 'slug' => 'nastar-'.uniqid(), 'has_variations' => false,
            'base_price' => 40000, 'weight_grams' => 500, 'status' => 'active',
        ]);

        $stockB = ProductStock::create([
            'agent_id' => $branchB['agen']->id, 'product_id' => $product->id,
            'quantity_on_hand' => 50, 'quantity_reserved' => 0,
        ]);

        $this->actingAs($branchA['admin']);
        $found = ProductStock::withoutGlobalScopes()->find($stockB->id); // exists in DB
        $this->assertNotNull($found);

        $scoped = ProductStock::query()->find($stockB->id); // as seen through Agent A admin's session
        $this->assertNull($scoped, 'Agent A must not be able to query Agent B stock at all');
    }

    public function test_korsal_cannot_view_a_sales_users_konsumen_from_a_different_korsal_in_the_same_agent(): void
    {
        $branch = $this->makeAgentWithStaff();

        $otherKorsal = User::factory()->korsal()->create(['agent_id' => $branch['agen']->id]);
        $otherSales = User::factory()->sales()->create([
            'agent_id' => $branch['agen']->id, 'korsal_id' => $otherKorsal->id, 'referral_code' => 'S-'.uniqid(),
        ]);
        $otherKonsumen = User::factory()->konsumen()->create([
            'agent_id' => $branch['agen']->id, 'korsal_id' => $otherKorsal->id, 'sales_id' => $otherSales->id,
        ]);

        // Same agent branch (BelongsToAgentScope alone would let this through) —
        // but a different korsal's downline, so UserPolicy::view must deny it.
        $this->actingAs($branch['korsal'])
            ->getJson("/api/v1/users/{$otherKonsumen->id}")
            ->assertStatus(403);
    }

    private function placeOrder(User $agen, User $konsumen): Order
    {
        $product = Product::create(['sku' => 'TEST-'.\Illuminate\Support\Str::uuid(), 
            'name' => 'Kue Isolasi', 'slug' => Str::slug('Kue Isolasi').'-'.uniqid(),
            'has_variations' => false, 'base_price' => 40000, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);

        $orderId = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ])->json('data.id');

        return Order::withoutGlobalScopes()->findOrFail($orderId);
    }

    public function test_admin_cannot_view_or_change_status_of_another_agents_order(): void
    {
        $branchA = $this->makeAgentWithStaff();
        $branchB = $this->makeAgentWithStaff();
        $orderB = $this->placeOrder($branchB['agen'], $branchB['konsumen']);

        // BelongsToAgentScope hides the row entirely from admin A's query
        // before OrderPolicy even runs — route-model-binding 404s rather
        // than resolving the record and then 403ing, which is the stronger
        // of the two (an attacker can't even confirm the order exists).
        $this->actingAs($branchA['admin'])->getJson("/api/v1/orders/{$orderB->id}")->assertStatus(404);
        $this->actingAs($branchA['admin'])
            ->patchJson("/api/v1/orders/{$orderB->id}/status", ['status' => 'diproses'])
            ->assertStatus(404);
        $this->actingAs($branchA['admin'])
            ->postJson("/api/v1/orders/{$orderB->id}/cancel", ['reason' => 'test'])
            ->assertStatus(404);
    }

    public function test_admin_never_sees_another_agents_data_in_any_report(): void
    {
        $branchA = $this->makeAgentWithStaff();
        $branchB = $this->makeAgentWithStaff();
        $this->placeOrder($branchB['agen'], $branchB['konsumen']);

        $customers = $this->actingAs($branchA['admin'])->getJson('/api/v1/reports/customers');
        $customers->assertOk();
        $this->assertFalse(collect($customers->json('data'))->contains('konsumen_id', $branchB['konsumen']->id));

        $korsal = $this->actingAs($branchA['admin'])->getJson('/api/v1/reports/korsal');
        $korsal->assertOk();
        $this->assertFalse(collect($korsal->json('data'))->contains('korsal_id', $branchB['korsal']->id));

        $sales = $this->actingAs($branchA['admin'])->getJson('/api/v1/reports/sales');
        $sales->assertOk();
        $this->assertFalse(collect($sales->json('data'))->contains('sales_id', $branchB['sales']->id));

        $couriers = $this->actingAs($branchA['admin'])->getJson('/api/v1/reports/couriers');
        $couriers->assertOk();
    }

    public function test_super_admin_can_filter_orders_and_users_by_agent_id_but_admin_cannot_use_it_to_escape_branch(): void
    {
        $branchA = $this->makeAgentWithStaff();
        $branchB = $this->makeAgentWithStaff();
        $orderB = $this->placeOrder($branchB['agen'], $branchB['konsumen']);
        $superAdmin = User::factory()->superAdmin()->create();

        $orders = $this->actingAs($superAdmin)->getJson('/api/v1/orders?agent_id='.$branchB['agen']->id);
        $orders->assertOk();
        $this->assertTrue(collect($orders->json('data'))->contains('id', $orderB->id));

        $ordersOther = $this->actingAs($superAdmin)->getJson('/api/v1/orders?agent_id='.$branchA['agen']->id);
        $ordersOther->assertOk();
        $this->assertFalse(collect($ordersOther->json('data'))->contains('id', $orderB->id));

        $users = $this->actingAs($superAdmin)->getJson('/api/v1/users?agent_id='.$branchB['agen']->id);
        $users->assertOk();
        $this->assertTrue(collect($users->json('data'))->contains('id', $branchB['sales']->id));
        $this->assertFalse(collect($users->json('data'))->contains('id', $branchA['sales']->id));

        // admin sending ?agent_id=<other branch> stays locked to its own branch (never a bypass).
        $adminEscape = $this->actingAs($branchA['admin'])->getJson('/api/v1/users?agent_id='.$branchB['agen']->id);
        $adminEscape->assertOk();
        $this->assertFalse(collect($adminEscape->json('data'))->contains('id', $branchB['sales']->id));
    }
}
