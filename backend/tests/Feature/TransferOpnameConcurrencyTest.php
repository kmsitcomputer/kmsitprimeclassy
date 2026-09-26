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

class TransferOpnameConcurrencyTest extends TestCase
{
    use RestoresIsolatedTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_overlapping_transfer_and_opname_preserve_transfer_and_stale_snapshot_contract(): void
    {
        $reports = [];

        for ($iteration = 1; $iteration <= 5; $iteration++) {
            $fixture = $this->createFixture();
            $harness = new ConcurrencyHarness;

            try {
                $report = $harness->runTransferOpname([
                    'transfer_actor_id' => $fixture['admin']->id,
                    'opname_actor_id' => $fixture['admin']->id,
                    'transfer_id' => $fixture['transfer']->id,
                    'opname_id' => $fixture['opname']->id,
                ]);

                $source = $this->quantity($fixture['agent']->id, $fixture['product']->id, 'transit');
                $destination = (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $fixture['agent']->id)->where('product_id', $fixture['product']->id)->where('stock_type', 'sub')->where('sub_location_id', $fixture['sub']->id)->value('quantity');
                $transfer = StockTransfer::withoutGlobalScopes()->findOrFail($fixture['transfer']->id);
                $opname = StockOpname::withoutGlobalScopes()->findOrFail($fixture['opname']->id);
                $transferOut = (int) StockMovement::withoutGlobalScopes()->where('transfer_id', $transfer->id)->where('type', 'transfer_out')->sum('quantity');
                $transferIn = (int) StockMovement::withoutGlobalScopes()->where('transfer_id', $transfer->id)->where('type', 'transfer_in')->sum('quantity');
                $opnameAdjustment = (int) StockMovement::withoutGlobalScopes()->where('opname_id', $opname->id)->where('type', 'opname_adjustment')->sum('quantity');
                $transferSucceeded = $report['transfer']['outcome'] === 'success';
                $opnameSucceeded = $report['opname']['outcome'] === 'success';

                $this->assertTrue($report['different_connections']);
                $this->assertTrue($report['true_overlap']);
                $this->assertTrue($transferSucceeded);
                $this->assertSame('completed', $transfer->status);
                $this->assertSame(-4, $transferOut);
                $this->assertSame(4, $transferIn);
                $this->assertSame(10 + $opnameAdjustment, $source + $destination);
                $this->assertGreaterThanOrEqual(0, $source);
                $this->assertGreaterThanOrEqual(0, $destination);
                $this->assertSame(2, StockMovement::withoutGlobalScopes()->where('transfer_id', $transfer->id)->count());

                if ($opnameSucceeded) {
                    $ordering = 'opname_then_transfer';
                    $this->assertSame('approved', $opname->status);
                    $this->assertSame(-2, $opnameAdjustment);
                    $this->assertSame(4, $source);
                    $this->assertSame(4, $destination);
                    $this->assertSame(1, StockMovement::withoutGlobalScopes()->where('opname_id', $opname->id)->count());
                } else {
                    $ordering = 'transfer_then_stale_opname_rejected';
                    $this->assertSame('submitted', $opname->status);
                    $this->assertSame(0, $opnameAdjustment);
                    $this->assertSame(6, $source);
                    $this->assertSame(4, $destination);
                    $this->assertSame(0, StockMovement::withoutGlobalScopes()->where('opname_id', $opname->id)->count());
                }

                $this->assertSame(10, $source + $destination + abs($opnameAdjustment));
                $this->assertFalse($source === 8 && $destination === 4, 'Stale opname overwrite erased the transfer.');

                $reports[] = [
                    'iteration' => $iteration,
                    'transfer_connection' => $report['transfer']['connection_id'],
                    'opname_connection' => $report['opname']['connection_id'],
                    'transfer_outcome' => $report['transfer']['outcome'],
                    'opname_outcome' => $report['opname']['outcome'],
                    'winning_serial_ordering' => $ordering,
                    'initial_source' => 10,
                    'initial_destination' => 0,
                    'transfer_quantity' => 4,
                    'opname_snapshot' => 10,
                    'opname_counted' => 8,
                    'final_source' => $source,
                    'final_destination' => $destination,
                    'opname_status' => $opname->status,
                    'transfer_movement' => $transferIn,
                    'opname_adjustment' => $opnameAdjustment,
                    'lost_update' => false,
                    'negative_stock' => false,
                    'duplicate_movement' => false,
                    'ledger_reconciles' => true,
                    'impossible_state' => false,
                ];
                fwrite(STDOUT, 'TRANSFER_OPNAME_RACE '.json_encode(end($reports), JSON_UNESCAPED_SLASHES).PHP_EOL);
            } finally {
                $this->cleanupFixture($fixture);
            }
        }

        $this->assertCount(5, $reports);
    }

    private function createFixture(): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $admin = User::factory()->admin()->create(['agent_id' => $agent->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $product = Product::create([
            'sku' => 'TO-'.Str::uuid(),
            'name' => 'Transfer Opname Cake',
            'slug' => 'to-'.Str::uuid(),
            'has_variations' => false,
            'status' => 'active',
        ]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 10]);
        $sub = WarehouseSubLocation::create(['agent_id' => $agent->id, 'code' => 'TO-'.Str::random(6), 'name' => 'Race Sub', 'created_by' => $agent->id]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $sub->id, 'quantity' => 0]);
        WarehouseSetting::create(['agent_id' => $agent->id, 'factory_plan_enabled' => false]);

        $transfer = app(StockTransferService::class)->create(
            $gudang,
            'transit',
            null,
            'sub',
            $sub->id,
            [['product_id' => $product->id, 'quantity' => 4]],
            'transfer-opname-race',
        );
        $opname = app(StockOpnameService::class)->create(
            $gudang,
            'physical_opname',
            'transit',
            null,
            [['product_id' => $product->id]],
            'transfer-opname-race',
        );
        $opname = $opname->fresh()->load('items');
        $opnameItem = $opname->items()->firstOrFail();
        app(StockOpnameService::class)->count($gudang, $opname, [['item_id' => $opnameItem->id, 'counted_quantity' => 8]]);
        app(StockOpnameService::class)->submit($gudang, $opname->fresh());

        return compact('agent', 'admin', 'gudang', 'product', 'transfer', 'opname', 'sub');
    }

    private function quantity(int $agentId, int $productId, string $stockType): int
    {
        return (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('product_id', $productId)->where('stock_type', $stockType)->value('quantity');
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
        WarehouseSubLocation::withoutGlobalScopes()->whereKey($fixture['sub']->id)->delete();
        Product::whereKey($fixture['product']->id)->delete();
        User::whereIn('id', [$fixture['admin']->id, $fixture['gudang']->id, $fixture['agent']->id])->delete();
    }
}
