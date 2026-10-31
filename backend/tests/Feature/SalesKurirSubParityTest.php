<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Role;
use App\Models\User;
use App\Services\Referral\ReferralReassignmentService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\Concerns\ResolvesCheckoutActor;
use Tests\TestCase;

/** Sales-Kurir-Sub inherits every Sales capability; these tests pin that parity and its cross-Sales isolation. */
class SalesKurirSubParityTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;
    use ResolvesCheckoutActor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    /** @return array{agen: User, korsal: User, korsal2: User, sks: User, sales: User, superAdmin: User} */
    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko', 'address' => 'Jl. X',
            'latitude' => -6.2, 'longitude' => 106.8,
        ]);
        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $korsal2 = User::factory()->korsal()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $sks = User::factory()->salesKurirSub()->create([
            'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'parent_id' => $korsal->id, 'referral_code' => 'SS-'.uniqid(),
        ]);
        $sales = User::factory()->sales()->create([
            'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'parent_id' => $korsal->id, 'referral_code' => 'SA-'.uniqid(),
        ]);
        $superAdmin = User::factory()->superAdmin()->create();

        return compact('agen', 'korsal', 'korsal2', 'sks', 'sales', 'superAdmin');
    }

    private function product(array $branch): Product
    {
        $product = Product::create([
            'sku' => 'PAR-'.Str::uuid(), 'name' => 'Kue', 'slug' => 'kue-'.uniqid(), 'has_variations' => false,
            'base_price' => 50000, 'weight_grams' => 400, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $branch['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 20, 'quantity_reserved' => 0]);
        $this->actingAs($branch['superAdmin'])->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 10000, 'sales_fee' => 8000, 'courier_fee' => 1000,
        ])->assertOk();

        return $product;
    }

    private function orderFor(User $buyer, Product $product): int
    {
        $response = $this->postOrderAs($buyer, [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'P', 'recipient_phone' => '0812', 'address_line' => 'Jl. P',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.9, 'longitude' => 107.6,
        ]);
        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    public function test_sales_kurir_sub_self_purchase_routes_the_sales_fee_to_itself_like_a_sales(): void
    {
        $branch = $this->branch();
        $product = $this->product($branch);

        $salesOrder = $this->orderFor($branch['sales'], $product);
        $sksOrder = $this->orderFor($branch['sks'], $product);

        $this->assertDatabaseHas('commissions', ['order_id' => $salesOrder, 'beneficiary_user_id' => $branch['sales']->id, 'beneficiary_role' => 'sales', 'amount' => 8000]);
        $this->assertDatabaseHas('commissions', ['order_id' => $sksOrder, 'beneficiary_user_id' => $branch['sks']->id, 'beneficiary_role' => 'sales', 'amount' => 8000]);
        $this->assertDatabaseMissing('commissions', ['order_id' => $sksOrder, 'beneficiary_user_id' => $branch['korsal']->id, 'beneficiary_role' => 'sales']);
        $this->assertDatabaseHas('commissions', ['order_id' => $sksOrder, 'beneficiary_role' => 'agent', 'amount' => 10000]);
    }

    public function test_sales_kurir_sub_dashboard_is_reachable_and_scoped_to_its_own_sales_orders(): void
    {
        $branch = $this->branch();
        $product = $this->product($branch);

        $ownCustomer = User::factory()->konsumen()->create([
            'agent_id' => $branch['agen']->id, 'korsal_id' => $branch['korsal']->id, 'sales_id' => $branch['sks']->id,
        ]);
        $otherCustomer = User::factory()->konsumen()->create([
            'agent_id' => $branch['agen']->id, 'korsal_id' => $branch['korsal']->id, 'sales_id' => $branch['sales']->id,
        ]);
        $this->orderFor($ownCustomer, $product);
        $this->orderFor($otherCustomer, $product);

        $sks = $this->actingAs($branch['sks'])->getJson('/api/v1/dashboard/summary');
        $sks->assertOk();
        $this->assertSame(1, $sks->json('data.total_orders'), 'SKS must not see another Sales order in the branch');
        $this->assertSame(0, $sks->json('data.total_sales'), 'Sales headcount stays hidden from seller roles like Sales');

        $agen = $this->actingAs($branch['agen'])->getJson('/api/v1/dashboard/summary');
        $agen->assertOk();
        $this->assertSame(2, $agen->json('data.total_sales'), 'Agen headcount counts Sales and Sales-Kurir-Sub alike');
    }

    public function test_sales_kurir_sub_can_be_moved_to_another_korsal_and_receive_konsumen_like_a_sales(): void
    {
        $branch = $this->branch();
        $service = app(ReferralReassignmentService::class);

        $moved = $service->reassignSalesToKorsal($branch['sks'], $branch['korsal2']->id, $branch['agen']);
        $this->assertSame($branch['korsal2']->id, $moved->korsal_id);

        $konsumen = User::factory()->konsumen()->create([
            'agent_id' => $branch['agen']->id, 'korsal_id' => $branch['korsal']->id, 'sales_id' => $branch['sales']->id,
        ]);
        $reassigned = $service->reassignKonsumenToSales($konsumen, $branch['sks']->id, $branch['agen']);
        $this->assertSame($branch['sks']->id, $reassigned->sales_id);
        $this->assertSame($branch['korsal2']->id, $reassigned->korsal_id);
    }

    public function test_seller_slugs_cover_sales_and_every_sales_kurir_sub_spelling(): void
    {
        $slugs = Role::sellerSlugs();

        $this->assertContains('sales', $slugs);
        $this->assertContains('sales-kurir-sub', $slugs);
        $this->assertNotContains('korsal', $slugs);
    }
}
