<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Product;
use App\Models\ProductFee;
use App\Models\ProductStock;
use App\Models\ShippingConfiguration;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

class OrderTest extends TestCase
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
        AgentProfile::create([
            'user_id' => $agen->id,
            'store_name' => 'Toko Kue Jaya',
            'address' => 'Jl. Merdeka No. 1',
            'latitude' => -6.200000,
            'longitude' => 106.816666,
        ]);

        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id]);
        $sales = User::factory()->sales()->create([
            'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'referral_code' => 'SALES-'.uniqid(),
        ]);
        $konsumen = User::factory()->konsumen()->create([
            'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'sales_id' => $sales->id,
        ]);

        ShippingConfiguration::create([
            'agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0,
            'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true,
        ]);

        return compact('agen', 'korsal', 'sales', 'konsumen');
    }

    private function makeProduct(User $agen, int $price, int $stockQty, int $agentFee = 0, int $salesFee = 0): Product
    {
        $product = Product::create(['sku' => 'TEST-'.\Illuminate\Support\Str::uuid(), 
            'name' => 'Black Forest Cake', 'slug' => 'black-forest-cake-'.uniqid(),
            'has_variations' => false, 'base_price' => $price, 'weight_grams' => 1000, 'status' => 'active',
        ]);

        ProductStock::create([
            'agent_id' => $agen->id, 'product_id' => $product->id,
            'quantity_on_hand' => $stockQty, 'quantity_reserved' => 0,
        ]);

        if ($agentFee > 0) {
            ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'agent', 'amount' => $agentFee, 'is_active' => true]);
        }
        if ($salesFee > 0) {
            ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'sales', 'amount' => $salesFee, 'is_active' => true]);
        }

        return $product;
    }

    public function test_order_total_is_computed_server_side_and_ignores_client_submitted_amounts(): void
    {
        ['agen' => $agen, 'sales' => $sales, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, price: 150000, stockQty: 10, agentFee: 10000, salesFee: 5000);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [
                // A malicious client also sends fabricated price/fee/total fields —
                // StoreOrderRequest doesn't even declare them as valid input, so
                // they are silently dropped, never reaching OrderService.
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 1, 'total_amount' => 1],
            ],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810, // Bandung — real distance from Jakarta
        ]);

        $response->assertCreated();
        $data = $response->json('data');
        $this->assertDatabaseHas('order_items', ['order_id' => $data['id'], 'product_id' => $product->id, 'sku_snapshot' => $product->sku]);

        $this->assertEquals(300000, (float) $data['subtotal_amount']); // 150000 * 2, never "1"
        $this->assertEquals(0, (float) $data['shipping_fee_amount']); // no shipping provider active -> free shipping
        $this->assertEquals(
            (float) $data['subtotal_amount'] + (float) $data['shipping_fee_amount'] + (float) $data['admin_fee_amount'],
            (float) $data['total_amount']
        );

        // Fee is per unit, same as price — quantity 2 doubles it, same as subtotal.
        $this->assertDatabaseHas('commissions', [
            'beneficiary_user_id' => $agen->id, 'beneficiary_role' => 'agent', 'amount' => 20000,
        ]);
        $this->assertDatabaseHas('commissions', [
            'beneficiary_user_id' => $sales->id, 'beneficiary_role' => 'sales', 'amount' => 10000,
        ]);
    }

    public function test_order_is_rejected_when_stock_is_insufficient(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, price: 100000, stockQty: 1);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.9, 'longitude' => 107.6,
        ]);

        $response->assertStatus(422);
        $this->assertFalse($response->json('success'));
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('product_stocks', ['product_id' => $product->id, 'quantity_reserved' => 0]);
    }

    public function test_two_agents_stock_for_the_same_product_never_mix(): void
    {
        $branchA = $this->makeAgentBranch();
        $branchB = $this->makeAgentBranch();

        $product = Product::create(['sku' => 'TEST-'.\Illuminate\Support\Str::uuid(), 
            'name' => 'Red Velvet', 'slug' => 'red-velvet-shared', 'has_variations' => false,
            'base_price' => 80000, 'weight_grams' => 800, 'status' => 'active',
        ]);

        ProductStock::create(['agent_id' => $branchA['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);
        ProductStock::create(['agent_id' => $branchB['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 25, 'quantity_reserved' => 0]);

        $this->actingAs($branchA['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
            'recipient_name' => 'A', 'recipient_phone' => '0811', 'address_line' => 'Jl. A',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.9, 'longitude' => 107.6,
        ])->assertCreated();

        $this->assertDatabaseHas('product_stocks', [
            'agent_id' => $branchA['agen']->id, 'product_id' => $product->id, 'quantity_reserved' => 4,
        ]);
        // Agent B's stock for the exact same product is completely untouched.
        $this->assertDatabaseHas('product_stocks', [
            'agent_id' => $branchB['agen']->id, 'product_id' => $product->id,
            'quantity_on_hand' => 25, 'quantity_reserved' => 0,
        ]);
    }

    public function test_konsumen_cannot_view_another_agents_order(): void
    {
        $branchA = $this->makeAgentBranch();
        $branchB = $this->makeAgentBranch();
        $product = $this->makeProduct($branchA['agen'], price: 50000, stockQty: 10);

        $order = $this->actingAs($branchA['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'A', 'recipient_phone' => '0811', 'address_line' => 'Jl. A',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.9, 'longitude' => 107.6,
        ])->json('data');

        // A konsumen belonging to a *different* agent branch cannot even see it exists.
        $this->actingAs($branchB['konsumen'])
            ->getJson("/api/v1/orders/{$order['id']}")
            ->assertStatus(404);
    }

    public function test_admin_and_kurir_may_never_create_an_order(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], price: 50000, stockQty: 10);
        $admin = User::factory()->admin()->create(['agent_id' => $branch['agen']->id]);
        $kurir = User::factory()->kurir()->create(['agent_id' => $branch['agen']->id]);

        $payload = [
            'payment_method_code' => 'cod',
            'konsumen_id' => $branch['konsumen']->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'A', 'recipient_phone' => '0811', 'address_line' => 'Jl. A',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.9, 'longitude' => 107.6,
        ];

        $this->actingAs($admin)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', $payload)->assertStatus(403);
        $this->actingAs($kurir)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', $payload)->assertStatus(403);
    }

    public function test_sales_can_create_an_order_for_their_own_konsumen_but_not_for_another_branch(): void
    {
        $branch = $this->makeAgentBranch();
        $otherBranch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], price: 50000, stockQty: 10);

        $this->actingAs($branch['sales'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'konsumen_id' => $branch['konsumen']->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'A', 'recipient_phone' => '0811', 'address_line' => 'Jl. A',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.9, 'longitude' => 107.6,
        ])->assertCreated();

        // Same sales user, but targeting a konsumen from a completely different branch:
        // OrderController::store scopes the konsumen_id lookup by the actor's own
        // agent_id explicitly, so it doesn't even resolve (404) — the request never
        // gets far enough to need OrderPolicy.
        $this->actingAs($branch['sales'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'konsumen_id' => $otherBranch['konsumen']->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'A', 'recipient_phone' => '0811', 'address_line' => 'Jl. A',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.9, 'longitude' => 107.6,
        ])->assertStatus(404);
    }

    public function test_order_resource_exposes_sales_and_korsal_identity_to_office_roles_but_never_to_the_konsumen(): void
    {
        ['agen' => $agen, 'korsal' => $korsal, 'sales' => $sales, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $agen->update(['agent_id' => $agen->id]);
        $product = $this->makeProduct($agen, price: 50000, stockQty: 5);

        $created = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. A',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.9, 'longitude' => 107.6,
        ]);
        $created->assertCreated();
        $orderId = $created->json('data.id');

        // Agen (and, by the same gate, admin/super_admin/korsal/sales) sees who made the sale.
        $agenView = $this->actingAs($agen)->getJson("/api/v1/orders/{$orderId}");
        $agenView->assertOk();
        $this->assertSame($sales->name, $agenView->json('data.sales.name'));
        $this->assertSame($korsal->name, $agenView->json('data.korsal.name'));

        // The konsumen viewing their own order never sees the internal referral-chain identity.
        $konsumenView = $this->actingAs($konsumen)->getJson("/api/v1/orders/{$orderId}");
        $konsumenView->assertOk();
        $this->assertArrayNotHasKey('sales', $konsumenView->json('data'));
        $this->assertArrayNotHasKey('korsal', $konsumenView->json('data'));
    }
}
