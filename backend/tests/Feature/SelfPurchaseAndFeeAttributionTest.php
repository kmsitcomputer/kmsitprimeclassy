<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
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

/**
 * Self-purchase (agen/korsal/sales buying for themselves) and the
 * referral-based agent_fee/sales_fee split for each referrer level.
 */
class SelfPurchaseAndFeeAttributionTest extends TestCase
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
        // Referred directly by the agen — no sales/korsal in the chain.
        $directKonsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        // Referred directly by the korsal — no sales in the chain.
        $korsalDirectKonsumen = User::factory()->konsumen()->create([
            'agent_id' => $agen->id, 'korsal_id' => $korsal->id,
        ]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $agen->id]);
        $kurir = User::factory()->kurir()->create(['agent_id' => $agen->id]);
        $superAdmin = User::factory()->superAdmin()->create();

        ShippingConfiguration::create([
            'agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0,
            'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true,
        ]);

        return compact('agen', 'korsal', 'sales', 'konsumen', 'directKonsumen', 'korsalDirectKonsumen', 'admin', 'keuangan', 'kurir', 'superAdmin');
    }

    private function makeProduct(User $agen, int $agentFee, int $salesFee, int $price = 100000, int $stockQty = 20): Product
    {
        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Kue Fee', 'slug' => 'kue-fee-'.uniqid(),
            'has_variations' => false, 'base_price' => $price, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => $stockQty, 'quantity_reserved' => 0]);
        ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'agent', 'amount' => $agentFee, 'is_active' => true]);
        ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'sales', 'amount' => $salesFee, 'is_active' => true]);

        return $product;
    }

    private function checkoutPayload(Product $product, int $qty = 1, ?int $konsumenId = null): array
    {
        return [
            'payment_method_code' => 'cod',
            ...($konsumenId ? ['konsumen_id' => $konsumenId] : []),
            'items' => [['product_id' => $product->id, 'quantity' => $qty]],
            'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.9, 'longitude' => 107.6,
        ];
    }

    private function checkoutAs(User $actor, array $payload)
    {
        return $this->actingAs($actor)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', $payload);
    }

    /* ---------------- Checkout matrix (§29) ---------------- */

    public function test_konsumen_can_checkout(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 10000, 5000);

        $this->checkoutAs($branch['konsumen'], $this->checkoutPayload($product))->assertCreated();
    }

    public function test_agent_can_checkout(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 10000, 5000);

        $this->checkoutAs($branch['agen'], $this->checkoutPayload($product))->assertCreated();
    }

    public function test_korsal_can_checkout(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 10000, 5000);

        $this->checkoutAs($branch['korsal'], $this->checkoutPayload($product))->assertCreated();
    }

    public function test_sales_can_checkout(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 10000, 5000);

        $this->checkoutAs($branch['sales'], $this->checkoutPayload($product))->assertCreated();
    }

    public function test_courier_cannot_checkout(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 10000, 5000);

        $this->checkoutAs($branch['kurir'], $this->checkoutPayload($product))->assertStatus(403);
    }

    public function test_admin_cannot_checkout(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 10000, 5000);

        $this->checkoutAs($branch['admin'], $this->checkoutPayload($product))->assertStatus(403);
    }

    public function test_finance_cannot_checkout(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 10000, 5000);

        $this->checkoutAs($branch['keuangan'], $this->checkoutPayload($product))->assertStatus(403);
    }

    public function test_super_admin_cannot_checkout(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 10000, 5000);

        $this->checkoutAs($branch['superAdmin'], $this->checkoutPayload($product))->assertStatus(403);
    }

    /* ---------------- Role immutability (§30/§34) ---------------- */

    public function test_agent_checkout_does_not_change_role(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 10000, 5000);

        $this->checkoutAs($branch['agen'], $this->checkoutPayload($product))->assertCreated();

        $this->assertSame('agen', $branch['agen']->fresh()->role->slug);
    }

    public function test_korsal_checkout_does_not_change_role(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 10000, 5000);

        $this->checkoutAs($branch['korsal'], $this->checkoutPayload($product))->assertCreated();

        $this->assertSame('korsal', $branch['korsal']->fresh()->role->slug);
    }

    public function test_sales_checkout_does_not_change_role(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 10000, 5000);

        $this->checkoutAs($branch['sales'], $this->checkoutPayload($product))->assertCreated();

        $this->assertSame('sales', $branch['sales']->fresh()->role->slug);
    }

    /* ---------------- Self-purchase fee attribution (§3/§4/§5, conceptual cases A/B/C) ---------------- */

    public function test_agent_self_purchase_earns_both_agent_fee_and_sales_fee_as_two_separate_commissions(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], agentFee: 20000, salesFee: 10000);

        $response = $this->checkoutAs($branch['agen'], $this->checkoutPayload($product));
        $response->assertCreated();
        $orderId = $response->json('data.id');

        $this->assertDatabaseHas('commissions', [
            'order_id' => $orderId, 'beneficiary_user_id' => $branch['agen']->id,
            'beneficiary_role' => 'agent', 'amount' => 20000,
        ]);
        $this->assertDatabaseHas('commissions', [
            'order_id' => $orderId, 'beneficiary_user_id' => $branch['agen']->id,
            'beneficiary_role' => 'sales', 'amount' => 10000,
        ]);
        // Never merged into a single row for the same beneficiary+order.
        $this->assertSame(2, \App\Models\Commission::where('order_id', $orderId)->count());
    }

    public function test_korsal_self_purchase_earns_sales_fee_for_self_and_agent_fee_goes_to_parent_agen(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], agentFee: 20000, salesFee: 10000);

        $response = $this->checkoutAs($branch['korsal'], $this->checkoutPayload($product));
        $response->assertCreated();
        $orderId = $response->json('data.id');

        $this->assertDatabaseHas('commissions', [
            'order_id' => $orderId, 'beneficiary_user_id' => $branch['agen']->id,
            'beneficiary_role' => 'agent', 'amount' => 20000,
        ]);
        $this->assertDatabaseHas('commissions', [
            'order_id' => $orderId, 'beneficiary_user_id' => $branch['korsal']->id,
            'beneficiary_role' => 'sales', 'amount' => 10000,
        ]);

        // The korsal can view their own self-purchased order.
        $this->actingAs($branch['korsal'])->getJson("/api/v1/orders/{$orderId}")->assertOk();
    }

    public function test_sales_self_purchase_earns_sales_fee_for_self_and_agent_fee_goes_to_parent_agen(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], agentFee: 20000, salesFee: 10000);

        $response = $this->checkoutAs($branch['sales'], $this->checkoutPayload($product));
        $response->assertCreated();
        $orderId = $response->json('data.id');

        $this->assertDatabaseHas('commissions', [
            'order_id' => $orderId, 'beneficiary_user_id' => $branch['agen']->id,
            'beneficiary_role' => 'agent', 'amount' => 20000,
        ]);
        $this->assertDatabaseHas('commissions', [
            'order_id' => $orderId, 'beneficiary_user_id' => $branch['sales']->id,
            'beneficiary_role' => 'sales', 'amount' => 10000,
        ]);

        // The sales rep can view their own self-purchased order.
        $this->actingAs($branch['sales'])->getJson("/api/v1/orders/{$orderId}")->assertOk();
    }

    /* ---------------- Conceptual cases A/B/C — konsumen referred by each level ---------------- */

    public function test_case_a_customer_referred_by_agen_gives_agen_both_fees(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], agentFee: 20000, salesFee: 10000);

        $order = $this->checkoutAs($branch['directKonsumen'], $this->checkoutPayload($product));
        $order->assertCreated();
        $orderId = $order->json('data.id');

        $this->assertDatabaseHas('commissions', ['order_id' => $orderId, 'beneficiary_user_id' => $branch['agen']->id, 'beneficiary_role' => 'agent', 'amount' => 20000]);
        $this->assertDatabaseHas('commissions', ['order_id' => $orderId, 'beneficiary_user_id' => $branch['agen']->id, 'beneficiary_role' => 'sales', 'amount' => 10000]);
    }

    public function test_case_b_customer_referred_by_korsal_gives_agen_agent_fee_and_korsal_sales_fee(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], agentFee: 20000, salesFee: 10000);

        $order = $this->checkoutAs($branch['korsalDirectKonsumen'], $this->checkoutPayload($product));
        $order->assertCreated();
        $orderId = $order->json('data.id');

        $this->assertDatabaseHas('commissions', ['order_id' => $orderId, 'beneficiary_user_id' => $branch['agen']->id, 'beneficiary_role' => 'agent', 'amount' => 20000]);
        $this->assertDatabaseHas('commissions', ['order_id' => $orderId, 'beneficiary_user_id' => $branch['korsal']->id, 'beneficiary_role' => 'sales', 'amount' => 10000]);
    }

    public function test_case_c_customer_referred_by_sales_gives_agen_agent_fee_and_sales_sales_fee(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], agentFee: 20000, salesFee: 10000);

        $order = $this->checkoutAs($branch['konsumen'], $this->checkoutPayload($product));
        $order->assertCreated();
        $orderId = $order->json('data.id');

        $this->assertDatabaseHas('commissions', ['order_id' => $orderId, 'beneficiary_user_id' => $branch['agen']->id, 'beneficiary_role' => 'agent', 'amount' => 20000]);
        $this->assertDatabaseHas('commissions', ['order_id' => $orderId, 'beneficiary_user_id' => $branch['sales']->id, 'beneficiary_role' => 'sales', 'amount' => 10000]);
    }

    /* ---------------- Quantity (§19) ---------------- */

    /** Fee is configured PER UNIT, same as price — scales with quantity exactly like subtotal does. */
    public function test_fee_calculation_respects_quantity(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], agentFee: 10000, salesFee: 5000, stockQty: 20);

        $order = $this->checkoutAs($branch['directKonsumen'], $this->checkoutPayload($product, qty: 3));
        $order->assertCreated();
        $orderId = $order->json('data.id');

        $this->assertDatabaseHas('commissions', ['order_id' => $orderId, 'beneficiary_role' => 'agent', 'amount' => 30000]);
        $this->assertDatabaseHas('commissions', ['order_id' => $orderId, 'beneficiary_role' => 'sales', 'amount' => 15000]);
    }

    /* ---------------- Historical snapshot (§20) ---------------- */

    public function test_historical_fee_uses_snapshot_not_current_product_fee(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], agentFee: 8000, salesFee: 3000);

        $order = $this->checkoutAs($branch['directKonsumen'], $this->checkoutPayload($product));
        $order->assertCreated();
        $orderId = $order->json('data.id');

        // Fee is edited AFTER the order was placed.
        $this->actingAs($branch['agen'])->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 99000, 'sales_fee' => 88000, 'courier_fee' => 0,
        ])->assertOk();

        $fresh = Order::withoutGlobalScopes()->find($orderId);
        $this->assertSame('8000.00', $fresh->items->first()->agent_fee_amount);
        $this->assertSame('3000.00', $fresh->items->first()->sales_fee_amount);
        $this->assertDatabaseHas('commissions', ['order_id' => $orderId, 'beneficiary_role' => 'agent', 'amount' => 8000]);
    }

    /* ---------------- Security — network isolation (§25/§26/§35) ---------------- */

    public function test_sales_cannot_force_agent_id_from_another_network(): void
    {
        $branchA = $this->makeAgentBranch();
        $branchB = $this->makeAgentBranch();
        $product = $this->makeProduct($branchA['agen'], agentFee: 20000, salesFee: 10000);

        // Sales from branch A tries to order "for" branch B's konsumen — the
        // konsumen_id lookup is scoped to the actor's own agent_id, so this
        // 404s rather than ever reaching fee/commission resolution.
        $payload = $this->checkoutPayload($product, konsumenId: $branchB['konsumen']->id);
        $this->checkoutAs($branchA['sales'], $payload)->assertStatus(404);
    }

    public function test_korsal_cannot_force_agent_id_from_another_network(): void
    {
        $branchA = $this->makeAgentBranch();
        $branchB = $this->makeAgentBranch();
        $product = $this->makeProduct($branchA['agen'], agentFee: 20000, salesFee: 10000);

        $payload = $this->checkoutPayload($product, konsumenId: $branchB['konsumen']->id);
        $this->checkoutAs($branchA['korsal'], $payload)->assertStatus(404);
    }

    public function test_consumer_cannot_force_referral_owner_via_payload(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], agentFee: 20000, salesFee: 10000);

        // A konsumen has no concept of "acting on behalf of" anyone — any
        // konsumen_id/sales_id/agent_id they send is simply not read by the
        // controller at all for a konsumen actor (resolveKonsumen returns
        // the actor itself unconditionally); the commission still resolves
        // from the konsumen's own persisted referral chain.
        $payload = $this->checkoutPayload($product);
        $payload['konsumen_id'] = $branch['sales']->id; // arbitrary — must be ignored
        $payload['agent_id'] = 999999;
        $payload['sales_id'] = 999999;

        $response = $this->checkoutAs($branch['konsumen'], $payload);
        $response->assertCreated();
        $orderId = $response->json('data.id');

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'konsumen_id' => $branch['konsumen']->id]);
        $this->assertDatabaseHas('commissions', ['order_id' => $orderId, 'beneficiary_user_id' => $branch['sales']->id, 'beneficiary_role' => 'sales', 'amount' => 10000]);
    }

    public function test_invalid_referral_code_does_not_create_a_fee_recipient(): void
    {
        $branch = $this->makeAgentBranch();

        $response = $this->getJson('/api/v1/referral/NOT-A-REAL-CODE');
        $response->assertStatus(422);
    }

    public function test_inactive_referral_does_not_create_unauthorized_fee(): void
    {
        $branch = $this->makeAgentBranch();
        $branch['sales']->update(['status' => 'inactive']);

        $response = $this->getJson("/api/v1/referral/{$branch['sales']->referral_code}");
        $response->assertStatus(422);
    }
}
