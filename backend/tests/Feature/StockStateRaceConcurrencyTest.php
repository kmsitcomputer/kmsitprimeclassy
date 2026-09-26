<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use App\Services\Stock\StockOpnameService;
use App\Services\Stock\StockTransferService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ConcurrencyHarness;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

/**
 * Terminal-state protection under real concurrency (separate processes and
 * MySQL connections): an approved transfer can never be cancelled afterwards,
 * and an approved opname can never be flipped to rejected after its stock
 * adjustment was applied. Exactly one side wins each round.
 */
class StockStateRaceConcurrencyTest extends TestCase
{
    use RestoresIsolatedTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_transfer_approve_and_cancel_never_leave_a_cancelled_transfer_with_moved_stock(): void
    {
        $outcomes = [];

        for ($iteration = 1; $iteration <= 5; $iteration++) {
            $fixture = $this->createFixture();

            try {
                $report = (new ConcurrencyHarness)->runServiceRace(
                    ['op' => 'transfer-approve', 'actor_id' => $fixture['admin']->id, 'subject_id' => $fixture['transfer']->id],
                    ['op' => 'transfer-cancel', 'actor_id' => $fixture['gudang_b']->id, 'subject_id' => $fixture['transfer']->id],
                );

                $transfer = StockTransfer::withoutGlobalScopes()->findOrFail($fixture['transfer']->id);
                $transit = $this->transit($fixture);
                $sub = $this->sub($fixture);
                $movements = StockMovement::withoutGlobalScopes()->where('transfer_id', $transfer->id)->count();
                $successes = collect([$report['a'], $report['b']])->where('outcome', 'success')->count();

                $this->assertTrue($report['different_connections']);
                $this->assertTrue($report['true_overlap']);
                $this->assertSame(1, $successes, 'Exactly one of approve/cancel may win: '.json_encode($report));
                $this->assertSame(10, $transit + $sub, 'Physical stock must be conserved.');

                if ($report['a']['outcome'] === 'success') {
                    $outcomes[] = 'approve_wins';
                    $this->assertSame('completed', $transfer->status);
                    $this->assertSame(6, $transit);
                    $this->assertSame(4, $sub);
                    $this->assertSame(2, $movements);
                } else {
                    $outcomes[] = 'cancel_wins';
                    $this->assertSame('cancelled', $transfer->status);
                    $this->assertSame(10, $transit);
                    $this->assertSame(0, $sub);
                    $this->assertSame(0, $movements);
                }
                $this->assertSame($transfer->status === 'completed' ? 1 : 0, DB::table('stock_handovers')->where('stock_transfer_id', $transfer->id)->count());

                fwrite(STDOUT, 'TRANSFER_STATE_RACE '.json_encode(['iteration' => $iteration, 'winner' => end($outcomes), 'status' => $transfer->status, 'transit' => $transit, 'sub' => $sub, 'movements' => $movements], JSON_UNESCAPED_SLASHES).PHP_EOL);
            } finally {
                $this->cleanupFixture($fixture);
            }
        }

        $this->assertCount(5, $outcomes);
    }

    public function test_opname_approve_and_reject_never_leave_a_rejected_opname_with_applied_stock(): void
    {
        $outcomes = [];

        for ($iteration = 1; $iteration <= 5; $iteration++) {
            $fixture = $this->createFixture();

            try {
                $report = (new ConcurrencyHarness)->runServiceRace(
                    ['op' => 'opname-approve', 'actor_id' => $fixture['admin']->id, 'subject_id' => $fixture['opname']->id],
                    ['op' => 'opname-reject', 'actor_id' => $fixture['admin_b']->id, 'subject_id' => $fixture['opname']->id],
                );

                $opname = StockOpname::withoutGlobalScopes()->findOrFail($fixture['opname']->id);
                $transit = $this->transit($fixture);
                $adjustment = (int) StockMovement::withoutGlobalScopes()->where('opname_id', $opname->id)->where('type', 'opname_adjustment')->sum('quantity');
                $movements = StockMovement::withoutGlobalScopes()->where('opname_id', $opname->id)->count();
                $successes = collect([$report['a'], $report['b']])->where('outcome', 'success')->count();

                $this->assertTrue($report['different_connections']);
                $this->assertTrue($report['true_overlap']);
                $this->assertSame(1, $successes, 'Exactly one of approve/reject may win: '.json_encode($report));
                $this->assertSame(10 + $adjustment, $transit, 'Stock must equal the ledger.');

                if ($report['a']['outcome'] === 'success') {
                    $outcomes[] = 'approve_wins';
                    $this->assertSame('approved', $opname->status);
                    $this->assertSame(-2, $adjustment);
                    $this->assertSame(8, $transit);
                    $this->assertSame(1, $movements);
                } else {
                    $outcomes[] = 'reject_wins';
                    $this->assertSame('rejected', $opname->status);
                    $this->assertSame(0, $adjustment);
                    $this->assertSame(10, $transit);
                    $this->assertSame(0, $movements);
                }

                fwrite(STDOUT, 'OPNAME_STATE_RACE '.json_encode(['iteration' => $iteration, 'winner' => end($outcomes), 'status' => $opname->status, 'transit' => $transit, 'movements' => $movements], JSON_UNESCAPED_SLASHES).PHP_EOL);
            } finally {
                $this->cleanupFixture($fixture);
            }
        }

        $this->assertCount(5, $outcomes);
    }

