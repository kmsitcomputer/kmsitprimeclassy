<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariation;
use App\Models\ProductVariationStock;
use App\Models\SubStockRequest;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use App\Services\Stock\SubStockRequestService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;
use Tests\Support\ConcurrencyHarness;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

/**
 * Codex Round-2 MAJOR-1 — an Agent checkout reservation and an approved Sub replenishment
 * (Transit -> Sub) execution both compete for the same Transit capacity. Both must lock the
 * Agent commitment row (ProductStock/ProductVariationStock) before the Warehouse Transit/Plan
 * row, in that order, or one can starve the other's already-committed capacity (see
 * StockTransferService::lockAgentCapacityForTransitToSub and
 * StockService::availableProduct/availableVariation).
 */
class AgentSubCapacityConcurrencyTest extends TestCase
{
    use RestoresIsolatedTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function fixture(bool $variation, int $transit, int $requestQty): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $admin = User::factory()->admin()->create(['agent_id' => $agent->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $sub = User::factory()->salesKurirSub()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $location = WarehouseSubLocation::create(['agent_id' => $agent->id, 'code' => 'C-'.Str::random(6), 'name' => 'Capacity', 'created_by' => $agent->id]);
        $location->forceFill(['owner_user_id' => $sub->id])->save();

        $product = Product::create(['sku' => $variation ? null : 'CAP-'.Str::uuid(), 'name' => 'Capacity Race', 'slug' => 'cap-race-'.Str::uuid(), 'has_variations' => $variation, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
        $variationModel = null;
        if ($variation) {
            $variationModel = ProductVariation::create(['product_id' => $product->id, 'sku' => 'CAP-V-'.Str::uuid(), 'price' => 1000, 'weight_grams' => 100, 'is_active' => true, 'sort_order' => 0]);
            ProductVariationStock::create(['agent_id' => $agent->id, 'product_variation_id' => $variationModel->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
            WarehouseStock::create(['agent_id' => $agent->id, 'product_variation_id' => $variationModel->id, 'stock_type' => 'transit', 'quantity' => $transit]);
        } else {
            ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
            WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => $transit]);
        }

        $requestService = app(SubStockRequestService::class);
        $items = $variation ? [['product_variation_id' => $variationModel->id, 'quantity' => $requestQty]] : [['product_id' => $product->id, 'quantity' => $requestQty]];
        $request = $requestService->create($sub, 'replenish', $items);
        $requestService->approve($admin, $request);

        return compact('agent', 'admin', 'gudang', 'sub', 'location', 'product', 'variationModel', 'request');
    }

    private function assertExactlyOneWinnerAndNoDeficit(array $f, bool $variation, int $transit, int $qty): void
    {
        $reserved = $variation
            ? (int) ProductVariationStock::withoutGlobalScopes()->where('agent_id', $f['agent']->id)->where('product_variation_id', $f['variationModel']->id)->value('quantity_reserved')
            : (int) ProductStock::withoutGlobalScopes()->where('agent_id', $f['agent']->id)->where('product_id', $f['product']->id)->value('quantity_reserved');
        $remainingTransit = $variation
            ? (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $f['agent']->id)->where('product_variation_id', $f['variationModel']->id)->where('stock_type', 'transit')->value('quantity')
            : (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $f['agent']->id)->where('product_id', $f['product']->id)->where('stock_type', 'transit')->value('quantity');
        $requestStatus = SubStockRequest::withoutGlobalScopes()->find($f['request']->id)->status;

        // exactly one side actually moved something: either the reservation was taken, or the
        // request executed (Transit decreased) — never both, since combined demand (2x $qty)
        // exceeds $transit.
        $reservationWon = $reserved > 0;
        $executionWon = $requestStatus === 'executed';
        $this->assertNotSame($reservationWon, $executionWon, 'exactly one side may win: reserved='.$reserved.' status='.$requestStatus);

        // no reservation deficit left standing regardless of who won.
        $this->assertGreaterThanOrEqual($reserved, $remainingTransit, 'Transit must never fall below the committed Agent reservation');
    }

    public function test_agent_checkout_reservation_races_sub_replenishment_execution_for_a_simple_product(): void
    {
        for ($iteration = 1; $iteration <= 3; $iteration++) {
            $f = $this->fixture(variation: false, transit: 10, requestQty: 8);
            $report = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'agent-reserve', 'actor_id' => $f['agent']->id, 'extra' => ['agent_id' => $f['agent']->id, 'product_id' => $f['product']->id, 'quantity' => 8]],
                ['op' => 'sub-request-execute', 'actor_id' => $f['gudang']->id, 'subject_id' => $f['request']->id],
            );

            $this->assertTrue($report['different_connections']);
            $this->assertTrue($report['true_overlap']);
            $this->assertExactlyOneWinnerAndNoDeficit($f, false, 10, 8);
            fwrite(STDOUT, 'AGENT_SUB_CAPACITY_RACE_PRODUCT '.json_encode(['iteration' => $iteration, 'a' => $report['a']['outcome'], 'b' => $report['b']['outcome']]).PHP_EOL);
        }
    }

    public function test_agent_checkout_reservation_races_sub_replenishment_execution_for_a_variation(): void
    {
        for ($iteration = 1; $iteration <= 3; $iteration++) {
            $f = $this->fixture(variation: true, transit: 10, requestQty: 8);
            $report = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'agent-reserve', 'actor_id' => $f['agent']->id, 'extra' => ['agent_id' => $f['agent']->id, 'variation_id' => $f['variationModel']->id, 'quantity' => 8]],
                ['op' => 'sub-request-execute', 'actor_id' => $f['gudang']->id, 'subject_id' => $f['request']->id],
            );

            $this->assertTrue($report['different_connections']);
            $this->assertTrue($report['true_overlap']);
            $this->assertExactlyOneWinnerAndNoDeficit($f, true, 10, 8);
            fwrite(STDOUT, 'AGENT_SUB_CAPACITY_RACE_VARIATION '.json_encode(['iteration' => $iteration, 'a' => $report['a']['outcome'], 'b' => $report['b']['outcome']]).PHP_EOL);
        }
    }
}
