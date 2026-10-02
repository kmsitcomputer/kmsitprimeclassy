<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\StockRequest;
use App\Models\StockRequestFulfillment;
use App\Models\StockRequestItem;
use App\Models\StockRequestProposal;
use App\Models\StockRequestProposalItem;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Services\Order\OrderLineAdditionService;
use App\Services\Stock\StockRequestProposalService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\Support\ConcurrencyHarness;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

/**
 * F07 — a concurrent idempotent approval replay must return the CURRENT committed decision graph (never a
 * stale REPEATABLE READ snapshot), while remaining exactly-once on every stock/audit side effect.
 *
 * Deterministic: this process performs the REAL approval on its own connection and leaves it UNCOMMITTED
 * (holding the Order lock), the real replay actor starts and is forced to block, then the approval commits
 * and the replay continues. Real separate PHP processes / MySQL connections (ConcurrencyHarness).
 */
class PackageCProposalReplayConcurrencyTest extends TestCase
{
    use HasTestRegion;
    use RestoresIsolatedTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    /** @return array{agen:User, admin:User, gudang:User, konsumen:User, product:Product, order:Order, request:StockRequest} */
    private function fixture(int $quantity = 10, int $stock = 50): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Replay', 'address' => 'x', 'latitude' => -6.2, 'longitude' => 106.8166]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);

