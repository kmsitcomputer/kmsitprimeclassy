<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Commission;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductFee;
use App\Models\ProductStock;
use App\Models\ShippingConfiguration;
use App\Models\User;
use App\Policies\OrderPolicy;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

class SalesCourierOrderIsolationTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_checkout_self_purchase_role_matrix_is_exact(): void
    {
        $branch = $this->branch();
        $policy = app(OrderPolicy::class);

        foreach (['konsumen', 'agent', 'korsal', 'sales', 'sales_kurir'] as $role) {
            $this->assertTrue($policy->create($branch[$role], $branch[$role]), "{$role} should be allowed");
        }

        foreach (['kurir', 'admin', 'keuangan', 'super_admin'] as $role) {
            $this->assertFalse($policy->create($branch[$role], $branch[$role]), "{$role} should be forbidden");
        }
    }

    public function test_checkout_request_layer_keeps_forbidden_roles_out(): void
    {
        $branch = $this->branch();

        foreach (['konsumen', 'agent', 'korsal', 'sales', 'sales_kurir'] as $role) {
            $this->actingAs($branch[$role])->postJson('/api/v1/orders', [])->assertUnprocessable();
        }

        foreach (['kurir', 'admin', 'keuangan', 'super_admin'] as $role) {
            $this->actingAs($branch[$role])->postJson('/api/v1/orders', [])->assertForbidden();
        }
    }

    /**
     * Owner decision (BUSINESS-RULES §15, §25; Phase 2 directive §4.3): a self-purchase does NOT zero the fee. A Sales-Kurir-Sub buying for
     * itself earns the SALES fee as a separate per-item row (the agent fee is a different row for the agent). The old assertion expected
     * no sales row, i.e. it encoded the opposite of the Owner rule.
     */
    public function test_sales_kurir_can_checkout_for_itself_and_earns_its_own_sales_fee(): void
    {
        $branch = $this->branch();
        $product = $this->product($branch['agent']);

        $response = $this->actingAs($branch['sales_kurir'])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'recipient_name' => 'Sales Kurir Buyer',
                'recipient_phone' => '08120000000',
                'address_line' => 'Test Address',
                'village_id' => $this->seedTestVillage(),
                'latitude' => -6.914744,
                'longitude' => 107.609810,
            ])->assertCreated();

        $order = Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
        $this->assertSame($branch['sales_kurir']->id, $order->konsumen_id);
        $this->assertNull($order->sales_id);
        $itemIds = $order->items()->pluck('id');
        $this->assertGreaterThan(0, $itemIds->count());
        foreach ($itemIds as $itemId) {
            // exactly ONE sales row per item, credited to the buyer itself, still pending (nothing is delivered yet)
            $this->assertSame(1, Commission::where('order_item_id', $itemId)->where('beneficiary_role', 'sales')->count());
            $this->assertDatabaseHas('commissions', [
                'order_id' => $order->id, 'order_item_id' => $itemId,
                'beneficiary_user_id' => $branch['sales_kurir']->id, 'beneficiary_role' => 'sales', 'status' => 'pending',
            ]);
            // the agent fee is a different row for a different beneficiary — never merged into the sales fee
            $this->assertSame(0, Commission::where('order_item_id', $itemId)->where('beneficiary_role', 'agent')->where('beneficiary_user_id', $branch['sales_kurir']->id)->count());
        }
    }

    public function test_sales_kurir_order_index_is_inner_scoped_before_filters_and_pagination(): void
    {
        $a = $this->branch();
        $b = $this->branch();
        $otherSalesKurir = User::factory()->salesKurir()->create([
            'agent_id' => $a['agent']->id,
            'korsal_id' => $a['korsal_two']->id,
            'parent_id' => $a['korsal_two']->id,
        ]);

        $own = $this->order($a['agent'], $a['customer'], $a['sales_kurir'], $a['korsal']);
        $sameKorsalSales = $this->order($a['agent'], $a['customer_two'], $a['sales'], $a['korsal']);
        $otherKorsalSales = $this->order($a['agent'], $a['customer_three'], $a['sales_two'], $a['korsal_two']);
        $otherSalesKurirOrder = $this->order($a['agent'], $a['customer_four'], $otherSalesKurir, $a['korsal_two']);
        $otherAgent = $this->order($b['agent'], $b['customer'], $b['sales'], $b['korsal']);

        $response = $this->actingAs($a['sales_kurir'])->getJson('/api/v1/orders?agent_id='.$b['agent']->id.'&sales_id='.$a['sales']->id.'&konsumen_id='.$a['customer_two']->id.'&search=PC&per_page=100')
            ->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertSame([$own->id], $ids->all());
        $this->assertNotContains($sameKorsalSales->id, $ids);
        $this->assertNotContains($otherKorsalSales->id, $ids);
        $this->assertNotContains($otherSalesKurirOrder->id, $ids);
        $this->assertNotContains($otherAgent->id, $ids);

        $this->actingAs($a['sales_kurir'])->getJson('/api/v1/orders/'.$sameKorsalSales->id)->assertForbidden();
        $this->actingAs($a['sales_kurir'])->getJson('/api/v1/orders/'.$otherAgent->id)->assertNotFound();

        $salesIds = collect($this->actingAs($a['sales'])->getJson('/api/v1/orders')->assertOk()->json('data'))->pluck('id');
        $this->assertSame([$sameKorsalSales->id], $salesIds->all());

        $korsalIds = collect($this->actingAs($a['korsal'])->getJson('/api/v1/orders')->assertOk()->json('data'))->pluck('id');
        $this->assertEqualsCanonicalizing([$own->id, $sameKorsalSales->id], $korsalIds->all());

        $agentIds = collect($this->actingAs($a['agent'])->getJson('/api/v1/orders?per_page=100')->assertOk()->json('data'))->pluck('id');
        $this->assertEqualsCanonicalizing([$own->id, $sameKorsalSales->id, $otherKorsalSales->id, $otherSalesKurirOrder->id], $agentIds->all());
    }

    public function test_sales_self_orders_stay_visible_in_list_and_detail_across_every_forward_status_without_scope_leaks(): void
    {
        $branch = $this->branch();
        $otherBranch = $this->branch();
        $paymentMethodId = PaymentMethod::where('code', 'cod')->value('id');

        $selfOrders = collect(['diterima', 'diproses', 'dikirim', 'terkirim'])->map(function (string $status) use ($branch, $paymentMethodId) {
            return Order::withoutGlobalScopes()->create([
                'order_no' => 'PC-SELF-'.Str::upper(Str::random(10)),
                'konsumen_id' => $branch['sales']->id,
                'sales_id' => null,
                'korsal_id' => $branch['korsal']->id,
                'agent_id' => $branch['agent']->id,
                'placed_by_user_id' => $branch['sales']->id,
                'payment_method_id' => $paymentMethodId,
                'status' => $status,
                'payment_status' => 'unpaid',
                'subtotal_amount' => 10000,
                'total_amount' => 10000,
                'recipient_name_snapshot' => $branch['sales']->name,
                'recipient_phone_snapshot' => $branch['sales']->phone,
                'address_snapshot' => 'Test Address',
            ]);
        });
        $referredOrder = $this->order($branch['agent'], $branch['customer_two'], $branch['sales'], $branch['korsal']);

        $salesListIds = collect($this->actingAs($branch['sales'])
            ->getJson('/api/v1/orders?per_page=100')->assertOk()->json('data'))
            ->pluck('id');

        foreach ($selfOrders as $order) {
            $this->assertContains($order->id, $salesListIds, "Self-order status {$order->status} must remain listed.");
            $this->actingAs($branch['sales'])->getJson("/api/v1/orders/{$order->id}")->assertOk();
        }
        $this->assertContains($referredOrder->id, $salesListIds);

        $protectedOrder = $selfOrders->last();
        $sameAgentOtherSalesIds = collect($this->actingAs($branch['sales_two'])
            ->getJson('/api/v1/orders?per_page=100')->assertOk()->json('data'))
            ->pluck('id');
        $this->assertNotContains($protectedOrder->id, $sameAgentOtherSalesIds);
        $this->actingAs($branch['sales_two'])->getJson("/api/v1/orders/{$protectedOrder->id}")->assertForbidden();

        $crossAgentIds = collect($this->actingAs($otherBranch['sales'])
            ->getJson('/api/v1/orders?per_page=100')->assertOk()->json('data'))
            ->pluck('id');
        $this->assertNotContains($protectedOrder->id, $crossAgentIds);
        $this->actingAs($otherBranch['sales'])->getJson("/api/v1/orders/{$protectedOrder->id}")->assertNotFound();

        $otherConsumerIds = collect($this->actingAs($branch['konsumen'])
            ->getJson('/api/v1/orders?per_page=100')->assertOk()->json('data'))
            ->pluck('id');
        $this->assertNotContains($protectedOrder->id, $otherConsumerIds);
        $this->actingAs($branch['konsumen'])->getJson("/api/v1/orders/{$protectedOrder->id}")->assertForbidden();
    }

    private function branch(): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        AgentProfile::create(['user_id' => $agent->id, 'store_name' => 'Test Branch', 'address' => 'Test', 'latitude' => -6.2, 'longitude' => 106.8]);
        ShippingConfiguration::create(['agent_id' => $agent->id, 'price_per_km' => 0, 'minimum_distance_km' => 0, 'minimum_charge' => 0, 'free_shipping_enabled' => true, 'is_active' => true]);
        $korsal = User::factory()->korsal()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $korsalTwo = User::factory()->korsal()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $sales = User::factory()->sales()->create(['agent_id' => $agent->id, 'korsal_id' => $korsal->id, 'parent_id' => $korsal->id]);
        $salesTwo = User::factory()->sales()->create(['agent_id' => $agent->id, 'korsal_id' => $korsalTwo->id, 'parent_id' => $korsalTwo->id]);
        $salesKurir = User::factory()->salesKurir()->create(['agent_id' => $agent->id, 'korsal_id' => $korsal->id, 'parent_id' => $korsal->id]);

        return [
            'agent' => $agent, 'korsal' => $korsal, 'korsal_two' => $korsalTwo,
            'sales' => $sales, 'sales_two' => $salesTwo, 'sales_kurir' => $salesKurir,
            'konsumen' => User::factory()->konsumen()->create(['agent_id' => $agent->id]),
            'customer' => User::factory()->konsumen()->create(['agent_id' => $agent->id, 'korsal_id' => $korsal->id, 'sales_id' => $salesKurir->id]),
            'customer_two' => User::factory()->konsumen()->create(['agent_id' => $agent->id, 'korsal_id' => $korsal->id, 'sales_id' => $sales->id]),
            'customer_three' => User::factory()->konsumen()->create(['agent_id' => $agent->id, 'korsal_id' => $korsalTwo->id, 'sales_id' => $salesTwo->id]),
            'customer_four' => User::factory()->konsumen()->create(['agent_id' => $agent->id, 'korsal_id' => $korsalTwo->id]),
            'kurir' => User::factory()->kurir()->create(['agent_id' => $agent->id]),
            'admin' => User::factory()->admin()->create(['agent_id' => $agent->id]),
            'keuangan' => User::factory()->keuangan()->create(['agent_id' => $agent->id]),
            'super_admin' => User::factory()->superAdmin()->create(),
        ];
    }

    private function product(User $agent): Product
    {
        $product = Product::create([
            'sku' => 'SK-CHECKOUT-'.Str::uuid(), 'name' => 'Sales Kurir Cake',
            'slug' => 'sales-kurir-sub-cake-'.Str::uuid(), 'has_variations' => false,
            'base_price' => 10000, 'weight_grams' => 100, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);
        ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'sales', 'amount' => 500, 'is_active' => true]);

        return $product;
    }

    private function order(User $agent, User $customer, User $sales, User $korsal): Order
    {
        return Order::withoutGlobalScopes()->create([
            'order_no' => 'PC-'.Str::upper(Str::random(12)),
            'konsumen_id' => $customer->id, 'sales_id' => $sales->id,
            'korsal_id' => $korsal->id, 'agent_id' => $agent->id,
            'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'),
            'status' => 'diproses', 'payment_status' => 'unpaid',
            'subtotal_amount' => 10000, 'total_amount' => 10000,
            'recipient_name_snapshot' => $customer->name,
            'recipient_phone_snapshot' => $customer->phone,
            'address_snapshot' => 'Test Address',
        ]);
    }
}
