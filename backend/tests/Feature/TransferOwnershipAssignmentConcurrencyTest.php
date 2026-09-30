<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockHandover;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use App\Services\Stock\StockTransferService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Tests\Support\ConcurrencyHarness;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

/**
 * Codex Round-2 MAJOR-2 — real two-MySQL-connection race between Admin approving a pending
 * generic transfer and Agen assigning an owner to the same Sub Location. Both must serialize on
 * the WarehouseSubLocation row lock (StockTransferService::assertNoOwnedSubLocation's locked
 * branch vs. SubLocationOwnershipService::assignOwner), so exactly one of two outcomes is legal:
 * approval wins (transfer completes, assignment then applies to an already-moved location), or
 * assignment wins (transfer is rejected, no stock moves into the now-owned location).
 */
class TransferOwnershipAssignmentConcurrencyTest extends TestCase
{
    use RestoresIsolatedTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_transfer_approval_races_owner_assignment_on_the_same_location(): void
    {
        for ($iteration = 1; $iteration <= 3; $iteration++) {
            $agent = User::factory()->agen()->create();
            $agent->update(['agent_id' => $agent->id]);
            $admin = User::factory()->admin()->create(['agent_id' => $agent->id]);
            $gudang = User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
            $sub = User::factory()->salesKurirSub()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
            $location = WarehouseSubLocation::create(['agent_id' => $agent->id, 'code' => 'RACE-'.$iteration, 'name' => 'Race', 'created_by' => $agent->id]);
            $product = Product::create(['sku' => 'OWN-RACE-'.$iteration.'-'.uniqid(), 'name' => 'Owner Race', 'slug' => 'own-race-'.$iteration.'-'.uniqid(), 'has_variations' => false, 'status' => 'active']);
            WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 50]);
            ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
            $transfer = app(StockTransferService::class)->create($gudang, 'transit', null, 'sub', $location->id, [['product_id' => $product->id, 'quantity' => 10]]);

            $report = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'transfer-approve', 'actor_id' => $admin->id, 'subject_id' => $transfer->id],
                ['op' => 'sub-location-assign-owner', 'actor_id' => $agent->id, 'extra' => ['location_id' => $location->id, 'owner_user_id' => $sub->id]],
            );

            $this->assertTrue($report['different_connections']);
            $this->assertTrue($report['true_overlap']);

            $transferStatus = StockTransfer::withoutGlobalScopes()->findOrFail($transfer->id)->status;
            $ownerId = WarehouseSubLocation::withoutGlobalScopes()->findOrFail($location->id)->owner_user_id;
            $transit = (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $agent->id)->where('product_id', $product->id)->where('stock_type', 'transit')->value('quantity');
            $subQty = (int) (WarehouseStock::withoutGlobalScopes()->where('sub_location_id', $location->id)->value('quantity') ?? 0);

            if ($transferStatus === 'completed') {
                // Approval won: stock moved while the location was still unowned.
                $this->assertSame(40, $transit);
                $this->assertSame(10, $subQty);
            } else {
                // Assignment won: approval must have been rejected before touching stock.
                $this->assertSame('pending', $transferStatus);
                $this->assertSame(50, $transit);
                $this->assertSame(0, $subQty);
                $this->assertSame(0, StockMovement::query()->where('transfer_id', $transfer->id)->count());
                $this->assertSame(0, StockHandover::query()->where('stock_transfer_id', $transfer->id)->count());
            }
            // Assignment itself always succeeds — it never depends on transfer state.
            $this->assertSame($sub->id, $ownerId);
            fwrite(STDOUT, 'TRANSFER_OWNERSHIP_RACE '.json_encode(['iteration' => $iteration, 'a' => $report['a']['outcome'], 'b' => $report['b']['outcome'], 'transfer_status' => $transferStatus]).PHP_EOL);
        }
    }
}
