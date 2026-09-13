<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariationStock;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

class FeeSystemTest extends TestCase
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
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $superAdmin = User::factory()->superAdmin()->create();

        return compact('agen', 'korsal', 'sales', 'konsumen', 'admin', 'superAdmin');
    }

    /* ---------------------------------------------------------------
     * 1/2. Product fee / variation fee + precedence
     * ------------------------------------------------------------- */

    public function test_super_admin_can_set_fee_for_a_product_without_variations(): void
    {
        $branch = $this->makeAgentBranch();
        $product = Product::create([
            'sku' => 'NASTAR-'.uniqid(),
            'name' => 'Nastar', 'slug' => 'nastar-'.uniqid(), 'has_variations' => false,
            'base_price' => 40000, 'weight_grams' => 500, 'status' => 'active',
        ]);

        $response = $this->actingAs($branch['superAdmin'])->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 10000, 'sales_fee' => 5000, 'courier_fee' => 1000,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('product_fees', ['product_id' => $product->id, 'beneficiary_role' => 'agent', 'amount' => 10000]);
        $this->assertDatabaseHas('product_fees', ['product_id' => $product->id, 'beneficiary_role' => 'sales', 'amount' => 5000]);
    }

    public function test_setting_fee_on_the_parent_product_is_rejected_when_it_has_variations(): void
    {
        $branch = $this->makeAgentBranch();
        $product = Product::create([
            'name' => 'Cake Susun', 'slug' => 'cake-susun-'.uniqid(), 'has_variations' => true, 'status' => 'active',
        ]);

        $this->actingAs($branch['superAdmin'])->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 10000, 'sales_fee' => 5000, 'courier_fee' => 1000,
        ])->assertStatus(422);
    }

    public function test_fee_precedence_uses_variation_fee_when_product_has_variations(): void
    {
        $branch = $this->makeAgentBranch();
        $product = Product::create([
            'name' => 'Cake Coklat', 'slug' => 'cake-coklat-'.uniqid(), 'has_variations' => true, 'status' => 'active',
        ]);
        $variationId = $this->actingAs($branch['superAdmin'])->postJson("/api/v1/products/{$product->id}/variations", [
            'sku' => 'CC-500', 'price' => 90000, 'weight_grams' => 500, 'attributes' => ['Ukuran' => '500gr'],
        ])->json('data.id');

        $this->actingAs($branch['superAdmin'])->putJson("/api/v1/products/{$product->id}/variations/{$variationId}/fees", [
            'agent_fee' => 12000, 'sales_fee' => 6000, 'courier_fee' => 1000,
        ])->assertOk();

        ProductVariationStock::create([
            'agent_id' => $branch['agen']->id, 'product_variation_id' => $variationId,
            'quantity_on_hand' => 10, 'quantity_reserved' => 0,
        ]);

        $order = $this->actingAs($branch['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'product_variation_id' => $variationId, 'quantity' => 1]],
            'recipient_name' => 'C', 'recipient_phone' => '0812', 'address_line' => 'Jl. C',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.9, 'longitude' => 107.6,
        ]);
        $order->assertCreated();

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->json('data.id'), 'sku_snapshot' => 'CC-500',
            'agent_fee_amount' => 12000, 'sales_fee_amount' => 6000,
        ]);
    }

    /**
     * Full CRUD cycle for an agen — create, read back, update to new
     * amounts, then zero out (the equivalent of "deleting" a fee, since
     * FeeService always upserts rather than removing rows — see its
     * docblock: "a missing fee row simply means that beneficiary earns 0").
     */
    public function test_agen_can_fully_manage_product_and_variation_fees(): void
    {
        $branch = $this->makeAgentBranch();
        $agen = $branch['agen'];

        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Brownies', 'slug' => 'brownies-'.uniqid(), 'has_variations' => false,
            'base_price' => 60000, 'weight_grams' => 500, 'status' => 'active',
        ]);

        // Create
        $this->actingAs($agen)->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 5000, 'sales_fee' => 3000, 'courier_fee' => 1000,
        ])->assertOk()->assertJsonPath('data.agent_fee', 5000)->assertJsonPath('data.sales_fee', 3000)->assertJsonPath('data.courier_fee', 1000);

        // Read
        $this->actingAs($agen)->getJson("/api/v1/products/{$product->id}/fees")
            ->assertOk()->assertJsonPath('data.agent_fee', 5000);

        // Update
        $this->actingAs($agen)->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 7500, 'sales_fee' => 4000, 'courier_fee' => 1500,
        ])->assertOk()->assertJsonPath('data.agent_fee', 7500);

        // "Delete" (zero out)
        $this->actingAs($agen)->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 0, 'sales_fee' => 0, 'courier_fee' => 0,
        ])->assertOk()->assertJsonPath('data.agent_fee', 0)->assertJsonPath('data.sales_fee', 0)->assertJsonPath('data.courier_fee', 0);

        // Same full cycle for a variation's fee.
        $variationProduct = Product::create([
            'name' => 'Kue Lapis', 'slug' => 'kue-lapis-'.uniqid(), 'has_variations' => true, 'status' => 'active',
        ]);
        $variationId = $this->actingAs($agen)->postJson("/api/v1/products/{$variationProduct->id}/variations", [
            'sku' => 'KL-500', 'price' => 70000, 'weight_grams' => 500, 'attributes' => ['Ukuran' => '500gr'],
        ])->json('data.id');

        $this->actingAs($agen)->putJson("/api/v1/products/{$variationProduct->id}/variations/{$variationId}/fees", [
            'agent_fee' => 6000, 'sales_fee' => 3500, 'courier_fee' => 1200,
        ])->assertOk()->assertJsonPath('data.agent_fee', 6000);

        $this->actingAs($agen)->putJson("/api/v1/products/{$variationProduct->id}/variations/{$variationId}/fees", [
            'agent_fee' => 0, 'sales_fee' => 0, 'courier_fee' => 0,
        ])->assertOk()->assertJsonPath('data.agent_fee', 0);

        $this->actingAs($agen)->getJson("/api/v1/products/{$variationProduct->id}/variations/{$variationId}/fees")
            ->assertOk()->assertJsonPath('data.sales_fee', 0);
    }

    /* ---------------------------------------------------------------
     * 3/4/5. Fee calculation, snapshot immutability, permission
     * ------------------------------------------------------------- */

    public function test_fee_snapshot_on_order_item_never_changes_after_product_fee_is_later_updated(): void
    {
        $branch = $this->makeAgentBranch();
        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Brownies', 'slug' => 'brownies-'.uniqid(), 'has_variations' => false,
            'base_price' => 50000, 'weight_grams' => 400, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $branch['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);

        // Fee at time of purchase: sales fee = Rp8.000.
        $this->actingAs($branch['superAdmin'])->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 10000, 'sales_fee' => 8000, 'courier_fee' => 1000,
        ])->assertOk();

        $orderResponse = $this->actingAs($branch['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'C', 'recipient_phone' => '0812', 'address_line' => 'Jl. C',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.9, 'longitude' => 107.6,
        ]);
        $orderResponse->assertCreated();
        $orderId = $orderResponse->json('data.id');

        $this->assertDatabaseHas('order_items', ['order_id' => $orderId, 'sales_fee_amount' => 8000]);
        $this->assertDatabaseHas('commissions', ['order_id' => $orderId, 'beneficiary_role' => 'sales', 'amount' => 8000]);

        // Product's fee configuration is later raised to Rp15.000.
        $this->actingAs($branch['superAdmin'])->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 10000, 'sales_fee' => 15000, 'courier_fee' => 1000,
        ])->assertOk();

        // The historical order item is untouched — still Rp8.000, never recalculated.
        $this->assertDatabaseHas('order_items', ['order_id' => $orderId, 'sales_fee_amount' => 8000]);
        $this->assertDatabaseMissing('order_items', ['order_id' => $orderId, 'sales_fee_amount' => 15000]);

        // A brand NEW order, placed after the change, uses the new configuration.
        $secondOrder = $this->actingAs($branch['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'C', 'recipient_phone' => '0812', 'address_line' => 'Jl. C',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.9, 'longitude' => 107.6,
        ]);
        $secondOrder->assertCreated();
        $this->assertDatabaseHas('order_items', ['order_id' => $secondOrder->json('data.id'), 'sales_fee_amount' => 15000]);
    }

    public function test_only_super_admin_agen_and_sales_may_view_fee_configuration(): void
    {
        $branch = $this->makeAgentBranch();
        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Pie Buah', 'slug' => 'pie-buah-'.uniqid(), 'has_variations' => false,
            'base_price' => 45000, 'weight_grams' => 600, 'status' => 'active',
        ]);
        $this->actingAs($branch['superAdmin'])->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 9000, 'sales_fee' => 4000, 'courier_fee' => 1000,
        ])->assertOk();

        foreach (['superAdmin', 'agen'] as $role) {
            $this->actingAs($branch[$role])
                ->getJson("/api/v1/products/{$product->id}/fees")
                ->assertOk()
                ->assertJsonPath('data.agent_fee', 9000)
                ->assertJsonPath('data.sales_fee', 4000)
                ->assertJsonPath('data.courier_fee', 1000);
        }

        // sales may reach the endpoint (it's their own commission rate) but
        // must never see agent_fee/courier_fee — only its own sales_fee.
        $salesResponse = $this->actingAs($branch['sales'])->getJson("/api/v1/products/{$product->id}/fees");
        $salesResponse->assertOk()->assertJsonPath('data.sales_fee', 4000);
        $this->assertArrayNotHasKey('agent_fee', $salesResponse->json('data'));
        $this->assertArrayNotHasKey('courier_fee', $salesResponse->json('data'));

        foreach (['korsal', 'admin', 'konsumen'] as $role) {
            $this->actingAs($branch[$role])
                ->getJson("/api/v1/products/{$product->id}/fees")
                ->assertStatus(403);
        }
    }

    /** Fee write access mirrors ProductPolicy::manage exactly — super_admin and agen may write, everyone else (including sales, which may only read) is rejected. */
    public function test_only_super_admin_and_agen_can_write_fee_configuration(): void
    {
        $branch = $this->makeAgentBranch();
        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Klappertaart', 'slug' => 'klappertaart-'.uniqid(), 'has_variations' => false,
            'base_price' => 55000, 'weight_grams' => 500, 'status' => 'active',
        ]);

        $this->actingAs($branch['agen'])->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 99999, 'sales_fee' => 99999, 'courier_fee' => 1000,
        ])->assertOk()->assertJsonPath('data.agent_fee', 99999);

        foreach (['sales', 'korsal', 'admin', 'konsumen'] as $role) {
            $this->actingAs($branch[$role])->putJson("/api/v1/products/{$product->id}/fees", [
                'agent_fee' => 1, 'sales_fee' => 1, 'courier_fee' => 1,
            ])->assertStatus(403);
        }
    }

    /* ---------------------------------------------------------------
     * 6. Reporting
     * ------------------------------------------------------------- */

    public function test_commission_summary_aggregates_correctly_and_is_scoped_per_role(): void
    {
        $branchA = $this->makeAgentBranch();
        $branchB = $this->makeAgentBranch();

        $productA = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Kue A', 'slug' => 'kue-a-'.uniqid(), 'has_variations' => false,
            'base_price' => 20000, 'weight_grams' => 300, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $branchA['agen']->id, 'product_id' => $productA->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);
        $this->actingAs($branchA['superAdmin'])->putJson("/api/v1/products/{$productA->id}/fees", [
            'agent_fee' => 5000, 'sales_fee' => 3000, 'courier_fee' => 1000,
        ])->assertOk();

        // Two orders in Branch A.
        foreach ([1, 1] as $qty) {
            $this->actingAs($branchA['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $productA->id, 'quantity' => $qty]],
                'recipient_name' => 'C', 'recipient_phone' => '0812', 'address_line' => 'Jl. C',
                'village_id' => $this->seedTestVillage(),
                'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated();
        }

        // super_admin sees everything.
        $globalSummary = $this->actingAs($branchA['superAdmin'])->getJson('/api/v1/commissions/summary');
        $globalSummary->assertOk();
        $this->assertSame(10000.0, (float) $globalSummary->json('data.total_agent_fee'));
        $this->assertSame(6000.0, (float) $globalSummary->json('data.total_sales_fee'));

        // Agent A's own summary matches (only their branch has any commissions).
        $agentASummary = $this->actingAs($branchA['agen'])->getJson('/api/v1/commissions/summary');
        $this->assertSame(10000.0, (float) $agentASummary->json('data.total_agent_fee'));

        // Agent B's summary is zero — completely different branch, no orders placed there.
        $agentBSummary = $this->actingAs($branchB['agen'])->getJson('/api/v1/commissions/summary');
        $this->assertSame(0.0, (float) $agentBSummary->json('data.total_agent_fee'));

        // Sales A only sees their own sales-fee earnings — agent_fee/courier_fee
        // must not even appear as keys, never just read as zero.
        $salesASummary = $this->actingAs($branchA['sales'])->getJson('/api/v1/commissions/summary');
        $this->assertSame(6000.0, (float) $salesASummary->json('data.total_sales_fee'));
        $this->assertArrayNotHasKey('total_agent_fee', $salesASummary->json('data'));
        $this->assertArrayNotHasKey('total_courier_fee', $salesASummary->json('data'));

        // Admin (per-agent staff) sees sales+courier fee for its own branch, and
        // the agent fee ONLY for direct agent referrals — these orders all go
        // through a sales rep, so that total is 0 (key present, value zero).
        $adminASummary = $this->actingAs($branchA['admin'])->getJson('/api/v1/commissions/summary');
        $adminASummary->assertOk();
        $this->assertSame(6000.0, (float) $adminASummary->json('data.total_sales_fee'));
        $this->assertSame(0.0, (float) $adminASummary->json('data.total_agent_fee'));

        // Korsal A sees only sales fee earned by their own downstream sales reps.
        $korsalASummary = $this->actingAs($branchA['korsal'])->getJson('/api/v1/commissions/summary');
        $korsalASummary->assertOk();
        $this->assertSame(6000.0, (float) $korsalASummary->json('data.total_sales_fee'));
        $this->assertArrayNotHasKey('total_agent_fee', $korsalASummary->json('data'));
        $this->assertArrayNotHasKey('total_courier_fee', $korsalASummary->json('data'));

        // Korsal B (different branch, no sales reps involved) sees nothing from branch A.
        $korsalBSummary = $this->actingAs($branchB['korsal'])->getJson('/api/v1/commissions/summary');
        $korsalBSummary->assertOk();
        $this->assertSame(0.0, (float) $korsalBSummary->json('data.total_sales_fee'));
    }

    /* ---------------------------------------------------------------
     * 7. Security — konsumen can never read fee data through any API
     * ------------------------------------------------------------- */

    public function test_konsumen_can_never_read_fee_data_through_any_endpoint(): void
    {
        $branch = $this->makeAgentBranch();
        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Tart Keju', 'slug' => 'tart-keju-'.uniqid(), 'has_variations' => false,
            'base_price' => 65000, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $branch['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);
        $this->actingAs($branch['superAdmin'])->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 7000, 'sales_fee' => 3500, 'courier_fee' => 1000,
        ])->assertOk();

        // 1. Public/authenticated catalog — product detail never carries fee fields at all.
        $catalog = $this->actingAs($branch['konsumen'])->getJson("/api/v1/products/{$product->slug}");
        $catalog->assertOk();
        $this->assertArrayNotHasKey('agent_fee', $catalog->json('data'));
        $this->assertArrayNotHasKey('sales_fee', $catalog->json('data'));
        $this->assertArrayNotHasKey('agent_fee_amount', $catalog->json('data'));

        // 2. Direct fee endpoint attempt.
        $this->actingAs($branch['konsumen'])
            ->getJson("/api/v1/products/{$product->id}/fees")
            ->assertStatus(403);

        // 3. Their own order's item never reveals fee amounts, even though it's their own order.
        $orderResponse = $this->actingAs($branch['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'C', 'recipient_phone' => '0812', 'address_line' => 'Jl. C',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.9, 'longitude' => 107.6,
        ]);
        $orderResponse->assertCreated();
        $this->assertArrayNotHasKey('agent_fee_amount', $orderResponse->json('data.items.0'));
        $this->assertArrayNotHasKey('sales_fee_amount', $orderResponse->json('data.items.0'));

        $orderId = $orderResponse->json('data.id');
        $showResponse = $this->actingAs($branch['konsumen'])->getJson("/api/v1/orders/{$orderId}");
        $showResponse->assertOk();
        $this->assertArrayNotHasKey('agent_fee_amount', $showResponse->json('data.items.0'));
        $this->assertArrayNotHasKey('sales_fee_amount', $showResponse->json('data.items.0'));

        // 4. Commission/reporting endpoints entirely inaccessible.
        $this->actingAs($branch['konsumen'])->getJson('/api/v1/commissions')->assertStatus(403);
        $this->actingAs($branch['konsumen'])->getJson('/api/v1/commissions/summary')->assertStatus(403);

        // 5. A guest (no session at all) gets the same catalog guarantee.
        Auth::guard('web')->logout();
        $guestCatalog = $this->getJson("/api/v1/products/{$product->slug}");
        $guestCatalog->assertOk();
        $this->assertArrayNotHasKey('agent_fee_amount', $guestCatalog->json('data'));
    }

    public function test_sales_can_see_fee_amounts_on_their_own_order_but_korsal_cannot(): void
    {
        $branch = $this->makeAgentBranch();
        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Lapis Legit', 'slug' => 'lapis-legit-'.uniqid(), 'has_variations' => false,
            'base_price' => 150000, 'weight_grams' => 1000, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $branch['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);
        $this->actingAs($branch['superAdmin'])->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 20000, 'sales_fee' => 10000, 'courier_fee' => 1000,
        ])->assertOk();

        $orderId = $this->actingAs($branch['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'C', 'recipient_phone' => '0812', 'address_line' => 'Jl. C',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.9, 'longitude' => 107.6,
        ])->json('data.id');

        $salesView = $this->actingAs($branch['sales'])->getJson("/api/v1/orders/{$orderId}");
        $salesView->assertOk();
        $this->assertSame(10000, (int) $salesView->json('data.items.0.sales_fee_amount'));
        $this->assertArrayNotHasKey('agent_fee_amount', $salesView->json('data.items.0'));
        $this->assertArrayNotHasKey('courier_fee_amount', $salesView->json('data.items.0'));

        $korsalView = $this->actingAs($branch['korsal'])->getJson("/api/v1/orders/{$orderId}");
        $korsalView->assertOk();
        $this->assertArrayNotHasKey('sales_fee_amount', $korsalView->json('data.items.0'));
    }
}
