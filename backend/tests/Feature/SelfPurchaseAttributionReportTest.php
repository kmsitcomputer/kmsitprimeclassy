<?php

namespace Tests\Feature;

use App\Console\Commands\SelfPurchaseAttributionReportCommand;
use App\Models\User;
use App\Services\Report\SelfPurchaseAttributionReport;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * G-13 (Owner decision 2026-10-30): the historical SKS self-purchase
 * attribution reconciliation is READ-ONLY. These tests pin: the mismatch
 * predicate (only a sales-kurir-sub buyer's sales-fee line booked to someone
 * other than the buyer is listed — agent-fee lines, correctly self-attributed
 * lines, non-SKS buyers and ordinary konsumen orders never appear), and the
 * ZERO-WRITE guarantee (order/commission/ledger data is byte-identical before
 * and after both the service report and the artisan command; the command has
 * no execution option at all). Runs on `primeclassy_testing` only.
 */
class SelfPurchaseAttributionReportTest extends TestCase
{
    use RefreshDatabase;

    private array $U = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id]);
        $sks = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id, 'korsal_id' => $korsal->id]);
        $sales = User::factory()->sales()->create(['agent_id' => $agen->id, 'korsal_id' => $korsal->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);

        $this->U = compact('agen', 'korsal', 'sks', 'sales', 'konsumen');

        $skSelf = $this->order('PC-SP-001', $agen->id, $sks->id, null, null, 'diproses');        // legacy SKS self-purchase
        $skOk = $this->order('PC-SP-002', $agen->id, $sks->id, $sks->id, null, 'terkirim');      // correctly self-attributed
        $ko = $this->order('PC-SP-003', $agen->id, $konsumen->id, $sales->id, $korsal->id, 'terkirim'); // ordinary konsumen order
        $skAgent = $this->order('PC-SP-004', $agen->id, $sks->id, null, null, 'dibatalkan');     // SKS self-purchase, fee to agen
        $salesSelf = $this->order('PC-SP-005', $agen->id, $sales->id, null, null, 'diproses');   // a SALES self-purchase (out of G-13 scope)

        $this->commission($skSelf, $agen->id, 'agent', 800);    // agent fee: never a mismatch
        $mismatchKorsal = $this->commission($skSelf, $korsal->id, 'sales', 1500);
        $this->commission($skOk, $sks->id, 'sales', 1500);      // correct self-attribution
        $this->commission($ko, $sales->id, 'sales', 1500);      // buyer is a konsumen: never listed
        $mismatchAgen = $this->commission($skAgent, $agen->id, 'sales', 900);
        $this->commission($salesSelf, $korsal->id, 'sales', 700); // buyer is sales, not SKS: out of scope

        $this->mismatches = [$mismatchKorsal, $mismatchAgen];
    }

    /** @var list<int> */
    private array $mismatches = [];

    public function test_the_report_lists_exactly_the_sks_self_purchase_mismatches(): void
    {
        $report = app(SelfPurchaseAttributionReport::class)->report();

        $this->assertSame(2, $report['totals']['mismatched_commission_lines']);
        $this->assertSame(2, $report['totals']['mismatched_orders']);
        $this->assertEquals(2400.0, $report['totals']['mismatched_sales_fee_amount']);

        $byNo = collect($report['orders'])->keyBy('order_no');
        $this->assertSame(['PC-SP-001', 'PC-SP-004'], $byNo->keys()->sort()->values()->all());

        $one = $byNo['PC-SP-001'];
        $this->assertSame($this->U['sks']->id, $one['buyer']['user_id']);
        $this->assertSame('sales-kurir-sub', $one['buyer']['role']);
        $this->assertSame(null, $one['sales_id'], 'legacy self-purchase rows carry sales_id = NULL');
        $this->assertEquals(1500.0, $one['mismatched_sales_fee_amount']);
        $this->assertSame($this->U['korsal']->id, $one['commission_lines'][0]['beneficiary_user_id']);
        $this->assertEquals(1500.0, $one['commission_lines'][0]['amount']);

        $four = $byNo['PC-SP-004'];
        $this->assertSame($this->U['agen']->id, $four['commission_lines'][0]['beneficiary_user_id']);
        $this->assertEquals(900.0, $four['mismatched_sales_fee_amount']);

        // The two flagged lines are exactly the two mismatched sales-fee commissions.
        $this->assertSame(
            collect($this->mismatches)->sort()->values()->all(),
            collect($report['orders'])->flatMap(fn ($o) => $o['commission_lines'])->pluck('commission_id')->sort()->values()->all(),
        );

        $this->assertStringContainsString('READ-ONLY', $report['mode']);
        $this->assertStringContainsString('separate Owner approval', $report['correction']);
    }

    public function test_neither_the_service_nor_the_command_changes_any_financial_data(): void
    {
        $snapshot = $this->financialSnapshot();

        app(SelfPurchaseAttributionReport::class)->report();
        $this->artisan('primeclassy:selfpurchase-attribution-report')->assertSuccessful();

        $this->assertSame($snapshot, $this->financialSnapshot(), 'orders/commission/ledger state must be byte-identical after the read-only report');
    }

    public function test_the_command_has_no_execution_option_and_nothing_is_scheduled(): void
    {
        $definition = Artisan::all()['primeclassy:selfpurchase-attribution-report']->getDefinition();
        $this->assertSame([], $definition->getOptions(), 'no flags at all — nothing can ever execute from this command');
        $this->assertSame(SelfPurchaseAttributionReportCommand::class, get_class(Artisan::all()['primeclassy:selfpurchase-attribution-report']));

        $scheduled = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map->command
            ->filter(fn ($c) => str_contains((string) $c, 'selfpurchase'));
        $this->assertCount(0, $scheduled, 'the reconciliation report is never scheduled');
    }

    /** Full byte-level state of the financial tables the report reads. */
    private function financialSnapshot(): array
    {
        return [
            json_encode(DB::table('orders')->orderBy('id')->get()),
            json_encode(DB::table('commissions')->orderBy('id')->get()),
            json_encode(DB::table('commission_adjustments')->orderBy('id')->get()),
            DB::table('activity_logs')->count(),
        ];
    }

    private function order(string $no, int $agentId, int $konsumenId, ?int $salesId, ?int $korsalId, string $status): int
    {
        return DB::table('orders')->insertGetId([
            'order_no' => $no, 'agent_id' => $agentId, 'konsumen_id' => $konsumenId,
            'sales_id' => $salesId, 'korsal_id' => $korsalId,
            'status' => $status, 'payment_status' => 'unpaid',
            'subtotal_amount' => 100000, 'discount_amount' => 0, 'shipping_fee_amount' => 0,
            'total_amount' => 100000, 'paid_amount' => 0, 'remaining_amount' => 0,
            'recipient_name_snapshot' => 'X', 'recipient_phone_snapshot' => '0', 'address_snapshot' => 'y',
            'created_at' => '2026-10-20 08:00:00', 'updated_at' => '2026-10-20 08:00:00',
        ]);
    }

    private function commission(int $orderId, int $beneficiaryId, string $role, float $amount): int
    {
        return DB::table('commissions')->insertGetId([
            'order_id' => $orderId, 'order_item_id' => null,
            'beneficiary_user_id' => $beneficiaryId, 'beneficiary_role' => $role,
            'amount' => $amount, 'status' => 'pending', 'earned_at' => '2026-10-20 08:00:00',
            'created_at' => '2026-10-20 08:00:00', 'updated_at' => '2026-10-20 08:00:00',
        ]);
    }
}