        $product = $this->makeProduct($agen, 'Replay');
        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);
        $response->assertCreated();
        $order = Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
        $request = StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->firstOrFail();

        return compact('agen', 'admin', 'gudang', 'konsumen', 'product', 'order', 'request');
    }

    private function makeProduct(User $agen, string $name): Product
    {
        $product = Product::create(['sku' => 'RPL-'.Str::uuid(), 'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(), 'has_variations' => false, 'base_price' => 10000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 100, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 50]);
        WarehouseSetting::firstOrCreate(['agent_id' => $agen->id], ['factory_plan_enabled' => false]);

        return $product;
    }

    /**
     * Run the REAL approval on this connection (uncommitted, holding the Order lock), then start the REAL
     * replay actor, wait until it is provably blocked, commit the approval and let the replay finish.
     *
     * @param  callable():void  $first  performs the first approval on the caller's connection (inside the tx)
     * @return array{actor: array, blocked: bool}
     */
    private function replayAgainstUncommittedApproval(callable $first, array $side): array
    {
        DB::beginTransaction();
        try {
            $first();

            return (new ConcurrencyHarness)->runServiceActorAgainstHeldLocks($side, fn () => DB::commit());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    public function test_replay_after_concurrent_approval_returns_current_committed_state(): void
    {
        $f = $this->fixture(quantity: 10, stock: 50);
        $requestItem = StockRequestItem::where('stock_request_id', $f['request']->id)->firstOrFail();
        $proposal = app(StockRequestProposalService::class)->propose($f['gudang'], $f['request'], [['item_id' => $requestItem->id, 'quantity' => 6]]);
        $proposalItem = $proposal->items->firstOrFail();

        $result = $this->replayAgainstUncommittedApproval(
            fn () => app(StockRequestProposalService::class)->approveItem($f['admin'], $proposal, $proposalItem),
            ['op' => 'proposal-item-approve', 'actor_id' => $f['admin']->id, 'subject_id' => $proposal->id, 'extra' => ['item_id' => $proposalItem->id]],
        );

        $this->assertTrue($result['blocked'], 'the replay actor must provably block on the held Order lock');
        $actor = $result['actor'];
        $this->assertSame('success', $actor['outcome'] ?? null, json_encode($actor));

        // B's response must agree with the committed DB graph — not the pre-wait snapshot.
        $dbProposal = StockRequestProposal::withoutGlobalScopes()->findOrFail($proposal->id);
        $dbItem = StockRequestProposalItem::findOrFail($proposalItem->id);
        $this->assertSame('approved', $dbProposal->status);
        $this->assertSame('approved', $dbItem->decision_status);
        $this->assertSame($dbProposal->status, $actor['proposal_status'] ?? null, 'replay response status must equal the DB status');
        $this->assertSame($dbItem->decision_status, $actor['item_decisions'][(string) $proposalItem->id] ?? null, 'replay response item must equal the DB item');

        // Exactly-once: the replay never executed a second fulfillment.
        $requestItem->refresh();
        $this->assertSame([10, 6, 4], [$requestItem->requested_qty, $requestItem->fulfilled_qty, $requestItem->remaining_qty]);
        $this->assertSame('partial', $f['request']->fresh()->status);
        $this->assertSame(1, StockRequestFulfillment::withoutGlobalScopes()->where('stock_request_id', $f['request']->id)->count());
        $this->assertSame(2, StockMovement::withoutGlobalScopes()->where('reference_type', StockRequest::class)->where('reference_id', $f['request']->id)->count());
        $this->assertSame(44, (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $f['agen']->id)->where('product_id', $f['product']->id)->where('stock_type', 'transit')->sum('quantity'));
        $this->assertSame(6, (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $f['agen']->id)->where('product_id', $f['product']->id)->where('stock_type', 'shipping')->sum('quantity'));
        $this->assertSame(4, (int) ProductStock::withoutGlobalScopes()->where('agent_id', $f['agen']->id)->where('product_id', $f['product']->id)->value('quantity_reserved'));
    }

    public function test_replay_projects_only_its_own_item_and_never_another_lines_state(): void
    {
        $f = $this->fixture(quantity: 10, stock: 50);
        $second = $this->makeProduct($f['agen'], 'Replay2');

        // Second order line via the real SC-03 path so the request has two items.
        app(OrderLineAdditionService::class)->addLine(
            Order::withoutGlobalScopes()->findOrFail($f['order']->id),
            ['product_id' => $second->id, 'quantity' => 2],
            $f['admin'], null, 'setup', 'cod', (string) Str::uuid(),
        );

        $request = $f['request']->fresh();
        $itemA = $request->items()->where('product_id', $f['product']->id)->firstOrFail();
        $itemB = $request->items()->where('product_id', $second->id)->firstOrFail();
        $proposal = app(StockRequestProposalService::class)->propose($f['gudang'], $request, [
            ['item_id' => $itemA->id, 'quantity' => 6],
            ['item_id' => $itemB->id, 'quantity' => 2],
        ]);
        $pa = $proposal->items->firstWhere('stock_request_item_id', $itemA->id);
        $pb = $proposal->items->firstWhere('stock_request_item_id', $itemB->id);

        $result = $this->replayAgainstUncommittedApproval(
            fn () => app(StockRequestProposalService::class)->approveItem($f['admin'], $proposal, $pa),
            ['op' => 'proposal-item-approve', 'actor_id' => $f['admin']->id, 'subject_id' => $proposal->id, 'extra' => ['item_id' => $pa->id]],
        );

        $this->assertTrue($result['blocked']);
        $actor = $result['actor'];
        $this->assertSame('success', $actor['outcome'] ?? null, json_encode($actor));

        $this->assertSame('approved', $actor['item_decisions'][(string) $pa->id] ?? null);
        $this->assertSame('pending', $actor['item_decisions'][(string) $pb->id] ?? null, 'another line must not be projected as decided');
        $this->assertSame('approved', StockRequestProposalItem::findOrFail($pa->id)->decision_status);
        $this->assertSame('pending', StockRequestProposalItem::findOrFail($pb->id)->decision_status);
        $this->assertSame('partial', StockRequestProposal::withoutGlobalScopes()->findOrFail($proposal->id)->status);
    }

    public function test_replay_returns_current_related_order_item_projection(): void
    {
        $f = $this->fixture(quantity: 10, stock: 50);
        $requestItem = StockRequestItem::where('stock_request_id', $f['request']->id)->firstOrFail();
        $proposal = app(StockRequestProposalService::class)->propose($f['gudang'], $f['request'], [['item_id' => $requestItem->id, 'quantity' => 6]]);
        $proposalItem = $proposal->items->firstOrFail();

        // Commit the approval so the replay takes the idempotent early-return path.
        app(StockRequestProposalService::class)->approveItem($f['admin'], $proposal, $proposalItem);

        $newDate = now()->addDays(20)->toDateString();
        $result = $this->replayAgainstUncommittedApproval(
            function () use ($f, $newDate) {
                // A: hold the ORDER lock and change the related OrderItem (quantity + date) — uncommitted.
                Order::withoutGlobalScopes()->whereKey($f['order']->id)->lockForUpdate()->first();
                $item = OrderItem::query()->where('order_id', $f['order']->id)->lockForUpdate()->firstOrFail();
                $item->update(['fulfilled_quantity' => 12, 'requested_delivery_date' => $newDate]);
            },
            ['op' => 'proposal-item-approve', 'actor_id' => $f['admin']->id, 'subject_id' => $proposal->id, 'extra' => ['item_id' => $proposalItem->id]],
        );

        $this->assertTrue($result['blocked'], 'the replay actor must provably block on the held Order lock');
        $actor = $result['actor'];
        $this->assertSame('success', $actor['outcome'] ?? null, json_encode($actor));

        // The projected related OrderItem fields must equal the committed DB, not the pre-wait snapshot.
        $item = OrderItem::query()->where('order_id', $f['order']->id)->firstOrFail();
        $projection = $actor['request_item_projection'][(string) $proposalItem->id] ?? null;
        $this->assertNotNull($projection, 'the response must project the related order item');
        $this->assertSame((int) $item->fulfilled_quantity, (int) ($projection['order_quantity'] ?? -1), 'order_quantity must be current');
        $this->assertSame($item->requested_delivery_date?->toDateString(), $projection['delivery_date'] ?? null, 'delivery_date must be current');
        $this->assertSame(12, (int) $projection['order_quantity']);
        $this->assertSame($newDate, $projection['delivery_date']);

        // Exactly-once: the replay never executed a second fulfillment.
        $this->assertSame(1, StockRequestFulfillment::withoutGlobalScopes()->where('stock_request_id', $f['request']->id)->count());
        $this->assertSame(2, StockMovement::withoutGlobalScopes()->where('reference_type', StockRequest::class)->where('reference_id', $f['request']->id)->count());
    }
}