    private function createFixture(): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $admin = User::factory()->admin()->create(['agent_id' => $agent->id]);
        $adminB = User::factory()->admin()->create(['agent_id' => $agent->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $gudangB = User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $product = Product::create([
            'sku' => 'SR-'.Str::uuid(),
            'name' => 'State Race Cake',
            'slug' => 'sr-'.Str::uuid(),
            'has_variations' => false,
            'status' => 'active',
        ]);
        $location = WarehouseSubLocation::create(['agent_id' => $agent->id, 'code' => 'SR-'.Str::random(6), 'name' => 'Race Sub', 'created_by' => $agent->id]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 10]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $location->id, 'quantity' => 0]);
        WarehouseSetting::create(['agent_id' => $agent->id, 'factory_plan_enabled' => false]);

        $transfer = app(StockTransferService::class)->create($gudang, 'transit', null, 'sub', $location->id, [['product_id' => $product->id, 'quantity' => 4]], 'state-race');
        $opname = app(StockOpnameService::class)->create($gudang, 'physical_opname', 'transit', null, [['product_id' => $product->id]], 'state-race')->fresh()->load('items');
        app(StockOpnameService::class)->count($gudang, $opname, [['item_id' => $opname->items()->firstOrFail()->id, 'counted_quantity' => 8]]);
        app(StockOpnameService::class)->submit($gudang, $opname->fresh());

        return ['agent' => $agent, 'admin' => $admin, 'admin_b' => $adminB, 'gudang' => $gudang, 'gudang_b' => $gudangB, 'product' => $product, 'location' => $location, 'transfer' => $transfer, 'opname' => $opname];
    }

    private function transit(array $fixture): int
    {
        return (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $fixture['agent']->id)->where('product_id', $fixture['product']->id)->where('stock_type', 'transit')->whereNull('sub_location_id')->value('quantity');
    }

    private function sub(array $fixture): int
    {
        return (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $fixture['agent']->id)->where('product_id', $fixture['product']->id)->where('stock_type', 'sub')->where('sub_location_id', $fixture['location']->id)->value('quantity');
    }

    private function cleanupFixture(array $fixture): void
    {
        StockMovement::withoutGlobalScopes()->where(function ($query) use ($fixture) {
            $query->where('transfer_id', $fixture['transfer']->id)->orWhere('opname_id', $fixture['opname']->id);
        })->delete();
        ActivityLog::where('subject_type', StockTransfer::class)->where('subject_id', $fixture['transfer']->id)->delete();
        DB::table('stock_handovers')->where('stock_transfer_id', $fixture['transfer']->id)->delete();
        DB::table('stock_transfer_items')->where('stock_transfer_id', $fixture['transfer']->id)->delete();
        $fixture['transfer']->delete();
        DB::table('stock_opname_items')->where('stock_opname_id', $fixture['opname']->id)->delete();
        $fixture['opname']->delete();
        WarehouseStock::withoutGlobalScopes()->where('agent_id', $fixture['agent']->id)->delete();
        WarehouseSetting::withoutGlobalScopes()->where('agent_id', $fixture['agent']->id)->delete();
        WarehouseSubLocation::withoutGlobalScopes()->whereKey($fixture['location']->id)->delete();
        Product::whereKey($fixture['product']->id)->delete();
        User::whereIn('id', [$fixture['admin']->id, $fixture['admin_b']->id, $fixture['gudang']->id, $fixture['gudang_b']->id, $fixture['agent']->id])->delete();
    }
}
