<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Commission;
use App\Models\Courier;
use App\Models\CrmCustomer;
use App\Models\CrmVisit;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * Owner-approved Phase 2 KPI contracts (GET /dashboard/kpis) + the final Korsal/Sales/SKS commission rules:
 * Komisi Cabang = the Agen's OWN fee; Komisi Tim = per-recipient Sales fee (no Korsal-role fee); Deal vs Komisi are
 * separate metrics; Stok Tersedia = sellable − reserved; Tugas Hari Ini = distinct assigned shipments due today.
 */
class DashboardKpiContractTest extends TestCase
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
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Toko KPI', 'address' => 'Jl. KPI', 'latitude' => -6.2, 'longitude' => 106.8166]);
        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id]);
        $sales = User::factory()->sales()->create(['agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'referral_code' => 'S-'.uniqid()]);
        $sks = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'referral_code' => 'K-'.uniqid()]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agen->id]);
        $kurir = User::factory()->kurir()->create(['agent_id' => $agen->id]);
        Courier::create(['type' => 'internal', 'user_id' => $kurir->id, 'agent_id' => $agen->id, 'name' => $kurir->name, 'is_active' => true]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'sales_id' => $sales->id]);

        return compact('agen', 'korsal', 'sales', 'sks', 'admin', 'gudang', 'kurir', 'konsumen');
    }

    private function product(User $agen, int $price = 50000, int $stock = 10): Product
    {
        $p = Product::create(['sku' => 'KPI-'.Str::uuid(), 'name' => 'Kue KPI', 'slug' => 'kue-kpi-'.uniqid(),
            'has_variations' => false, 'base_price' => $price, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $p->id, 'quantity_on_hand' => $stock, 'quantity_reserved' => 0]);

        return $p;
    }

    private function place(User $buyer, Product $product, int $qty = 1): Order
    {
        $r = $this->actingAs($buyer)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => $qty]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810,
            'delivery_date' => now()->addDays(3)->toDateString(),
        ])->assertCreated();

        return Order::withoutGlobalScopes()->findOrFail($r->json('data.id'));
    }

    private function fee(Order $order, User $to, string $role, float $amount, string $status = 'approved'): Commission
    {
        return Commission::create([
            'order_id' => $order->id, 'order_item_id' => $order->items()->value('id'),
            'beneficiary_user_id' => $to->id, 'beneficiary_role' => $role, 'amount' => $amount, 'status' => $status, 'earned_at' => now(),
        ]);
    }

    private function kpis(User $actor)
    {
        return $this->actingAs($actor)->getJson('/api/v1/dashboard/kpis');
    }

    /* ---------------- Commission ---------------- */

    public function test_agen_komisi_cabang_is_only_the_agens_own_fee_and_never_the_network_pool(): void
    {
        $b = $this->branch();
        $order = $this->place($b['konsumen'], $this->product($b['agen']));
        Commission::query()->delete();
        $this->fee($order, $b['agen'], 'agent', 1000, 'approved');
        $this->fee($order, $b['agen'], 'agent', 500, 'pending');
        $this->fee($order, $b['agen'], 'agent', 300, 'reversed');       // reported, never added
        $this->fee($order, $b['sales'], 'sales', 7000);                 // downline fee: NOT the agen's
        $this->fee($order, $b['sks'], 'sales', 8000);
        $this->fee($order, $b['kurir'], 'courier', 9000);

        $own = $this->kpis($b['agen'])->assertOk()->json('data.own_fee');
        $this->assertEquals(1500, $own['total']);
        $this->assertEquals(300, $own['reversed']);
        $this->assertEquals(['agent' => 1500], $own['by_role']);
        $this->assertSame(2, $own['count']);
    }

    public function test_korsal_has_no_korsal_role_fee_and_a_downline_sale_creates_none(): void
    {
        $b = $this->branch();
        $this->place($b['konsumen'], $this->product($b['agen'], 40000));   // downline sale referred by Sales

        $roles = Commission::query()->distinct()->pluck('beneficiary_role')->all();
        $this->assertEmpty(array_diff($roles, ['agent', 'sales', 'courier']), 'Only agent/sales/courier ledger roles may exist.');
        $this->assertSame(0, Commission::where('beneficiary_user_id', $b['korsal']->id)->count(), 'Korsal earns nothing from a downline sale.');
    }

    public function test_korsal_team_commissions_are_per_recipient_including_own_sales_fee_without_duplicates_or_leaks(): void
    {
        $b = $this->branch();
        $order = $this->place($b['konsumen'], $this->product($b['agen']));
        Commission::query()->delete();
        $this->fee($order, $b['sales'], 'sales', 2000, 'paid');
        $this->fee($order, $b['sks'], 'sales', 3000, 'pending');
        $this->fee($order, $b['korsal'], 'sales', 4000, 'approved');       // Korsal's OWN Sales fee
        $this->fee($order, $b['agen'], 'agent', 99999);                    // never in a team breakdown

        // A sibling Korsal's Sales and a different branch must not leak.
        $other = $this->branch();
        $this->fee($order, $other['sales'], 'sales', 5555);

        $team = $this->kpis($b['korsal'])->assertOk()->json('data.team_commissions');
        $byId = collect($team['members'])->keyBy('user_id');

        $this->assertEqualsCanonicalizing([$b['korsal']->id, $b['sales']->id, $b['sks']->id], $byId->keys()->all());
        $this->assertEquals(4000, $byId[$b['korsal']->id]['total']);
        $this->assertTrue($byId[$b['korsal']->id]['is_self']);
        $this->assertEquals(2000, $byId[$b['sales']->id]['paid']);
        $this->assertEquals(3000, $byId[$b['sks']->id]['pending']);
        $this->assertEquals(9000, $team['totals']['total']);               // = 2000 + 3000 + 4000, no double count, no agent fee
        $this->assertArrayNotHasKey('own_fee', $this->kpis($b['korsal'])->json('data'));
    }

    public function test_admin_and_other_non_defined_roles_cannot_read_kpi_or_agen_fee_data(): void
    {
        $b = $this->branch();
        $this->kpis($b['admin'])->assertForbidden();
        $this->kpis($b['konsumen'])->assertForbidden();

        // Admin's commission summary still never carries the agent component.
        $summary = $this->actingAs($b['admin'])->getJson('/api/v1/commissions/summary')->assertOk()->json('data');
        $this->assertArrayNotHasKey('total_agent_fee', $summary);
    }

    /* ---------------- Deals (network) vs commission ---------------- */

    public function test_sales_deal_counts_attributed_orders_without_a_commission_and_commission_is_separate(): void
    {
        $b = $this->branch();
        $own = $this->place($b['sales'], $this->product($b['agen'], 260000));   // unpaid self-purchase
        $this->place($b['konsumen'], $this->product($b['agen'], 10000));        // referred by Sales
        Commission::query()->delete();
        $this->fee($own, $b['sales'], 'sales', 1200, 'approved');

        $data = $this->kpis($b['sales'])->assertOk()->json('data');
        $this->assertSame(2, $data['deals']['count']);
        $this->assertEquals(270000, $data['deals']['value']);
        $this->assertEquals(1200, $data['commission']['total']);
        $this->assertArrayNotHasKey('available_stock', $data);
        $this->assertArrayNotHasKey('own_fee', $data);
    }

    public function test_an_upline_sales_named_in_a_seller_buyers_sales_id_is_not_double_credited(): void
    {
        $b = $this->branch();
        $downSales = User::factory()->sales()->create([
            'agent_id' => $b['agen']->id, 'korsal_id' => $b['korsal']->id, 'sales_id' => $b['sales']->id, 'referral_code' => 'D-'.uniqid(),
        ]);
        $this->place($downSales, $this->product($b['agen']));

        $this->assertSame(1, $this->kpis($downSales)->json('data.deals.count'));
        $this->assertSame(0, $this->kpis($b['sales'])->json('data.deals.count'), 'Seller-buyer purchases are attributed to the buyer only.');
        $this->assertSame(0, $this->actingAs($b['sales'])->getJson('/api/v1/reports/my-customers')->json('meta.total'));
        $this->assertSame(1, $this->actingAs($downSales)->getJson('/api/v1/reports/my-customers')->json('meta.total'));
        $this->assertSame(1, (int) $this->kpis($b['korsal'])->json('data.deals.count'), 'Korsal team deals include downline sellers once.');
    }

    /* ---------------- Stage 1: Gani / Purwanti / Melina DEV evidence ---------------- */

    public function test_admin_customer_report_shows_unpaid_order_value_not_rp0_and_korsal_personal_scope_stays_personal(): void
    {
        $b = $this->branch();
        $melina = $b['sks']; // SKS under the Korsal, like DEV user 31 under 30
        $this->place($melina, $this->product($b['agen'], 260000));
        $this->place($melina, $this->product($b['agen'], 220000));

        // Admin (Gani) Laporan Pelanggan: 2 orders, nothing paid. The old "Total Belanja" showed Rp0.
        $row = collect($this->actingAs($b['admin'])->getJson('/api/v1/reports/customers')->assertOk()->json('data'))->firstWhere('konsumen_id', $melina->id);
        $this->assertSame(2, (int) $row['order_count']);
        $this->assertEquals(480000, $row['total_ordered']);
        $this->assertEquals(0, $row['total_paid']);
        $this->assertEquals(0, $row['total_spent'], 'historical key = fully-paid value, unchanged');

        // A cancelled order never inflates the ordered value.
        Order::withoutGlobalScopes()->where('konsumen_id', $melina->id)->orderBy('id')->first()->forceFill(['status' => 'dibatalkan'])->saveQuietly();
        $row = collect($this->actingAs($b['admin'])->getJson('/api/v1/reports/customers')->json('data'))->firstWhere('konsumen_id', $melina->id);
        $this->assertEquals(220000, $row['total_ordered']);

        // Purwanti (Korsal): personal "Konsumen Saya" is empty (no direct customer / own purchase) while the TEAM views count both orders.
        $this->assertSame(0, $this->actingAs($b['korsal'])->getJson('/api/v1/reports/my-customers')->json('meta.total'));
        $team = collect($this->actingAs($b['agen'])->getJson('/api/v1/reports/korsal')->json('data'))->firstWhere('korsal_id', $b['korsal']->id);
        $this->assertSame(2, (int) $team['transaction_count']);
        $this->assertSame(2, (int) $this->actingAs($b['korsal'])->getJson('/api/v1/dashboard/summary')->json('data.total_orders'));
        $this->assertSame(2, $this->kpis($b['korsal'])->json('data.deals.count'));
    }

    /* ---------------- Inventory & tasks ---------------- */

    public function test_gudang_available_stock_is_sellable_minus_reserved_within_its_own_branch(): void
    {
        $b = $this->branch();
        $p = $this->product($b['agen'], 1000, 10);
        ProductStock::where('product_id', $p->id)->update(['quantity_on_hand' => 10, 'quantity_reserved' => 3]);
        $p2 = $this->product($b['agen'], 1000, 4);

        $other = $this->branch();
        $this->product($other['agen'], 1000, 99);

        $this->assertSame(7 + 4, $this->kpis($b['gudang'])->assertOk()->json('data.available_stock.units'));

        ProductStock::where('product_id', $p2->id)->update(['quantity_on_hand' => 2, 'quantity_reserved' => 5]);
        $this->assertSame(7, $this->kpis($b['gudang'])->json('data.available_stock.units'), 'Never negative.');
    }

    public function test_kurir_tasks_today_count_distinct_assigned_shipments_due_today_in_wib(): void
    {
        $b = $this->branch();
        $order = $this->place($b['konsumen'], $this->product($b['agen']), 2);
        $courier = Courier::where('user_id', $b['kurir']->id)->firstOrFail();
        $today = Carbon::now('Asia/Jakarta')->toDateString();

        $shipment = Shipment::where('order_id', $order->id)->firstOrFail();
        $shipment->update(['courier_id' => $courier->id]);
        OrderItem::where('order_id', $order->id)->update(['requested_delivery_date' => $today, 'status' => 'diproses', 'shipment_id' => $shipment->id]);
        $this->assertSame(1, $this->kpis($b['kurir'])->assertOk()->json('data.tasks_today.deliveries'));
        // GAP-A activated: return_pickups is now a real number (0 here — nothing scheduled on this branch).
        $this->assertSame(0, $this->kpis($b['kurir'])->json('data.tasks_today.return_pickups'));

        OrderItem::where('order_id', $order->id)->update(['requested_delivery_date' => Carbon::parse($today)->addDay()->toDateString()]);
        $this->assertSame(0, $this->kpis($b['kurir'])->json('data.tasks_today.deliveries'));

        // Another courier never sees this task.
        $otherKurir = User::factory()->kurir()->create(['agent_id' => $b['agen']->id]);
        Courier::create(['type' => 'internal', 'user_id' => $otherKurir->id, 'agent_id' => $b['agen']->id, 'name' => $otherKurir->name, 'is_active' => true]);
        OrderItem::where('order_id', $order->id)->update(['requested_delivery_date' => $today]);
        $this->assertSame(0, $this->kpis($otherKurir)->json('data.tasks_today.deliveries'));
    }

    public function test_korsal_does_not_inherit_sks_only_stock_or_courier_surfaces(): void
    {
        $b = $this->branch();
        $this->actingAs($b['korsal'])->getJson('/api/v1/sub-stock/my')->assertForbidden();
        $this->actingAs($b['korsal'])->getJson('/api/v1/kurir/orders')->assertForbidden();
        $data = $this->kpis($b['korsal'])->json('data');
        $this->assertArrayNotHasKey('available_stock', $data);
        $this->assertArrayNotHasKey('tasks_today', $data);
    }

    /* ---------------- Korsal acts as Sales (own scope only) ---------------- */

    public function test_korsal_can_run_the_sales_crm_and_assisted_order_for_its_own_customers_and_earns_the_sales_fee(): void
    {
        $b = $this->branch();
        $own = $this->actingAs($b['korsal'])->postJson('/api/v1/crm/customers', ['name' => 'Ibu Korsal', 'phone' => '081200000001'])
            ->assertCreated()->json('data.id');
        $this->actingAs($b['korsal'])->patchJson("/api/v1/crm/customers/{$own}", ['note' => 'ok'])->assertOk();
        $this->actingAs($b['korsal'])->postJson("/api/v1/crm/customers/{$own}/visits", ['visit_date' => now()->toDateString(), 'status' => '3'])->assertCreated();

        // A downline Sales' customer is readable by the Korsal but NOT writable (write = owner only).
        $down = CrmCustomer::create(['agent_id' => $b['agen']->id, 'owner_user_id' => $b['sales']->id, 'name' => 'Down', 'phone' => '081200000002']);
        $this->actingAs($b['korsal'])->getJson("/api/v1/crm/customers/{$down->id}")->assertOk();
        $this->actingAs($b['korsal'])->patchJson("/api/v1/crm/customers/{$down->id}", ['note' => 'x'])->assertForbidden();

        // Another Korsal's network is invisible.
        $other = $this->branch();
        $this->actingAs($other["korsal"])->getJson("/api/v1/crm/customers/{$own}")->assertNotFound(); // cross-Agent rows do not exist for the caller

        // Assisted order for its OWN customer: the Korsal is the Sales-fee beneficiary, and no Korsal-role row exists.
        $product = $this->product($b['agen'], 30000);
        $r = $this->actingAs($b['korsal'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson("/api/v1/crm/customers/{$own}/orders", [
            'payment_method_code' => 'cod', 'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'Ibu Rina', 'recipient_phone' => '0811000111', 'address_line' => 'Jl. Sudirman 1',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810,
        ])->assertCreated();
        $orderId = $r->json('data.id');
        $salesRows = Commission::where('order_id', $orderId)->where('beneficiary_role', 'sales')->get();
        $this->assertTrue($salesRows->every(fn ($c) => $c->beneficiary_user_id === $b['korsal']->id));
        $this->assertEmpty(Commission::where('order_id', $orderId)->pluck('beneficiary_role')->diff(['agent', 'sales'])->all());

        // Konsumen Saya works for the Korsal and the team CRM funnel counts the Korsal's own customer.
        $this->actingAs($b['korsal'])->getJson('/api/v1/reports/my-customers')->assertOk();
        $this->assertSame(2, $this->actingAs($b['korsal'])->getJson('/api/v1/reports/crm-visits')->json('data.totals.customers'));
    }

    /* ---------------- Stage 2: Korsal's remaining Sales workflows (consent / link / refund recipient / fees) ---------------- */

    public function test_korsal_runs_consent_otp_link_candidates_and_fee_view_for_its_own_referrals_only(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $b = $this->branch();
        // A konsumen referred DIRECTLY to the Korsal (no Sales in between) vs the downline Sales' konsumen ($b['konsumen']).
        $direct = User::factory()->konsumen()->create(['agent_id' => $b['agen']->id, 'korsal_id' => $b['korsal']->id, 'sales_id' => null, 'email_verified_at' => now()]);
        $b['konsumen']->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($b['korsal'])->postJson('/api/v1/consent/otp', ['subject_user_id' => $direct->id, 'purpose' => 'assisted_return'])
            ->assertCreated()->assertJsonMissingPath('data.code');
        // Never a downline Sales' konsumen, another team's, or another branch's.
        $this->actingAs($b['korsal'])->postJson('/api/v1/consent/otp', ['subject_user_id' => $b['konsumen']->id, 'purpose' => 'assisted_return'])->assertForbidden();
        $other = $this->branch();
        $foreign = User::factory()->konsumen()->create(['agent_id' => $other['agen']->id, 'korsal_id' => $other['korsal']->id, 'sales_id' => null, 'email_verified_at' => now()]);
        $this->actingAs($b['korsal'])->postJson('/api/v1/consent/otp', ['subject_user_id' => $foreign->id, 'purpose' => 'assisted_return'])->assertForbidden();

        // Link candidates = own direct referrals only (masked), never the downline's.
        $ids = collect($this->actingAs($b['korsal'])->getJson('/api/v1/crm/link-candidates')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$direct->id], $ids);

        // Product fee view: Sales fee only, never the Agent/courier fee; Admin stays out.
        $p = $this->product($b['agen']);
        $fees = $this->actingAs($b['korsal'])->getJson("/api/v1/products/{$p->id}/fees")->assertOk()->json('data');
        $this->assertArrayHasKey('sales_fee', $fees);
        $this->assertArrayNotHasKey('agent_fee', $fees);
        $this->assertArrayNotHasKey('courier_fee', $fees);
        $this->actingAs($b['admin'])->getJson("/api/v1/products/{$p->id}/fees")->assertForbidden();
    }

    public function test_korsal_refund_recipient_evidence_is_limited_to_orders_where_it_is_the_sales_recipient(): void
    {
        $b = $this->branch();
        $product = $this->product($b['agen'], 40000);
        // Refund-recipient evidence exists for CRM-origin (assisted) orders only; the Sales recipient is the CRM customer's owner.
        $assist = function (User $placer, User $owner) use ($b, $product): Order {
            $customer = CrmCustomer::create(['agent_id' => $b['agen']->id, 'owner_user_id' => $owner->id, 'name' => 'C'.uniqid(), 'phone' => '08'.random_int(100000000, 999999999)]);
            $r = $this->actingAs($placer)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson("/api/v1/crm/customers/{$customer->id}/orders", [
                'payment_method_code' => 'cod', 'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'recipient_name' => 'Ibu Rina', 'recipient_phone' => '0811000111', 'address_line' => 'Jl. Sudirman 1',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810,
            ])->assertCreated();

            return Order::withoutGlobalScopes()->findOrFail($r->json('data.id'));
        };
        $own = $assist($b['korsal'], $b['korsal']);        // Korsal's own CRM customer => Korsal is the Sales recipient
        $downline = $assist($b['korsal'], $b['sales']);    // downline Sales' customer (placed by the Korsal) => Sales is the recipient
        $other = $this->branch();

        $payload = ['recipient_name' => 'Ibu Rina', 'method' => 'cash', 'authorization_note' => 'Pelanggan minta tunai.'];

        // Reading follows order visibility (the Korsal sees its network); WRITING follows the Sales-recipient rule.
        $this->actingAs($b['korsal'])->getJson("/api/v1/orders/{$own->id}/refund-recipient")->assertOk();
        $send = fn (User $who, Order $order) => $this->actingAs($who)->post("/api/v1/orders/{$order->id}/refund-recipient", $payload + [
            'evidence' => \Illuminate\Http\UploadedFile::fake()->image('persetujuan.jpg'),
        ], ['Accept' => 'application/json']);
        $send($b['korsal'], $own)->assertCreated();
        $send($b['korsal'], $downline)->assertForbidden();
        $this->assertContains($send($other['korsal'], $own)->status(), [403, 404]);
    }

    public function test_korsal_still_has_no_sks_only_or_coordinator_fee_surfaces_after_the_sales_extension(): void
    {
        $b = $this->branch();
        foreach (['/api/v1/sub-stock/my', '/api/v1/sub-stock/replenishment-targets', '/api/v1/kurir/orders'] as $url) {
            $this->actingAs($b['korsal'])->getJson($url)->assertForbidden();
        }
        $this->assertEmpty(Commission::query()->distinct()->pluck('beneficiary_role')->diff(['agent', 'sales', 'courier'])->all());
    }

    /* ---------------- CRM team ---------------- */

    public function test_korsal_crm_funnel_covers_own_and_downline_activity_only(): void
    {
        $b = $this->branch();
        $other = $this->branch();

        $mk = function (User $owner, string $status) {
            $c = CrmCustomer::create(['agent_id' => $owner->agent_id, 'owner_user_id' => $owner->id, 'name' => 'C'.uniqid(), 'phone' => '08'.random_int(100000000, 999999999)]);
            CrmVisit::create(['agent_id' => $owner->agent_id, 'crm_customer_id' => $c->id, 'user_id' => $owner->id, 'visit_date' => now()->toDateString(), 'status' => $status]);
        };
        $mk($b['korsal'], '7');   // own Deal
        $mk($b['sales'], '2');    // downline prospect
        $mk($b['sks'], 'N');      // downline Nolak
        $mk($other['sales'], '7'); // other team: must not appear

        $t = $this->actingAs($b['korsal'])->getJson('/api/v1/reports/crm-visits')->assertOk()->json('data.totals');
        $this->assertSame(3, $t['customers']);
        $this->assertSame(1, $t['buckets']['7']);
        $this->assertSame(1, $t['buckets']['2']);
        $this->assertSame(1, $t['buckets']['N']);
    }
}
