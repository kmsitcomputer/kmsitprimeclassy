<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 2 reporting directives: network transaction totals (§7, order-grained,
 * unattributed separated) and the CRM visit funnel with SimpleV2's 0–7/N
 * semantics (§6). Also guards AC-13 (no double count), AC-16-17 (admin never
 * sees agent fee — none of these responses carry agent-fee data at all;
 * cross-agent isolation) and the xlsx export branches.
 */
class NetworkAndFunnelReportTest extends TestCase
{
    use RefreshDatabase;

    private array $U = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $agenA = User::factory()->agen()->create();
        $agenA->update(['agent_id' => $agenA->id]);
        $agenB = User::factory()->agen()->create();
        $agenB->update(['agent_id' => $agenB->id]);
        $konsumenA = User::factory()->konsumen()->create(['agent_id' => $agenA->id]);
        $adminA = User::factory()->admin()->create(['agent_id' => $agenA->id]);
        $keuanganA = User::factory()->keuangan()->create(['agent_id' => $agenA->id]);
        $k1 = User::factory()->korsal()->create(['agent_id' => $agenA->id, 'korsal_id' => null]);
        $s1 = User::factory()->sales()->create(['agent_id' => $agenA->id, 'korsal_id' => $k1->id]);
        $s2 = User::factory()->sales()->create(['agent_id' => $agenA->id, 'korsal_id' => $k1->id]);
        $sB = User::factory()->sales()->create(['agent_id' => $agenB->id]);

        $this->U = compact('agenA', 'agenB', 'konsumenA', 'adminA', 'keuanganA', 'k1', 's1', 's2', 'sB');

        // Orders (order-grained fixture — NO items needed):
        $this->order('PC-NW-001', $agenA->id, $konsumenA->id, $s1->id, $k1->id, 100000, 40000, 'diproses');
        $this->order('PC-NW-002', $agenA->id, $konsumenA->id, $s2->id, $k1->id, 200000, 0, 'diterima');
        $this->order('PC-NW-003', $agenA->id, $konsumenA->id, null, null, 50000, 50000, 'terkirim');   // direct, unattributed
        $this->order('PC-NW-004', $agenA->id, $konsumenA->id, $s1->id, $k1->id, 30000, 10000, 'dibatalkan'); // collectible 0
        $selfOrder = $this->order('PC-NW-005', $agenA->id, $s1->id, null, null, 70000, 0, 'diproses');  // s1 self-purchase
        $this->order('PC-NW-006', $agenB->id, null, $sB->id, null, 90000, 0, 'diproses');

