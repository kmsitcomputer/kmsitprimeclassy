<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ReturnItem;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

class ReturnSystemTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        Storage::fake('public');
    }

    private function makeAgentBranch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko QA', 'address' => 'Jl. QA',
            'latitude' => -6.2, 'longitude' => 106.8166,
        ]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);

        return compact('agen', 'admin', 'konsumen');
    }

    /** @return array{order: Order, item: OrderItem} */
    private function makeDeliveredOrder(User $agen, User $admin, User $konsumen, int $price = 100000, int $qty = 3, int $stock = 10): array
    {
        $product = Product::create(['sku' => 'TEST-'.\Illuminate\Support\Str::uuid(), 
            'name' => 'Returnable Cake', 'slug' => 'returnable-cake-'.uniqid(),
            'has_variations' => false, 'base_price' => $price, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => $stock, 'quantity_reserved' => 0]);

        $orderId = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => $qty]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ])->json('data.id');

        // COD order is already 'diproses' on creation.
        $order = Order::withoutGlobalScopes()->findOrFail($orderId);
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'dikirim'])->assertOk();
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'terkirim'])->assertOk();

        $item = OrderItem::where('order_id', $order->id)->firstOrFail();

        return ['order' => $order->fresh(), 'item' => $item];
    }

    public function test_konsumen_can_request_a_return_for_one_item_and_it_does_not_require_the_whole_order(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        ['order' => $order, 'item' => $item] = $this->makeDeliveredOrder($agen, $admin, $konsumen, price: 100000, qty: 3);

        $response = $this->actingAs($konsumen)->post("/api/v1/orders/{$order->id}/returns", [
            'reason' => 'Rusak saat diterima',
            'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
            'evidence' => UploadedFile::fake()->image('proof.jpg'),
        ]);

        $response->assertCreated();
        $this->assertEquals(100000, (float) $response->json('data.total_refund_amount')); // 1x price, not the whole order

        $item->refresh();
        $this->assertSame('pengembalian', $item->status);
        $this->assertSame(1, $item->returned_quantity);

        $this->assertDatabaseHas('return_items', [
            'order_item_id' => $item->id, 'quantity_returned' => 1, 'refund_amount' => 100000, 'status' => 'pending',
        ]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'return.requested']);
    }

    public function test_return_is_rejected_before_item_is_terkirim(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = Product::create(['sku' => 'TEST-'.\Illuminate\Support\Str::uuid(), 'name' => 'Not Yet Delivered', 'slug' => 'nyd-'.uniqid(), 'has_variations' => false, 'base_price' => 50000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 5, 'quantity_reserved' => 0]);

        $orderId = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod', 'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ])->json('data.id');
        $item = OrderItem::where('order_id', $orderId)->firstOrFail();

        $this->actingAs($konsumen)->post("/api/v1/orders/{$orderId}/returns", [
            'reason' => 'Too early', 'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
            'evidence' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertStatus(422);
    }

    public function test_konsumen_cannot_request_a_return_for_another_konsumens_order(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        ['order' => $order, 'item' => $item] = $this->makeDeliveredOrder($agen, $admin, $konsumen);

        $otherKonsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);

        $this->actingAs($otherKonsumen)->post("/api/v1/orders/{$order->id}/returns", [
            'reason' => 'Not mine', 'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
            'evidence' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertStatus(403);
    }

    public function test_return_quantity_cannot_exceed_what_remains_returnable(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        ['order' => $order, 'item' => $item] = $this->makeDeliveredOrder($agen, $admin, $konsumen, qty: 2);

        $this->actingAs($konsumen)->post("/api/v1/orders/{$order->id}/returns", [
            'reason' => 'Too many', 'items' => [['order_item_id' => $item->id, 'quantity' => 5]],
            'evidence' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertStatus(422);
    }

    public function test_admin_can_approve_a_return_restock_it_and_then_mark_it_refunded(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        ['order' => $order, 'item' => $item] = $this->makeDeliveredOrder($agen, $admin, $konsumen, price: 80000, qty: 4, stock: 10);

        $stockBefore = ProductStock::withoutGlobalScopes()->where('product_id', $item->product_id)->first()->quantity_on_hand;

        $returnId = $this->actingAs($konsumen)->post("/api/v1/orders/{$order->id}/returns", [
            'reason' => 'Salah kirim', 'items' => [['order_item_id' => $item->id, 'quantity' => 2, 'restock' => true]],
            'evidence' => UploadedFile::fake()->image('proof.jpg'),
        ])->json('data.id');

        $list = $this->actingAs($admin)->getJson('/api/v1/admin/returns');
        $list->assertOk();
        $this->assertCount(1, $list->json('data'));
        $this->assertSame($konsumen->name, $list->json('data.0.customer_name'));
        $this->assertSame(80000 * 2, (int) $list->json('data.0.total_refund_amount'));

        $review = $this->actingAs($admin)->patchJson("/api/v1/admin/returns/{$returnId}/review", ['approved' => true]);
        $review->assertOk()->assertJsonPath('data.status', 'approved');

        // Restocked: 2 units added back on hand.
        $stockAfter = ProductStock::withoutGlobalScopes()->where('product_id', $item->product_id)->first()->quantity_on_hand;
        $this->assertEquals($stockBefore + 2, $stockAfter);

        $returnItem = ReturnItem::where('order_item_id', $item->id)->firstOrFail();
        $this->assertSame('approved', $returnItem->status);
        $this->assertSame('pending', $returnItem->refund_status);

        // The order was COD and already delivered — Keuangan has collected the
        // cash and marked it paid, so the refund is now a valid post-paid
        // financial adjustment.
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $agen->id]);
        $this->actingAs($keuangan)->patchJson("/api/v1/orders/{$order->id}/payment/cod", ['paid' => true])->assertOk();

        // Financial refund settlement is KEUANGAN's authority — ADMIN is refused.
        $this->actingAs($admin)->patchJson("/api/v1/admin/return-items/{$returnItem->id}/refund", [])
            ->assertStatus(403);
        $this->actingAs($keuangan)->patchJson("/api/v1/admin/return-items/{$returnItem->id}/refund", [])
            ->assertOk();

        $returnItem->refresh();
        $this->assertSame('processed', $returnItem->refund_status);

        $item->refresh();
        $this->assertSame('kembali', $item->status);
        $this->assertSame(2, $item->refund_quantity);

        $this->assertDatabaseHas('activity_logs', ['event' => 'return.reviewed']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'return_item.refund_marked']);
    }

    public function test_admin_rejecting_a_return_reverts_the_item_back_to_terkirim(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        ['order' => $order, 'item' => $item] = $this->makeDeliveredOrder($agen, $admin, $konsumen, qty: 3);

        $returnId = $this->actingAs($konsumen)->post("/api/v1/orders/{$order->id}/returns", [
            'reason' => 'Changed my mind', 'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
            'evidence' => UploadedFile::fake()->image('proof.jpg'),
        ])->json('data.id');

        $this->actingAs($admin)->patchJson("/api/v1/admin/returns/{$returnId}/review", ['approved' => false, 'note' => 'Not eligible'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        $item->refresh();
        $this->assertSame('terkirim', $item->status);
        $this->assertSame(0, $item->returned_quantity);
    }

    public function test_admin_from_another_branch_cannot_view_or_review_this_return(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        ['order' => $order, 'item' => $item] = $this->makeDeliveredOrder($agen, $admin, $konsumen);

        $returnId = $this->actingAs($konsumen)->post("/api/v1/orders/{$order->id}/returns", [
            'reason' => 'X', 'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
            'evidence' => UploadedFile::fake()->image('proof.jpg'),
        ])->json('data.id');

        $otherAgen = User::factory()->agen()->create();
        $otherAgen->update(['agent_id' => $otherAgen->id]);
        $otherAdmin = User::factory()->admin()->create(['agent_id' => $otherAgen->id]);

        $this->actingAs($otherAdmin)->getJson("/api/v1/admin/returns/{$returnId}")->assertStatus(403);
        $this->actingAs($otherAdmin)->patchJson("/api/v1/admin/returns/{$returnId}/review", ['approved' => true])->assertStatus(403);
    }

    public function test_super_admin_can_filter_the_returns_listing_by_agent_id(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        ['order' => $order, 'item' => $item] = $this->makeDeliveredOrder($agen, $admin, $konsumen);
        $this->actingAs($konsumen)->post("/api/v1/orders/{$order->id}/returns", [
            'reason' => 'X', 'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
            'evidence' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertCreated();

        $otherBranch = $this->makeAgentBranch();
        $superAdmin = User::factory()->superAdmin()->create();

        $filtered = $this->actingAs($superAdmin)->getJson('/api/v1/admin/returns?agent_id='.$agen->id);
        $filtered->assertOk();
        $this->assertTrue(collect($filtered->json('data'))->contains('order_id', $order->id));

        $otherFiltered = $this->actingAs($superAdmin)->getJson('/api/v1/admin/returns?agent_id='.$otherBranch['agen']->id);
        $otherFiltered->assertOk();
        $this->assertFalse(collect($otherFiltered->json('data'))->contains('order_id', $order->id));
    }

    public function test_konsumen_sees_their_own_return_status_on_order_detail_with_no_fee_fields_anywhere(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        ['order' => $order, 'item' => $item] = $this->makeDeliveredOrder($agen, $admin, $konsumen, price: 100000, qty: 2);

        $this->actingAs($konsumen)->post("/api/v1/orders/{$order->id}/returns", [
            'reason' => 'Tidak sesuai pesanan',
            'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
            'evidence' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertCreated();

        $response = $this->actingAs($konsumen)->getJson("/api/v1/orders/{$order->id}");
        $response->assertOk();

        $returns = $response->json('data.returns');
        $this->assertCount(1, $returns);
        $this->assertSame('Tidak sesuai pesanan', $returns[0]['reason']);
        $this->assertSame('requested', $returns[0]['status']);
        $this->assertSame(1, $returns[0]['items'][0]['quantity_returned']);
        $this->assertSame('pending', $returns[0]['items'][0]['status']);
        $this->assertSame('pengembalian', OrderItem::find($item->id)->status);

        // Konsumen must never see any fee field anywhere in this response.
        $body = $response->json();
        $this->assertStringNotContainsString('agent_fee', json_encode($body));
        $this->assertStringNotContainsString('sales_fee', json_encode($body));
        $this->assertStringNotContainsString('courier_fee', json_encode($body));
    }

    public function test_konsumen_never_sees_another_konsumens_return_via_order_detail(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        ['order' => $order, 'item' => $item] = $this->makeDeliveredOrder($agen, $admin, $konsumen);
        $this->actingAs($konsumen)->post("/api/v1/orders/{$order->id}/returns", [
            'reason' => 'X', 'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
            'evidence' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertCreated();

        $otherKonsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);

        $this->actingAs($otherKonsumen)->getJson("/api/v1/orders/{$order->id}")->assertStatus(403);
    }
}
