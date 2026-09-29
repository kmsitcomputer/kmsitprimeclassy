<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockHandover;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\SubStockRequest;
use App\Models\SubStockReservation;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * R-02 — Transit -> Sub replenishment and Sub -> Transit return:
 * Sales-Kurir-Sub requests, Admin approves, Gudang executes; stock moves once, movement + handover backed.
 */
class SubStockRequestFlowTest extends TestCase
{
    use RefreshDatabase;

    private array $b;

    private array $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        $this->b = $this->branch();
        $this->other = $this->branch();
    }

    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $sub = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $location = WarehouseSubLocation::create(['agent_id' => $agen->id, 'code' => 'L-'.Str::random(5), 'name' => 'Sub', 'created_by' => $agen->id]);
        $location->forceFill(['owner_user_id' => $sub->id])->save();
        $product = Product::create(['sku' => 'FLOW-'.Str::uuid(), 'name' => 'Flow Cake', 'slug' => 'flow-'.Str::uuid(), 'has_variations' => false, 'status' => 'active']);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 20]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);

        return compact('agen', 'admin', 'gudang', 'sub', 'location', 'product');
    }

    private function transit(?array $b = null): int
    {
        $b ??= $this->b;

        return (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $b['agen']->id)->where('product_id', $b['product']->id)->where('stock_type', 'transit')->value('quantity');
    }

    private function sub(?array $b = null): int
    {
        $b ??= $this->b;

        return (int) WarehouseStock::withoutGlobalScopes()->where('stock_type', 'sub')->where('sub_location_id', $b['location']->id)->where('product_id', $b['product']->id)->value('quantity');
    }

    private function setSub(int $qty): void
    {
        WarehouseStock::updateOrCreate(['agent_id' => $this->b['agen']->id, 'product_id' => $this->b['product']->id, 'stock_type' => 'sub', 'sub_location_id' => $this->b['location']->id], ['quantity' => $qty]);
    }

    private function request(string $direction, int $qty, ?User $actor = null, array $headers = [])
    {
        return $this->actingAs($actor ?? $this->b['sub'])->withHeaders($headers)->postJson('/api/v1/sub-stock/requests', [
            'direction' => $direction, 'items' => [['product_id' => $this->b['product']->id, 'quantity' => $qty]],
        ]);
    }

    private function act(User $actor, int $id, string $action, array $body = [])
    {
        return $this->actingAs($actor)->postJson("/api/v1/sub-stock/requests/{$id}/{$action}", $body);
    }

    public function test_replenishment_follows_request_admin_approval_gudang_execution_and_sub_receipt(): void
    {
        $id = $this->request('replenish', 6)->assertCreated()->assertJsonPath('data.status', 'requested')->json('data.id');
        $this->assertSame([20, 0], [$this->transit(), $this->sub()], 'creating a request moves nothing');

        // wrong actors / wrong order
        $this->act($this->b['gudang'], $id, 'execute')->assertUnprocessable();          // not approved yet
        $this->act($this->b['admin'], $id, 'execute')->assertForbidden();               // Admin never executes
        $this->act($this->b['sub'], $id, 'approve')->assertForbidden();                 // Sub never approves
        $this->act($this->b['gudang'], $id, 'approve')->assertForbidden();              // Gudang never approves
        $this->act($this->b['agen'], $id, 'approve')->assertForbidden();
        $this->act($this->b['sub'], $id, 'receive')->assertUnprocessable();             // nothing handed over yet
        $this->assertSame([20, 0], [$this->transit(), $this->sub()]);

        $this->act($this->b['admin'], $id, 'approve')->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame([20, 0], [$this->transit(), $this->sub()], 'approval alone moves nothing');
        $this->act($this->b['admin'], $id, 'approve')->assertOk(); // idempotent

        $this->act($this->b['gudang'], $id, 'execute')->assertOk()->assertJsonPath('data.status', 'executed');
        $this->assertSame([14, 6], [$this->transit(), $this->sub()], 'Transit decreases and Sub increases exactly once');
        $this->act($this->b['gudang'], $id, 'execute')->assertOk(); // retry
        $this->assertSame([14, 6], [$this->transit(), $this->sub()], 'retry cannot double-move');

        $request = SubStockRequest::withoutGlobalScopes()->findOrFail($id);
        $movements = StockMovement::withoutGlobalScopes()->where('transfer_id', $request->stock_transfer_id)->orderBy('id')->get();
        $this->assertCount(2, $movements);
        $this->assertSame(['transfer_out', 'transit', -6], [$movements[0]->type, $movements[0]->stock_type, (int) $movements[0]->quantity]);
        $this->assertSame(['transfer_in', 'sub', 6], [$movements[1]->type, $movements[1]->stock_type, (int) $movements[1]->quantity]);
        $this->assertSame($this->b['location']->id, (int) $movements[1]->sub_location_id);
        $handover = StockHandover::withoutGlobalScopes()->where('stock_transfer_id', $request->stock_transfer_id)->firstOrFail();
        $this->assertSame($this->b['gudang']->id, $handover->handed_over_by);
        $this->assertSame('handed_over', $handover->status);
        $this->assertSame($movements[0]->handover_id, $handover->id);

        $this->act($this->b['sub'], $id, 'receive')->assertOk()->assertJsonPath('data.status', 'received');
        $this->act($this->b['sub'], $id, 'receive')->assertOk();
        $handover->refresh();
        $this->assertSame([$this->b['sub']->id, 'received'], [$handover->received_by, $handover->status]);
        $this->assertSame(2, StockMovement::withoutGlobalScopes()->where('transfer_id', $request->stock_transfer_id)->count());
    }

    public function test_replenishment_fails_atomically_when_transit_is_insufficient_or_committed_to_agent_reservations(): void
    {
        $id = $this->request('replenish', 25)->assertCreated()->json('data.id');
        $this->act($this->b['admin'], $id, 'approve')->assertOk();
        $this->act($this->b['gudang'], $id, 'execute')->assertUnprocessable();
        $this->assertSame([20, 0], [$this->transit(), $this->sub()]);
        $this->assertSame('approved', SubStockRequest::withoutGlobalScopes()->find($id)->status);
        $this->assertSame(0, StockMovement::withoutGlobalScopes()->where('type', 'transfer_out')->count());

        // Agent Reserved 15 of Transit 20: moving 6 would drop Transit below the Agent's commitments.
        ProductStock::withoutGlobalScopes()->where('agent_id', $this->b['agen']->id)->update(['quantity_reserved' => 15]);
        $id2 = $this->request('replenish', 6)->assertCreated()->json('data.id');
        $this->act($this->b['admin'], $id2, 'approve')->assertOk();
        $this->act($this->b['gudang'], $id2, 'execute')->assertUnprocessable();
        $this->assertSame([20, 0], [$this->transit(), $this->sub()]);
    }

    public function test_return_follows_approval_and_gudang_receipt_and_never_cuts_below_reservations(): void
    {
        $this->setSub(10);
        $id = $this->request('return', 5)->assertCreated()->json('data.id');
        $this->assertSame([20, 10], [$this->transit(), $this->sub()]);
        $this->act($this->b['gudang'], $id, 'execute')->assertUnprocessable();
        $this->act($this->b['admin'], $id, 'approve')->assertOk();
        $this->assertSame([20, 10], [$this->transit(), $this->sub()]);
        $this->act($this->b['sub'], $id, 'execute')->assertForbidden();

        $this->act($this->b['gudang'], $id, 'execute')->assertOk()->assertJsonPath('data.status', 'executed');
        $this->assertSame([25, 5], [$this->transit(), $this->sub()], 'Sub decreases and Transit increases');
        $this->act($this->b['gudang'], $id, 'execute')->assertOk();
        $this->assertSame([25, 5], [$this->transit(), $this->sub()]);
        $this->act($this->b['sub'], $id, 'receive')->assertUnprocessable(); // receive is replenish-only

        $request = SubStockRequest::withoutGlobalScopes()->findOrFail($id);
        $out = StockMovement::withoutGlobalScopes()->where('transfer_id', $request->stock_transfer_id)->where('type', 'transfer_out')->firstOrFail();
        $this->assertSame(['sub', -5, $this->b['location']->id], [$out->stock_type, (int) $out->quantity, (int) $out->sub_location_id]);
        $handover = StockHandover::withoutGlobalScopes()->where('stock_transfer_id', $request->stock_transfer_id)->firstOrFail();
        $this->assertSame([$this->b['sub']->id, $this->b['gudang']->id, 'received'], [$handover->handed_over_by, $handover->received_by, $handover->status]);
    }

    public function test_return_is_limited_by_sub_sellable_at_request_time_and_at_execution(): void
    {
        $this->setSub(10);
        SubStockReservation::create(['agent_id' => $this->b['agen']->id, 'sub_location_id' => $this->b['location']->id, 'order_item_id' => $this->makeOrderItemId(4), 'product_id' => $this->b['product']->id, 'quantity' => 4, 'status' => 'active']);

        $this->request('return', 7)->assertUnprocessable();              // sellable is 10 - 4 = 6
        $id = $this->request('return', 6)->assertCreated()->json('data.id');
        SubStockReservation::query()->update(['quantity' => 6]); // more Sub orders reserved before Gudang got to it
        $this->act($this->b['admin'], $id, 'approve')->assertOk();
        $this->act($this->b['gudang'], $id, 'execute')->assertUnprocessable();
        $this->assertSame([20, 10], [$this->transit(), $this->sub()]);
    }

    private function makeOrderItemId(int $qty): int
    {
        $sub = $this->b['sub'];
        $order = Order::create([
            'order_no' => 'F-'.strtoupper(Str::random(10)), 'konsumen_id' => $sub->id, 'agent_id' => $this->b['agen']->id,
            'payment_method_id' => PaymentMethod::query()->value('id'), 'status' => 'diproses', 'payment_status' => 'unpaid',
            'subtotal_amount' => 0, 'shipping_fee_amount' => 0, 'admin_fee_amount' => 0, 'total_amount' => 0,
            'recipient_name_snapshot' => 'R', 'recipient_phone_snapshot' => '0', 'address_snapshot' => 'R',
        ]);

        return OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->b['product']->id, 'stock_source' => 'sub', 'sub_location_id' => $this->b['location']->id,
            'product_name_snapshot' => 'x', 'sku_snapshot' => 'x', 'unit_price_snapshot' => 1, 'subtotal_snapshot' => $qty,
            'original_quantity' => $qty, 'fulfilled_quantity' => $qty, 'status' => 'diproses',
        ])->id;
    }

    public function test_reject_and_cancel_never_touch_stock_and_close_the_request(): void
    {
        $a = $this->request('replenish', 5)->assertCreated()->json('data.id');
        $b = $this->request('replenish', 5)->assertCreated()->json('data.id');

        $this->act($this->b['admin'], $a, 'reject', ['reason' => 'stok pabrik belum ada'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->act($this->b['sub'], $b, 'cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        foreach ([$a, $b] as $id) {
            $this->act($this->b['admin'], $id, 'approve')->assertUnprocessable();
            $this->act($this->b['gudang'], $id, 'execute')->assertUnprocessable();
        }
        $this->assertSame([20, 0], [$this->transit(), $this->sub()]);
        $this->assertSame(0, StockMovement::withoutGlobalScopes()->whereIn('type', ['transfer_in', 'transfer_out'])->count());
    }

    public function test_only_the_owning_sales_kurir_sub_can_create_and_only_within_their_own_location(): void
    {
        $this->request('replenish', 1, $this->b['gudang'])->assertForbidden();
        $this->request('replenish', 1, $this->b['admin'])->assertForbidden();
        $noLocation = User::factory()->salesKurirSub()->create(['agent_id' => $this->b['agen']->id, 'parent_id' => $this->b['agen']->id]);
        $this->request('replenish', 1, $noLocation)->assertUnprocessable();
        $this->request('replenish', 0)->assertUnprocessable();
        $this->actingAs($this->b['sub'])->postJson('/api/v1/sub-stock/requests', ['direction' => 'replenish', 'items' => [['product_id' => $this->b['product']->id, 'quantity' => 1], ['product_id' => $this->b['product']->id, 'quantity' => 2]]])->assertUnprocessable();
        // a forged sub_location_id is simply ignored: the location is derived from ownership
        $id = $this->actingAs($this->b['sub'])->postJson('/api/v1/sub-stock/requests', ['direction' => 'replenish', 'sub_location_id' => $this->other['location']->id, 'items' => [['product_id' => $this->b['product']->id, 'quantity' => 1]]])->assertCreated()->json('data.id');
        $this->assertSame($this->b['location']->id, SubStockRequest::withoutGlobalScopes()->find($id)->sub_location_id);
    }

    public function test_cross_agent_and_cross_sub_isolation(): void
    {
        $id = $this->request('replenish', 5)->assertCreated()->json('data.id');
        $this->act($this->b['admin'], $id, 'approve')->assertOk();

        $this->act($this->other['admin'], $id, 'approve')->assertNotFound();
        $this->act($this->other['gudang'], $id, 'execute')->assertNotFound();
        $this->actingAs($this->other['sub'])->getJson("/api/v1/sub-stock/requests/{$id}")->assertNotFound();
        $peer = User::factory()->salesKurirSub()->create(['agent_id' => $this->b['agen']->id, 'parent_id' => $this->b['agen']->id]);
        $this->actingAs($peer)->getJson("/api/v1/sub-stock/requests/{$id}")->assertForbidden();
        $this->act($peer, $id, 'cancel')->assertForbidden();
        $this->actingAs($this->b['sub'])->getJson('/api/v1/sub-stock/requests')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($peer)->getJson('/api/v1/sub-stock/requests')->assertOk()->assertJsonCount(0, 'data');

        $this->act($this->b['gudang'], $id, 'execute')->assertOk();
        $this->act($peer, $id, 'receive')->assertForbidden();
        $this->assertSame([15, 5], [$this->transit(), $this->sub()]);
        $this->assertSame([20, 0], [$this->transit($this->other), $this->sub($this->other)]);
    }

    public function test_a_retried_create_with_the_same_idempotency_key_returns_the_same_request(): void
    {
        $key = (string) Str::uuid();
        $first = $this->request('replenish', 5, null, ['Idempotency-Key' => $key])->assertCreated()->json('data.id');
        $second = $this->request('replenish', 5, null, ['Idempotency-Key' => $key])->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, SubStockRequest::withoutGlobalScopes()->count());
    }

    public function test_owned_sub_locations_reject_direct_gudang_transfers_and_my_stock_reports_reserved_and_sellable(): void
    {
        $this->actingAs($this->b['gudang'])->postJson('/api/v1/warehouse/transfers', ['source_stock_type' => 'transit', 'destination_stock_type' => 'sub', 'destination_sub_location_id' => $this->b['location']->id, 'items' => [['product_id' => $this->b['product']->id, 'quantity' => 1]]])->assertUnprocessable();
        $this->assertSame(0, StockTransfer::withoutGlobalScopes()->count());

        $this->setSub(10);
        SubStockReservation::create(['agent_id' => $this->b['agen']->id, 'sub_location_id' => $this->b['location']->id, 'order_item_id' => $this->makeOrderItemId(3), 'product_id' => $this->b['product']->id, 'quantity' => 3, 'status' => 'active']);
        $mine = $this->actingAs($this->b['sub'])->getJson('/api/v1/sub-stock/my')->assertOk();
        $mine->assertJsonPath('data.sub_location.id', $this->b['location']->id);
        $this->assertSame(['physical' => 10, 'reserved' => 3, 'sellable' => 7], collect($mine->json('data.stocks.0'))->only(['physical', 'reserved', 'sellable'])->all());
        $this->actingAs($this->b['gudang'])->getJson('/api/v1/sub-stock/my')->assertForbidden();
    }
}
