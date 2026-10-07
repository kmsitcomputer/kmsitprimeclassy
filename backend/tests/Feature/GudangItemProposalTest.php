<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\StockRequest;
use App\Models\StockRequestProposal;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Consolidated Human UAT (B + Y): Gudang's warehouse work unit is the OrderItem, NOT the order.
 *
 * Every eligible OrderItem gets its own "Usulkan Pemenuhan" action. Proposing Item A must never
 * propose Item B. The server enforces this because:
 *   - proposal lines are resolved ONLY inside the request's own line collection
 *     (StockRequestProposalService::propose locks the Order, re-scopes the StockRequest to it,
 *     and looks every submitted line id up in THAT collection);
 *   - Gudang scope, agent match, diproses-no-courier state and quantity bounds are re-checked
 *     under the lock, so a forged item id, a stale queue state or a cross-order id is refused.
 *
 * No parallel fulfillment model exists: the canonical stock-request proposal workflow is reused,
 * it simply carries a subset.
 */
class GudangItemProposalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    /** @return array{agen:User, admin:User, gudang:User, konsumen:User} */
    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);

        return [
            'agen' => $agen,
            'admin' => User::factory()->admin()->create(['agent_id' => $agen->id]),
            'gudang' => User::factory()->gudang()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]),
            'konsumen' => User::factory()->konsumen()->create(['agent_id' => $agen->id]),
        ];
    }

    private function product(array $b, string $tag, int $price = 1000): Product
    {
        return Product::create([
            'sku' => 'GI-'.$tag.'-'.uniqid(), 'name' => 'Produk '.$tag, 'slug' => 'gi-'.strtolower($tag).'-'.uniqid(),
            'has_variations' => false, 'base_price' => $price, 'weight_grams' => 500, 'status' => 'active',
        ]);
    }

    /**
     * @param  array<int, array{tag:string, qty:int}>  $lines
     * @return array{order:Order, items:array<int, OrderItem>, request:StockRequest}
     */
    private function twoItemDiprosesOrder(array $b, array $lines): array
    {
        $order = Order::create([
            'order_no' => 'GI-'.uniqid(), 'konsumen_id' => $b['konsumen']->id,
            'agent_id' => $b['agen']->id, 'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'),
            'status' => 'diterima', 'payment_status' => 'unpaid',
            'subtotal_amount' => 99999, 'total_amount' => 99999,
            'recipient_name_snapshot' => 'Test', 'recipient_phone_snapshot' => '0811', 'address_snapshot' => 'Test',
        ]);

        WarehouseSetting::create(['agent_id' => $b['agen']->id, 'factory_plan_enabled' => false]);

        $items = [];
        foreach ($lines as $line) {
            $product = $this->product($b, $line['tag']);
            $item = OrderItem::create([
                'order_id' => $order->id, 'product_id' => $product->id, 'product_name_snapshot' => $product->name,
                'sku_snapshot' => $product->sku, 'unit_price_snapshot' => 1000, 'subtotal_snapshot' => $line['qty'] * 1000,
                'original_quantity' => $line['qty'], 'fulfilled_quantity' => 0, 'status' => 'diterima',
            ]);
            ProductStock::create(['agent_id' => $b['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => $line['qty']]);
            WarehouseStock::create(['agent_id' => $b['agen']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 20]);
            $items[] = $item;
        }

        $shipment = Shipment::create([
            'order_id' => $order->id, 'shipping_provider_code' => 'free',
            'origin_latitude' => -6.2, 'origin_longitude' => 106.8166,
            'destination_latitude' => -6.2, 'destination_longitude' => 106.8,
            'distance_km' => 1, 'shipping_fee_snapshot' => 0,
            'status' => 'pending', 'delivery_mode' => 'standard',
        ]);
        foreach ($items as $item) {
            $item->update(['shipment_id' => $shipment->id]);
        }

        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();

        $request = StockRequest::where('order_id', $order->id)->firstOrFail();

        return ['order' => $order->fresh(), 'items' => $items, 'request' => $request];
    }

    /** Stock request line id belonging to one OrderItem. */
    private function lineFor(int $requestId, int $orderItemId): int
    {
        return \App\Models\StockRequestItem::where('stock_request_id', $requestId)
            ->where('order_item_id', $orderItemId)->value('id');
    }

    private function propose(User $actor, int $requestId, array $lines)
    {
        return $this->actingAs($actor)->postJson("/api/v1/warehouse/stock-requests/{$requestId}/proposals", ['items' => $lines]);
    }

    // ---------- 1 + 2. One item proposes; its sibling is untouched ----------

    public function test_gudang_proposes_one_selected_item_without_proposing_its_sibling(): void
    {
        $b = $this->branch();
        ['order' => $order, 'items' => [$itemA, $itemB], 'request' => $request] =
            $this->twoItemDiprosesOrder($b, [['tag' => 'A', 'qty' => 4], ['tag' => 'B', 'qty' => 6]]);

        $lineA = $this->lineFor($request->id, $itemA->id);
        $this->assertNotNull($lineA);

        $response = $this->propose($b['gudang'], $request->id, [['item_id' => $lineA, 'quantity' => 4]])->assertCreated();
        $proposalId = $response->json('data.id');

        // Exactly the selected line was proposed — the sibling was never touched.
        $this->assertSame(1, \App\Models\StockRequestProposalItem::where('stock_request_proposal_id', $proposalId)->count());
        $this->assertSame(
            $lineA,
            \App\Models\StockRequestProposalItem::where('stock_request_proposal_id', $proposalId)->value('stock_request_item_id'),
        );
        $this->assertSame(
            0,
            \App\Models\StockRequestProposalItem::where('stock_request_proposal_id', $proposalId)
                ->where('stock_request_item_id', $this->lineFor($request->id, $itemB->id))->count(),
            'the sibling line must not be proposed',
        );
    }

    // ---------- 3. A forged item id from another order is rejected ----------

    public function test_cross_order_item_id_is_rejected(): void
    {
        $b = $this->branch();
        ['request' => $request] = $this->twoItemDiprosesOrder($b, [['tag' => 'A', 'qty' => 4], ['tag' => 'B', 'qty' => 6]]);

        $foreign = $this->twoItemDiprosesOrder($this->branch(), [['tag' => 'X', 'qty' => 2], ['tag' => 'Y', 'qty' => 2]]);
        $foreignLine = \App\Models\StockRequestItem::where('stock_request_id', $foreign['request']->id)->value('id');

        $this->propose($b['gudang'], $request->id, [['item_id' => $foreignLine, 'quantity' => 1]])->assertUnprocessable();

        // And a gudang from another branch cannot propose at all — route-model binding itself is
        // agent-scoped, so the foreign request is invisible (404, fail-closed, no existence leak)
        // before the service's own same-agent check would even run.
        $ownLine = \App\Models\StockRequestItem::where('stock_request_id', $request->id)->value('id');
        $this->propose($this->otherGudang($b), $request->id, [['item_id' => $ownLine, 'quantity' => 1]])
            ->assertNotFound();
    }

    private function otherGudang(array $b): User
    {
        // Cross-agent: same role, different branch — must be refused by the same-agent check.
        $stranger = User::factory()->agen()->create();
        $stranger->update(['agent_id' => $stranger->id]);

        return User::factory()->gudang()->create(['agent_id' => $stranger->id, 'parent_id' => $stranger->id]);
    }

    // ---------- 4. Invalid and repeated transitions are handled safely ----------

    public function test_invalid_and_repeated_proposal_payloads_are_refused_safely(): void
    {
        $b = $this->branch();
        ['order' => $order, 'items' => [$itemA], 'request' => $request] =
            $this->twoItemDiprosesOrder($b, [['tag' => 'A', 'qty' => 4], ['tag' => 'B', 'qty' => 6]]);

        $lineA = $this->lineFor($request->id, $itemA->id);

        // Unknown line id.
        $this->propose($b['gudang'], $request->id, [['item_id' => 999999999, 'quantity' => 1]])->assertUnprocessable();

        // Quantity above the line's remaining.
        $this->propose($b['gudang'], $request->id, [['item_id' => $lineA, 'quantity' => 999]])->assertUnprocessable();

        // Duplicate lines inside one payload.
        $this->propose($b['gudang'], $request->id, [
            ['item_id' => $lineA, 'quantity' => 1],
            ['item_id' => $lineA, 'quantity' => 1],
        ])->assertUnprocessable();

        // A valid single-item proposal then succeeds, proving the refusals changed nothing.
        $this->propose($b['gudang'], $request->id, [['item_id' => $lineA, 'quantity' => 2]])->assertCreated();
    }

    // ---------- 5. Gudang permission is enforced server-side ----------

    public function test_proposal_is_gudang_only_and_same_branch(): void
    {
        $b = $this->branch();
        ['items' => [$itemA], 'request' => $request] =
            $this->twoItemDiprosesOrder($b, [['tag' => 'A', 'qty' => 4], ['tag' => 'B', 'qty' => 6]]);

        $lineA = $this->lineFor($request->id, $itemA->id);
        $payload = [['item_id' => $lineA, 'quantity' => 1]];

        $kurir = User::factory()->kurir()->create(['agent_id' => $b['agen']->id]);
        $this->propose($kurir, $request->id, $payload)->assertForbidden('a kurir must never propose');

        // Foreign-branch gudang: the request is invisible to them (binding-scope 404, fail-closed).
        $this->propose($this->otherGudang($b), $request->id, $payload)->assertNotFound('a foreign-branch gudang must never propose');

        // A non-gudang role never reaches the controller: the role:gudang route gate refuses
        // an admin with 403 before binding or policy are even consulted.
        $this->propose($b['admin'], $request->id, $payload)->assertForbidden('an admin must never propose');
    }

    // ---------- 6. Individually fulfilled same-date items converge to the canonical Shipment ----------

    public function test_individually_fulfilled_same_date_items_converge_to_the_canonical_shipment(): void
    {
        $b = $this->branch();
        ['order' => $order, 'items' => [$itemA, $itemB], 'request' => $request] =
            $this->twoItemDiprosesOrder($b, [['tag' => 'A', 'qty' => 4], ['tag' => 'B', 'qty' => 6]]);

        // Propose and approve item A first, completely independently of item B.
        $proposalA = $this->propose($b['gudang'], $request->id, [
            ['item_id' => $this->lineFor($request->id, $itemA->id), 'quantity' => 4],
        ])->assertCreated()->json('data.id');

        $this->actingAs($b['admin'])
            ->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposalA}/approve")
            ->assertOk();

        // Then item B, independently.
        $proposalB = $this->propose($b['gudang'], $request->id, [
            ['item_id' => $this->lineFor($request->id, $itemB->id), 'quantity' => 6],
        ])->assertCreated()->json('data.id');

        $this->actingAs($b['admin'])
            ->postJson("/api/v1/warehouse/fulfillment-proposals/{$proposalB}/approve")
            ->assertOk();

        // Both lines are fulfilled, and both items sit on the single canonical shipment —
        // per-item fulfillment never scattered them.
        $this->assertSame(0, (int) \App\Models\StockRequestItem::where('stock_request_id', $request->id)->sum('remaining_qty'));
        $this->assertSame($itemA->shipment_id, $itemB->fresh()->shipment_id, 'same-date items share the canonical shipment');
        $this->assertSame(1, Shipment::where('order_id', $order->id)->count());
    }
}