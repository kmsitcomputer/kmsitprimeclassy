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
 * Phase 6 — consolidated network reporting scope (req 11/12/14) and the
 * agent-referral fee visibility rule (agent fee is only exposed to admin/
 * keuangan when the AGEN itself was the consumer's direct referral source).
 */
class KeuanganReportingTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko '.uniqid(), 'address' => 'Jl. QA',
            'latitude' => -6.2, 'longitude' => 106.8166,
        ]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $agen->id]);
        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id, 'referral_code' => 'K-'.uniqid()]);
        $sales = User::factory()->sales()->create([
            'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'referral_code' => 'S-'.uniqid(),
        ]);
        // Referred DIRECTLY by the agen — no sales in the chain.
        $directKonsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        // Referred through a sales rep.
        $salesKonsumen = User::factory()->konsumen()->create([
            'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'sales_id' => $sales->id, 'parent_id' => $sales->id,
        ]);

        ShippingConfiguration::create([
            'agent_id' => $agen->id, 'price_per_km' => 2000, 'minimum_distance_km' => 0,
            'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true,
        ]);

        return compact('agen', 'admin', 'keuangan', 'korsal', 'sales', 'directKonsumen', 'salesKonsumen');
    }

    private function makeProduct(User $agen, int $agentFee = 10000, int $salesFee = 0, int $price = 100000): Product
    {
        $product = Product::create(['sku' => 'TEST-'.\Illuminate\Support\Str::uuid(),
            'name' => 'Kue Report', 'slug' => 'kue-report-'.uniqid(),
            'has_variations' => false, 'base_price' => $price, 'weight_grams' => 1000, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 20, 'quantity_reserved' => 0]);
        ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'agent', 'amount' => $agentFee, 'is_active' => true]);
        if ($salesFee > 0) {
            ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'sales', 'amount' => $salesFee, 'is_active' => true]);
        }

        return $product;
    }

    private function placeCodOrder(User $konsumen, Product $product): Order
    {
        // Codemod left $agen undefined here: resolve the buyer's branch agen
        // (staff-only COD per Improvement 04) instead of the missing parameter.
        $agen = User::query()->findOrFail($konsumen->agent_id);
        $response = $this->actingAs($agen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'konsumen_id' => $konsumen->id,
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);
        $response->assertCreated();

        return Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
    }

    public function test_network_summary_is_scoped_to_the_actors_own_branch(): void
    {
        $branchA = $this->branch();
        $branchB = $this->branch();

        $this->placeCodOrder($branchA['directKonsumen'], $this->makeProduct($branchA['agen']));
        $this->placeCodOrder($branchB['directKonsumen'], $this->makeProduct($branchB['agen']));

        $superAdmin = User::factory()->superAdmin()->create();
        $superResponse = $this->actingAs($superAdmin)->getJson('/api/v1/reports/network-summary');
        $superResponse->assertOk();
        $this->assertSame(2, $superResponse->json('data.totals.order_count'));
        $this->assertCount(2, $superResponse->json('data.per_agen'));

        // Agen and Keuangan both see ONLY their own branch's single order.
        foreach (['agen', 'keuangan'] as $role) {
            $response = $this->actingAs($branchA[$role])->getJson('/api/v1/reports/network-summary');
            $response->assertOk();
            $this->assertSame(1, $response->json('data.totals.order_count'), "{$role} must be confined to its own branch");
            $this->assertCount(1, $response->json('data.per_agen'));
            $this->assertSame($branchA['agen']->id, $response->json('data.per_agen.0.agent_id'));
        }

        // Admin does not get the agent-fee-bearing consolidated rollup at all.
        $this->actingAs($branchA['admin'])->getJson('/api/v1/reports/network-summary')->assertStatus(403);

        // A foreign-branch keuangan cannot reach another branch's numbers either.
        $this->actingAs($branchB['keuangan'])->getJson('/api/v1/reports/network-summary?agent_id='.$branchA['agen']->id)
            ->assertOk()
            ->assertJsonPath('data.totals.order_count', 1);
        $this->assertSame(
            $branchB['agen']->id,
            $this->actingAs($branchB['keuangan'])->getJson('/api/v1/reports/network-summary?agent_id='.$branchA['agen']->id)->json('data.per_agen.0.agent_id'),
            'agent_id cannot be used to escape the branch'
        );
    }

    /**
     * agent_fee is the branch owner's own margin — never exposed to admin/
     * keuangan, in any scenario. When a konsumen was referred DIRECTLY by
     * the agen (no sales in between), the agen additionally earns a
     * SEPARATE sales-role commission for that referral (two distinct
     * commissions, never one merged into the other) — that sales commission
     * is what admin/keuangan see, via the fee type they're already allowed.
     */
    public function test_agent_fee_is_never_visible_to_admin_or_keuangan_direct_referral_earns_a_separate_sales_commission(): void
    {
        $branch = $this->branch();
        $product = $this->makeProduct($branch['agen'], agentFee: 10000, salesFee: 4000);

        $directOrder = $this->placeCodOrder($branch['directKonsumen'], $product);
        $salesOrder = $this->placeCodOrder($branch['salesKonsumen'], $product);

        // Sanity: the direct order really has no sales attribution.
        $this->assertNull($directOrder->sales_id);
        $this->assertNotNull($salesOrder->sales_id);

        foreach (['admin', 'keuangan'] as $role) {
            $direct = $this->actingAs($branch[$role])->getJson("/api/v1/orders/{$directOrder->id}");
            $direct->assertOk();
            $this->assertArrayNotHasKey('agent_fee_amount', $direct->json('data.items.0'), "{$role} must NEVER see agent_fee, even for a direct referral");
            $this->assertSame(4000, (int) $direct->json('data.items.0.sales_fee_amount'), "{$role} sees the agen's referral commission AS sales_fee");

            $viaSales = $this->actingAs($branch[$role])->getJson("/api/v1/orders/{$salesOrder->id}");
            $viaSales->assertOk();
            $this->assertArrayNotHasKey('agent_fee_amount', $viaSales->json('data.items.0'), "{$role} must NOT see agent fee");
            $this->assertSame(4000, (int) $viaSales->json('data.items.0.sales_fee_amount'));
        }

        // Two distinct commissions exist for the direct order: the agen's own
        // 'agent' commission (hidden from keuangan) AND a SEPARATE 'sales'
        // commission also paid to the agen (visible to keuangan) — never merged.
        $this->assertDatabaseHas('commissions', [
            'order_id' => $directOrder->id, 'beneficiary_user_id' => $branch['agen']->id,
            'beneficiary_role' => 'agent', 'amount' => 10000,
        ]);
        $this->assertDatabaseHas('commissions', [
            'order_id' => $directOrder->id, 'beneficiary_user_id' => $branch['agen']->id,
            'beneficiary_role' => 'sales', 'amount' => 4000,
        ]);

        // The commission summary never exposes agent fee to keuangan at all,
        // and sales_fee sums both the direct-referral (agen) and normal
        // (sales rep) commissions within the branch.
        $summary = $this->actingAs($branch['keuangan'])->getJson('/api/v1/commissions/summary');
        $summary->assertOk();
        $this->assertArrayNotHasKey('total_agent_fee', $summary->json('data'));
        $this->assertSame(8000.0, (float) $summary->json('data.total_sales_fee'));
    }

    /** A korsal who is themselves a konsumen's direct referrer (no sales beneath them) earns their own sales commission, and can see it via /commissions. */
    public function test_korsal_direct_referral_earns_and_can_view_their_own_sales_commission(): void
    {
        $branch = $this->branch();
        $product = $this->makeProduct($branch['agen'], agentFee: 10000, salesFee: 5000);

        $korsalDirectKonsumen = User::factory()->konsumen()->create([
            'agent_id' => $branch['agen']->id, 'korsal_id' => $branch['korsal']->id, 'parent_id' => $branch['korsal']->id,
        ]);

        $order = $this->placeCodOrder($korsalDirectKonsumen, $product);
        $this->assertNull($order->sales_id);

        $this->assertDatabaseHas('commissions', [
            'order_id' => $order->id, 'beneficiary_user_id' => $branch['korsal']->id,
            'beneficiary_role' => 'sales', 'amount' => 5000,
        ]);

        $list = $this->actingAs($branch['korsal'])->getJson('/api/v1/commissions');
        $list->assertOk();
        $this->assertTrue(
            collect($list->json('data'))->contains(fn ($c) => (float) $c['amount'] === 5000.0),
            'korsal must see their own direct-referral commission'
        );
    }

    /**
     * Improvement 10 + Owner-approved centralized Piutang menu (§8B): the
     * finance-orders rows carry the per-order due date and overdue flag so the
     * Admin/Keuangan receivables view can filter Belum Lunas / Jatuh Tempo /
     * Terlambat from the SAME financial source — no second truth.
     */
    public function test_finance_orders_rows_carry_due_date_and_overdue_flag_for_the_piutang_menu(): void
    {
        $branch = $this->branch();
        $product = $this->makeProduct($branch['agen']);
        $withDue = $this->placeCodOrder($branch['directKonsumen'], $product);
        $noDue = $this->placeCodOrder($branch['salesKonsumen'], $this->makeProduct($branch['agen']));
        for ($i = 0; $i < 14; $i++) {
            $this->placeCodOrder($branch['directKonsumen'], $product);
        }

        // Due-date authority is Admin/Keuangan (MasterV2 #10), not the agen.
        $this->actingAs($branch['keuangan'])->putJson("/api/v1/orders/{$withDue->id}/due-date", [
            'due_date' => now()->subDay()->toDateString(), 'reason' => 'Piutang jatuh tempo kemarin',
        ])->assertOk();

        $resp = $this->actingAs($branch['keuangan'])->getJson('/api/v1/reports/finance-orders?per_page=20');
        $rows = collect($resp->json('data'))->keyBy('order_id');

        $this->assertTrue($rows->has($withDue->id) && $rows->has($noDue->id));
        $this->assertSame(now()->subDay()->toDateString(), $rows[$withDue->id]['due_date']);
        $this->assertTrue($rows[$withDue->id]['is_overdue'], 'a past due date must be flagged overdue');
        $this->assertNull($rows[$noDue->id]['due_date']);
        $this->assertFalse($rows[$noDue->id]['is_overdue']);
        $this->assertGreaterThan(0, (float) $rows[$withDue->id]['remaining'], 'receivables row must expose outstanding balance');

        $paged = $this->actingAs($branch['keuangan'])->getJson('/api/v1/reports/finance-orders?receivable=1&per_page=15');
        $paged->assertOk()->assertJsonCount(15, 'data');
        $this->assertSame(16, $paged->json('meta.total'));
        $this->assertSame(16, $paged->json('meta.summary.count'));
        $this->assertGreaterThan(0, (float) $paged->json('meta.summary.outstanding'));

        $overdue = $this->actingAs($branch['keuangan'])->getJson('/api/v1/reports/finance-orders?receivable=1&due_filter=overdue');
        $overdue->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.order_id', $withDue->id);

        // The centralized menu is Admin/Keuangan territory: a plain korsal
        // never reaches the finance-orders source.
        $this->actingAs($branch['korsal'])->getJson('/api/v1/reports/finance-orders')->assertStatus(403);
    }
}
