<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\InventoryCancellationReversal;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Models\StockRequestProposal;
use App\Models\StockRequestProposalItem;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Services\Stock\StockRequestProposalService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ConcurrencyHarness;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

class FulfillmentCancellationConcurrencyTest extends TestCase
{
    use RestoresIsolatedTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_overlapping_proposal_approval_and_cancellation_match_a_valid_serial_ordering(): void
    {
        $reports = [];

        for ($iteration = 1; $iteration <= 5; $iteration++) {
            $fixture = $this->createFixture();
            $harness = new ConcurrencyHarness;

            try {
                $report = $harness->runFulfillmentCancellation([
                    'fulfillment_actor_id' => $fixture['approver']->id,
                    'cancellation_actor_id' => $fixture['admin']->id,
                    'proposal_id' => $fixture['proposal']->id,
                    'order_id' => $fixture['order']->id,
                    'request_id' => $fixture['request']->id,
                    'item_id' => $fixture['request_item']->id,
                    'quantity' => 6,
                ]);

                $request = StockRequest::withoutGlobalScopes()->findOrFail($fixture['request']->id);
                $item = StockRequestItem::findOrFail($fixture['request_item']->id);
                $order = Order::withoutGlobalScopes()->findOrFail($fixture['order']->id);
                $transit = $this->quantity($fixture['agent']->id, $fixture['product']->id, 'transit');
                $shipping = $this->quantity($fixture['agent']->id, $fixture['product']->id, 'shipping');
                $reserved = (int) ProductStock::withoutGlobalScopes()->where('agent_id', $fixture['agent']->id)->where('product_id', $fixture['product']->id)->value('quantity_reserved');
                $reversals = InventoryCancellationReversal::where('order_id', $fixture['order']->id)->get();
                $fulfillmentShipping = (int) StockMovement::withoutGlobalScopes()->where('reference_type', StockRequest::class)->where('reference_id', $fixture['request']->id)->where('stock_type', 'shipping')->sum('quantity');
                $fulfillmentTransit = (int) StockMovement::withoutGlobalScopes()->where('reference_type', StockRequest::class)->where('reference_id', $fixture['request']->id)->where('stock_type', 'transit')->sum('quantity');
                $reversalShipping = (int) StockMovement::withoutGlobalScopes()->where('reference_type', Order::class)->where('reference_id', $fixture['order']->id)->where('stock_type', 'shipping')->sum('quantity');
                $reversalTransit = (int) StockMovement::withoutGlobalScopes()->where('reference_type', Order::class)->where('reference_id', $fixture['order']->id)->where('stock_type', 'transit')->sum('quantity');
                $proposal = StockRequestProposal::withoutGlobalScopes()->findOrFail($fixture['proposal']->id);
                $operations = DB::table('stock_request_fulfillments')->where('stock_request_id', $fixture['request']->id)->count();
                $movementCount = StockMovement::withoutGlobalScopes()->where(function ($query) use ($fixture) {
                    $query->where('reference_type', StockRequest::class)->where('reference_id', $fixture['request']->id)
                        ->orWhere(function ($nested) use ($fixture) {
                            $nested->where('reference_type', Order::class)->where('reference_id', $fixture['order']->id);
                        });
                })->count();
                $fulfillmentSucceeded = $report['fulfillment']['outcome'] === 'success';
                $cancellationSucceeded = $report['cancellation']['outcome'] === 'success';

                $this->assertTrue($report['different_connections']);
                $this->assertTrue($report['true_overlap']);
                $this->assertTrue($cancellationSucceeded, json_encode($report['cancellation'], JSON_UNESCAPED_SLASHES));
                $this->assertSame('dibatalkan', $order->status);
                $this->assertSame('cancelled', $request->status);
                $this->assertSame(1, $reversals->count());
                $this->assertSame(0, $reserved);
                $this->assertGreaterThanOrEqual(0, $transit);
                $this->assertGreaterThanOrEqual(0, $shipping);

                // Overrun guards that hold for either serial ordering.
                $this->assertLessThanOrEqual((int) $item->requested_qty, (int) $item->fulfilled_qty);
                $this->assertGreaterThanOrEqual(0, (int) $item->remaining_qty);
                $this->assertLessThanOrEqual(1, $operations);

                if ($fulfillmentSucceeded) {
                    $ordering = 'approval_then_cancellation';
                    $this->assertSame('approved', $report['fulfillment']['proposal_status']);
                    $this->assertSame('approved', $proposal->status);
                    $this->assertSame(1, $operations);
                    // one Transit/Shipping pair from the approval + one pair from the cancellation reversal
                    $this->assertSame(4, $movementCount);
                    $this->assertSame(6, (int) $item->fulfilled_qty);
                    $this->assertSame(4, (int) $item->remaining_qty);
                    $this->assertSame(6, $fulfillmentShipping);
                    $this->assertSame(-6, $fulfillmentTransit);
                    $this->assertSame(-6, $reversalShipping);
                    $this->assertSame(6, $reversalTransit);
                    $this->assertSame(4, (int) $reversals->first()->released_quantity);
                } else {
                    $ordering = 'cancellation_then_approval';
                    // The late approval is refused (request already cancelled) and leaves the
                    // proposal pending: cancellation never touches proposals, and no stock moves.
                    $this->assertSame(\App\Exceptions\ApiException::class, $report['fulfillment']['exception'] ?? null);
                    $this->assertSame('pending', $proposal->status);
                    $this->assertSame(0, $operations);
                    $this->assertSame(0, $movementCount);
                    $this->assertSame(0, (int) $item->fulfilled_qty);
                    $this->assertSame(10, (int) $item->remaining_qty);
                    $this->assertSame(0, $fulfillmentShipping);
                    $this->assertSame(0, $fulfillmentTransit);
                    $this->assertSame(0, $reversalShipping);
                    $this->assertSame(0, $reversalTransit);
                    $this->assertSame(10, (int) $reversals->first()->released_quantity);
                }

                $this->assertSame(6, $transit);
                $this->assertSame(0, $shipping);
                $this->assertSame($fulfillmentSucceeded ? 6 : 0, (int) $reversals->first()->fulfilled_quantity);
                $this->assertSame($fulfillmentSucceeded ? 6 : 0, (int) $item->fulfilled_qty);
                $this->assertSame(1, InventoryCancellationReversal::where('order_item_id', $fixture['order_item']->id)->count());

                $reports[] = [
                    'iteration' => $iteration,
                    'fulfillment_connection' => $report['fulfillment']['connection_id'],
                    'cancellation_connection' => $report['cancellation']['connection_id'],
                    'fulfillment_outcome' => $report['fulfillment']['outcome'],
                    'cancellation_outcome' => $report['cancellation']['outcome'],
                    'winning_serial_ordering' => $ordering,
                    'proposal_status' => $proposal->status,
                    'fulfillment_records' => $operations,
                    'movement_count' => $movementCount,
                    'final_order_status' => $order->status,
                    'final_request_status' => $request->status,
                    'final_fulfilled' => $item->fulfilled_qty,
                    'final_remaining' => $item->remaining_qty,
                    'final_transit' => $transit,
                    'final_shipping' => $shipping,
                    'final_reserved' => $reserved,
                    'fulfillment_movement' => $fulfillmentShipping,
                    'reversal_movement' => $reversalShipping,
                    'double_release' => false,
                    'double_restock' => false,
                    'duplicate_movement' => false,
                    'impossible_state' => false,
                ];
                fwrite(STDOUT, 'FULFILLMENT_CANCELLATION_RACE '.json_encode(end($reports), JSON_UNESCAPED_SLASHES).PHP_EOL);
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
        $approver = User::factory()->admin()->create(['agent_id' => $agent->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        AgentProfile::create(['user_id' => $agent->id, 'store_name' => 'Fulfillment Cancellation Branch', 'address' => 'Test', 'latitude' => -6.2, 'longitude' => 106.8]);
        $product = Product::create([
            'sku' => 'FC-'.Str::uuid(),
            'name' => 'Fulfillment Cancellation Cake',
            'slug' => 'fc-'.Str::uuid(),
            'has_variations' => false,
            'base_price' => 1000,
            'weight_grams' => 100,
            'status' => 'active',
        ]);
        $order = Order::create([
            'order_no' => 'FC-'.Str::random(12),
            'konsumen_id' => $konsumen->id,
            'agent_id' => $agent->id,
            'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'),
            'status' => 'diproses',
            'payment_status' => 'unpaid',
            'subtotal_amount' => 10000,
            'total_amount' => 10000,
            'recipient_name_snapshot' => 'Test',
            'recipient_phone_snapshot' => '0811',
            'address_snapshot' => 'Test',
        ]);
        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->name,
            'sku_snapshot' => $product->sku,
            'unit_price_snapshot' => 1000,
            'subtotal_snapshot' => 10000,
            'original_quantity' => 10,
            'fulfilled_quantity' => 0,
            'status' => 'diproses',
        ]);
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 10]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 6]);
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'shipping', 'quantity' => 0]);
        WarehouseSetting::create(['agent_id' => $agent->id, 'factory_plan_enabled' => false]);
        $request = StockRequest::create(['agent_id' => $agent->id, 'order_id' => $order->id, 'request_number' => 'SR-'.Str::uuid(), 'status' => 'pending', 'created_by' => $agent->id]);
        $requestItem = StockRequestItem::create(['stock_request_id' => $request->id, 'order_item_id' => $orderItem->id, 'product_id' => $product->id, 'sku_snapshot' => $product->sku, 'requested_qty' => 10, 'fulfilled_qty' => 0, 'remaining_qty' => 10]);
        $proposal = app(StockRequestProposalService::class)->propose($gudang, $request, [['item_id' => $requestItem->id, 'quantity' => 6]]);

        return [
            'agent' => $agent,
            'admin' => $admin,
            'approver' => $approver,
            'proposal' => $proposal,
            'gudang' => $gudang,
            'konsumen' => $konsumen,
            'product' => $product,
            'order' => $order,
            'order_item' => $orderItem,
            'request' => $request,
            'request_item' => $requestItem,
        ];
    }

    private function quantity(int $agentId, int $productId, string $stockType): int
    {
        return (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('product_id', $productId)->where('stock_type', $stockType)->value('quantity');
    }

    private function cleanupFixture(array $fixture): void
    {
        StockMovement::withoutGlobalScopes()->where(function ($query) use ($fixture) {
            $query->where('reference_type', StockRequest::class)->where('reference_id', $fixture['request']->id)
                ->orWhere(function ($nested) use ($fixture) {
                    $nested->where('reference_type', Order::class)->where('reference_id', $fixture['order']->id);
                });
        })->delete();
        InventoryCancellationReversal::where('order_id', $fixture['order']->id)->delete();
        StockRequestProposalItem::query()->where('stock_request_proposal_id', $fixture['proposal']->id)->delete();
        StockRequestProposal::withoutGlobalScopes()->whereKey($fixture['proposal']->id)->delete();
        DB::table('stock_request_fulfillments')->where('stock_request_id', $fixture['request']->id)->delete();
        $fixture['request_item']->delete();
        $fixture['request']->delete();
        $fixture['order_item']->delete();
        $fixture['order']->delete();
        WarehouseStock::withoutGlobalScopes()->where('agent_id', $fixture['agent']->id)->delete();
        ProductStock::withoutGlobalScopes()->where('agent_id', $fixture['agent']->id)->delete();
        WarehouseSetting::withoutGlobalScopes()->where('agent_id', $fixture['agent']->id)->delete();
        AgentProfile::where('user_id', $fixture['agent']->id)->delete();
        Product::whereKey($fixture['product']->id)->delete();
        User::whereIn('id', [$fixture['admin']->id, $fixture['approver']->id, $fixture['gudang']->id, $fixture['konsumen']->id, $fixture['agent']->id])->delete();
    }
}