        // CRM funnel fixtures.
        $now = '2026-10-20 08:00:00';
        $c1 = $this->customer($agenA->id, $s1->id, 'C1', $now);
        $c2 = $this->customer($agenA->id, $s1->id, 'C2', $now);
        $c3 = $this->customer($agenA->id, $s2->id, 'C3', $now);
        $this->visit($agenA->id, $c1, $s1->id, '2026-10-01', '1');
        $this->visit($agenA->id, $c1, $s1->id, '2026-10-10', '7');
        $this->visit($agenA->id, $c3, $s2->id, '2026-10-05', 'N');
    }

    private function order(string $no, int $agentId, ?int $konsumenId, ?int $salesId, ?int $korsalId, int $total, int $paid, string $status): void
    {
        DB::table('orders')->insert([
            'order_no' => $no, 'agent_id' => $agentId, 'konsumen_id' => $konsumenId,
            'sales_id' => $salesId, 'korsal_id' => $korsalId,
            'status' => $status, 'payment_status' => $paid > 0 ? 'partially_paid' : 'unpaid',
            'subtotal_amount' => $total, 'discount_amount' => 0, 'shipping_fee_amount' => 0,
            'total_amount' => $total, 'paid_amount' => $paid, 'remaining_amount' => 0,
            'recipient_name_snapshot' => 'X', 'recipient_phone_snapshot' => '0', 'address_snapshot' => 'y',
            'created_at' => '2026-10-20 08:00:00', 'updated_at' => '2026-10-20 08:00:00',
        ]);
    }

    private function customer(int $agentId, int $ownerId, string $name, string $ts): int
    {
        $id = DB::table('crm_customers')->insertGetId([
            'agent_id' => $agentId, 'owner_user_id' => $ownerId, 'name' => $name,
            'phone' => '08'.$ownerId.str_pad((string) crc32($name), 4, '0', STR_PAD_LEFT),
            'created_at' => $ts, 'updated_at' => $ts,
        ]);

        return $id;
    }

    private function visit(int $agentId, int $customerId, int $userId, string $date, string $status): void
    {
        DB::table('crm_visits')->insert([
            'agent_id' => $agentId, 'crm_customer_id' => $customerId, 'user_id' => $userId,
            'visit_date' => $date, 'status' => $status, 'created_at' => '2026-10-20', 'updated_at' => '2026-10-20',
        ]);
    }

    public function test_sales_network_totals_include_self_purchase_and_cancelled_canonical(): void
    {
        $r = $this->actingAs($this->U['s1'])->getJson('/api/v1/reports/network-transactions')->assertOk()->json('data');
        // O1 (attributed) + O4 (cancelled, collectible 0) + O5 (self-purchase).
        $this->assertSame(3, $r['totals']['order_count']);
        $this->assertEquals(200000, $r['totals']['total_amount']);          // 100k+30k+70k
        $this->assertEquals(130000, $r['totals']['outstanding_amount']);     // 60k + 0 (cancelled, WS-7) + 70k
        $this->assertNull($r['by_sales']);   // a sales sees no below-node breakdown
        $this->assertNull($r['by_korsal']);
    }

    public function test_sales_dashboard_now_counts_self_purchase_orders_like_the_network_report(): void
    {
        // WS8 reconciliation (Melina/Purwanti/Gani): a historic self-purchase
        // has sales_id = NULL and konsumen_id = the seller. The dashboard used
        // to show 0 while the network report showed the order; both now use the
        // same canonical attribution — and the numbers must agree.
        $dash = $this->actingAs($this->U['s1'])->getJson('/api/v1/dashboard/summary')->assertOk()->json('data');
        $net = $this->actingAs($this->U['s1'])->getJson('/api/v1/reports/network-transactions')->assertOk()->json('data');
        $this->assertSame(3, $dash['total_orders']);   // O1 + O4 + O5 (self-purchase)
        $this->assertSame($net['totals']['order_count'], $dash['total_orders']);
    }

    public function test_korsal_network_sees_downline_and_totals_match_transactions_scope(): void
    {
        $r = $this->actingAs($this->U['k1'])->getJson('/api/v1/reports/network-transactions')->assertOk()->json('data');
        // korsalAttr = k1 -> O1,O2,O4 (O5 korsal_id null & buyer is sales, not korsal).
        $this->assertSame(3, $r['totals']['order_count']);
        $salesIds = collect($r['by_sales'])->pluck('id')->filter()->all();
        $this->assertEqualsCanonicalizing([$this->U['s1']->id, $this->U['s2']->id], $salesIds);
        $this->assertNull($r['by_korsal']); // korsal does not see sibling korsals
    }

    public function test_agen_breakdowns_partition_every_order_exactly_once(): void
    {
        $r = $this->actingAs($this->U['agenA'])->getJson('/api/v1/reports/network-transactions')->assertOk()->json('data');
        $this->assertSame(5, $r['totals']['order_count']);
        // AC-13: sum over each breakdown == distinct order count (no double count, none lost).
        $this->assertSame(5, collect($r['by_korsal'])->sum('order_count'));
        $this->assertSame(5, collect($r['by_sales'])->sum('order_count'));
        $names = collect($r['by_korsal'])->pluck('name');
        $this->assertTrue($names->contains('TANPA KORSAL'));
        $this->assertTrue(collect($r['by_sales'])->pluck('name')->contains('TANPA SALES (langsung/agen)'));
        // O3 (unattributed, fully paid) must not inflate outstanding:
        // 60k (O1) + 200k (O2) + 0 (O3 paid) + 0 (O4 cancelled) + 70k (O5) = 330k.
        $this->assertEquals(330000, $r['totals']['outstanding_amount']);
    }

    public function test_cross_agent_isolation_and_super_admin_scope(): void
    {
        $b = $this->actingAs($this->U['agenB'])->getJson('/api/v1/reports/network-transactions')->assertOk()->json('data');
        $this->assertSame(1, $b['totals']['order_count']);

        $all = $this->actingAs(User::factory()->superAdmin()->create())->getJson('/api/v1/reports/network-transactions')->assertOk()->json('data');
        $this->assertSame(6, $all['totals']['order_count']);
        $one = $this->actingAs(User::factory()->superAdmin()->create())->getJson('/api/v1/reports/network-transactions?agent_id='.$this->U['agenA']->id)->assertOk()->json('data');
        $this->assertSame(5, $one['totals']['order_count']);
    }

    public function test_roles_without_scope_are_rejected(): void
    {
        $this->actingAs($this->U['keuanganA'])->getJson('/api/v1/reports/network-transactions')->assertForbidden();
    }

    public function test_funnel_uses_latest_visit_per_customer_with_sv2_labels(): void
    {
        $r = $this->actingAs($this->U['s1'])->getJson('/api/v1/reports/crm-visits')->assertOk()->json('data');
        $this->assertSame(2, $r['totals']['customers']);
        $this->assertSame(2, $r['totals']['visits']);                 // C1 twice
        $this->assertSame(1, $r['totals']['buckets']['7']);            // latest '7' supersedes '1' => one Deal
        $this->assertSame(0, $r['totals']['buckets']['1']);            // older '1' no longer counts
        $this->assertSame(1, $r['totals']['buckets']['0']);            // C2 never visited
        $this->assertSame('Deal', $r['bucket_labels']['7']);
        $this->assertSame('Nolak', $r['bucket_labels']['N']);

        $k = $this->actingAs($this->U['k1'])->getJson('/api/v1/reports/crm-visits')->assertOk()->json('data');
        $this->assertSame(3, $k['totals']['customers']);               // downline owners included
        $this->assertSame(1, $k['totals']['buckets']['N']);
        $this->assertSame(2, count($k['rows']));

        // Period window restricts which visits count; outside -> bucket 0.
        $p = $this->actingAs($this->U['s1'])->getJson('/api/v1/reports/crm-visits?from=2026-10-02&to=2026-10-09')->assertOk()->json('data');
        $this->assertSame(2, $p['totals']['buckets']['0']);
    }

    public function test_funnel_is_agent_isolated(): void
    {
        $b = $this->actingAs($this->U['agenB'])->getJson('/api/v1/reports/crm-visits')->assertOk()->json('data');
        $this->assertSame(0, $b['totals']['customers']);
    }

    public function test_xlsx_export_branches_stream(): void
    {
        foreach (['/api/v1/reports/network-transactions?export=xlsx', '/api/v1/reports/crm-visits?export=xlsx', '/api/v1/reports/finance-orders?export=xlsx'] as $url) {
            $res = $this->actingAs($this->U['agenA'])->get($url);
            $res->assertOk();
            $this->assertStringContainsString('spreadsheet', $res->headers->get('content-type') ?? '');
        }
    }

    public function test_admin_network_report_contains_no_agent_fee_data(): void
    {
        // AC-16: agent fee lives only in fees/agent (admin 403 there). The
        // network/funnel payloads must carry no fee keys at all.
        DB::table('commissions')->insert([
            ['order_id' => DB::table('orders')->where('order_no', 'PC-NW-001')->value('id'),
             'order_item_id' => null, 'beneficiary_user_id' => $this->U['agenA']->id, 'beneficiary_role' => 'agent',
             'amount' => 12345, 'status' => 'pending', 'earned_at' => '2026-10-20', 'created_at' => '2026-10-20', 'updated_at' => '2026-10-20'],
        ]);
        $body = json_encode($this->actingAs($this->U['adminA'])->getJson('/api/v1/reports/network-transactions')->assertOk()->json());
        $this->assertStringNotContainsString('12345', $body);
        $this->assertStringNotContainsString('agent_fee', $body);
    }
}
