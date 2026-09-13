<?php

namespace Tests\Feature;

use App\Models\AgentPaymentGatewayConfig;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

class OrderFulfillmentTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
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
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);

        return compact('agen', 'admin', 'keuangan', 'konsumen');
    }

    private function makeProduct(User $agen, string $name, int $price, int $stockQty): Product
    {
        $product = Product::create(['sku' => 'TEST-'.\Illuminate\Support\Str::uuid(), 
            'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(),
            'has_variations' => false, 'base_price' => $price, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => $stockQty, 'quantity_reserved' => 0]);

        return $product;
    }

    /** @return array{order: Order, itemA: OrderItem, itemB: OrderItem} */
    private function placeTwoProductOrder(User $agen, User $konsumen, Product $productA, int $qtyA, Product $productB, int $qtyB, string $paymentMethodCode = 'cod'): array
    {
        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => $paymentMethodCode,
            'items' => [
                ['product_id' => $productA->id, 'quantity' => $qtyA],
                ['product_id' => $productB->id, 'quantity' => $qtyB],
            ],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);
        $response->assertCreated();

        $order = Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
        $itemA = OrderItem::where('order_id', $order->id)->where('product_id', $productA->id)->firstOrFail();
        $itemB = OrderItem::where('order_id', $order->id)->where('product_id', $productB->id)->firstOrFail();

        return compact('order', 'itemA', 'itemB');
    }

    /** Configures a real bank_transfer gateway for this agen — needed for any test that must NOT use COD (see PaymentGatewayTest for the same pattern). */
    private function configureBankTransfer(User $agen): void
    {
        $bankTransfer = PaymentMethod::where('code', 'bank_transfer')->firstOrFail();
        AgentPaymentGatewayConfig::create([
            'agent_id' => $agen->id, 'payment_method_id' => $bankTransfer->id, 'environment' => 'sandbox',
            'config' => ['bank_name' => 'BCA', 'account_name' => 'QA', 'account_number' => '123'],
        ]);
    }

    /**
     * The exact worked example from the Blueprint: A=5,B=3 ordered; admin fulfills A=4,B=3 -> A loses 1 -> refund for 1x price A.
     * Uses bank_transfer (real money already collected) — a refund record only ever makes sense when something was actually paid.
     */
    public function test_reducing_fulfilled_quantity_creates_a_refund_record_for_the_shortfall(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $this->configureBankTransfer($agen);
        $productA = $this->makeProduct($agen, 'Product A', 100000, 10);
        $productB = $this->makeProduct($agen, 'Product B', 50000, 10);
        ['order' => $order, 'itemA' => $itemA, 'itemB' => $itemB] = $this->placeTwoProductOrder($agen, $konsumen, $productA, 5, $productB, 3, 'bank_transfer');
        $order->update(['payment_status' => 'paid']); // simulate a verified transfer so the order can advance to diproses

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();

        $response = $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$itemA->id}/fulfillment", [
            'fulfilled_quantity' => 4, 'reason' => 'Stok tidak mencukupi',
        ]);
        $response->assertOk();

        $itemA->refresh();
        $this->assertSame(5, $itemA->original_quantity);
        $this->assertSame(4, $itemA->fulfilled_quantity);
        $this->assertSame(1, $itemA->cancelled_quantity);
        $this->assertSame(1, $itemA->refund_quantity);
        $this->assertSame(0, $itemA->additional_quantity);
        // original_quantity/subtotal_snapshot are the historical record — never rewritten.
        $this->assertEquals(500000, (float) $itemA->subtotal_snapshot);

        $this->assertDatabaseHas('order_item_adjustments', [
            'order_item_id' => $itemA->id, 'quantity_reduced' => 1, 'refund_amount' => 100000, 'refund_status' => 'pending',
        ]);

        // Product B untouched.
        $itemB->refresh();
        $this->assertSame(3, $itemB->fulfilled_quantity);
        $this->assertSame(0, $itemB->cancelled_quantity);

        // The released unit is available to other orders again.
        $stock = ProductStock::withoutGlobalScopes()->where('product_id', $productA->id)->first();
        $this->assertSame(4, $stock->quantity_reserved); // 5 reserved at order time - 1 released
        $this->assertSame(6, $stock->availableQuantity()); // 10 on hand - 4 reserved

        $this->assertDatabaseHas('activity_logs', ['event' => 'order_item.fulfillment_adjusted']);
    }

    /** COD never actually collected anything yet — reducing quantity is a plain cancellation, never a "refund" (nothing to give back). */
    public function test_reducing_fulfilled_quantity_on_a_cod_order_cancels_without_a_refund_record(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Product A', 100000, 10);
        $productB = $this->makeProduct($agen, 'Product B', 50000, 10);
        ['order' => $order, 'itemA' => $itemA] = $this->placeTwoProductOrder($agen, $konsumen, $productA, 5, $productB, 3);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$itemA->id}/fulfillment", [
            'fulfilled_quantity' => 4, 'reason' => 'Stok tidak mencukupi',
        ])->assertOk();

        $itemA->refresh();
        $this->assertSame(4, $itemA->fulfilled_quantity);
        $this->assertSame(1, $itemA->cancelled_quantity);
        $this->assertSame(0, $itemA->refund_quantity);

        $this->assertDatabaseMissing('order_item_adjustments', ['order_item_id' => $itemA->id]);
    }

    public function test_reducing_fulfilled_quantity_to_zero_marks_the_item_dibatalkan(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, 'Only Product', 100000, 10);
        ['order' => $order, 'itemA' => $item] = $this->placeTwoProductOrder($agen, $konsumen, $product, 2, $this->makeProduct($agen, 'Filler', 10000, 10), 1);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/fulfillment", [
            'fulfilled_quantity' => 0, 'reason' => 'Habis',
        ])->assertOk();

        $item->refresh();
        $this->assertSame('dibatalkan', $item->status);
        $this->assertSame(0, $item->fulfilled_quantity);
        $this->assertSame(2, $item->cancelled_quantity);
    }

    /** Only a non-COD order (real money already collected) ever gets a separate additional-payment record for an increased quantity. */
    public function test_increasing_fulfilled_quantity_creates_an_additional_payment_record(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $this->configureBankTransfer($agen);
        $productA = $this->makeProduct($agen, 'Product A', 100000, 10);
        $productB = $this->makeProduct($agen, 'Product B', 50000, 10);
        ['order' => $order, 'itemA' => $itemA] = $this->placeTwoProductOrder($agen, $konsumen, $productA, 3, $productB, 1, 'bank_transfer');
        $order->update(['payment_status' => 'paid']); // simulate a verified transfer so the order can advance to diproses

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();

        $response = $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$itemA->id}/fulfillment", [
            'fulfilled_quantity' => 5, 'reason' => 'Customer wants 2 more', 'additional_payment_method' => 'transfer',
        ]);
        $response->assertOk();

        $itemA->refresh();
        $this->assertSame(5, $itemA->fulfilled_quantity);
        $this->assertSame(2, $itemA->additional_quantity);
        $this->assertNotNull($itemA->additional_payment_id);

        $this->assertDatabaseHas('order_additional_payments', [
            'order_id' => $order->id, 'method' => 'transfer', 'amount' => 200000, 'status' => 'pending',
        ]);

        // A real payment transaction was created for the transfer method.
        $additionalPayment = $itemA->additionalPayment;
        $this->assertNotNull($additionalPayment->payment_transaction_id);

        // Extra stock was actually reserved for the 2 additional units.
        $stock = ProductStock::withoutGlobalScopes()->where('product_id', $productA->id)->first();
        $this->assertSame(5, $stock->quantity_reserved);
    }

    /** Choosing "cod" as the ADDITIONAL charge's own collection method (order itself paid by transfer) still creates a record, just no online transaction. */
    public function test_increasing_fulfilled_quantity_with_cod_as_the_additional_charge_method_creates_no_payment_transaction(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $this->configureBankTransfer($agen);
        $productA = $this->makeProduct($agen, 'Product A', 100000, 10);
        ['order' => $order, 'itemA' => $itemA] = $this->placeTwoProductOrder($agen, $konsumen, $productA, 1, $this->makeProduct($agen, 'Filler', 10000, 10), 1, 'bank_transfer');
        $order->update(['payment_status' => 'paid']);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$itemA->id}/fulfillment", [
            'fulfilled_quantity' => 2, 'reason' => 'Extra unit', 'additional_payment_method' => 'cod',
        ])->assertOk();

        $additionalPayment = $itemA->fresh()->additionalPayment;
        $this->assertSame('cod', $additionalPayment->method);
        $this->assertNull($additionalPayment->payment_transaction_id);
    }

    /** The order's OWN payment method being COD blocks additional-payment creation entirely, regardless of what method is requested for the extra charge. */
    public function test_increasing_fulfilled_quantity_on_a_cod_order_never_creates_an_additional_payment_record(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Product A', 100000, 10);
        ['order' => $order, 'itemA' => $itemA] = $this->placeTwoProductOrder($agen, $konsumen, $productA, 1, $this->makeProduct($agen, 'Filler', 10000, 10), 1);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$itemA->id}/fulfillment", [
            'fulfilled_quantity' => 2, 'reason' => 'Extra unit', 'additional_payment_method' => 'transfer',
        ])->assertOk();

        $itemA->refresh();
        $this->assertSame(2, $itemA->fulfilled_quantity);
        $this->assertSame(1, $itemA->additional_quantity);
        $this->assertNull($itemA->additional_payment_id);
        $this->assertDatabaseMissing('order_additional_payments', ['order_id' => $order->id]);
    }

    public function test_insufficient_stock_rejects_a_fulfillment_increase(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Scarce Product', 100000, 3); // only 3 on hand
        ['order' => $order, 'itemA' => $itemA] = $this->placeTwoProductOrder($agen, $konsumen, $productA, 2, $this->makeProduct($agen, 'Filler', 10000, 10), 1);
        // 2 reserved, 1 left available.

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$itemA->id}/fulfillment", [
            'fulfilled_quantity' => 5, 'reason' => 'Try to add too many',
        ])->assertStatus(422);

        $this->assertSame(2, $itemA->fresh()->fulfilled_quantity); // unchanged
    }

    public function test_fulfillment_cannot_be_adjusted_before_diproses_or_after_dikirim(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $this->configureBankTransfer($agen);
        $productA = $this->makeProduct($agen, 'Product A', 100000, 10);
        // Bank transfer (not COD) so the order actually starts 'diterima' — a COD order
        // is created straight into 'diproses' and has no "too early" window to test here.
        ['order' => $order, 'itemA' => $itemA] = $this->placeTwoProductOrder($agen, $konsumen, $productA, 3, $this->makeProduct($agen, 'Filler', 10000, 10), 1, 'bank_transfer');

        // Still 'diterima' — too early.
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$itemA->id}/fulfillment", [
            'fulfilled_quantity' => 2, 'reason' => 'Too early',
        ])->assertStatus(422);

        $order->update(['payment_status' => 'paid']); // simulate a verified transfer so the order can advance to diproses
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'dikirim'])->assertOk();

        // Too late — already shipped.
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$itemA->id}/fulfillment", [
            'fulfilled_quantity' => 2, 'reason' => 'Too late',
        ])->assertStatus(422);
    }

    public function test_admin_from_another_branch_cannot_adjust_this_orders_items(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Product A', 100000, 10);
        ['order' => $order, 'itemA' => $itemA] = $this->placeTwoProductOrder($agen, $konsumen, $productA, 3, $this->makeProduct($agen, 'Filler', 10000, 10), 1);

        $otherAgen = User::factory()->agen()->create();
        $otherAgen->update(['agent_id' => $otherAgen->id]);
        $otherAdmin = User::factory()->admin()->create(['agent_id' => $otherAgen->id]);

        $this->actingAs($otherAdmin)->patchJson("/api/v1/orders/{$order->id}/items/{$itemA->id}/fulfillment", [
            'fulfilled_quantity' => 2, 'reason' => 'Not my branch',
        ])->assertStatus(404); // BelongsToAgentScope hides the order entirely, same as other order endpoints.
    }

    /* ---------------- Admin dashboard: refund + additional payment status ---------------- */

    public function test_keuangan_can_view_and_mark_a_refund_processed(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'keuangan' => $keuangan, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $this->configureBankTransfer($agen);
        $productA = $this->makeProduct($agen, 'Product A', 100000, 10);
        ['order' => $order, 'itemA' => $itemA] = $this->placeTwoProductOrder($agen, $konsumen, $productA, 5, $this->makeProduct($agen, 'Filler', 10000, 10), 1, 'bank_transfer');
        $order->update(['payment_status' => 'paid']);
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$itemA->id}/fulfillment", [
            'fulfilled_quantity' => 4, 'reason' => 'Shortfall',
        ])->assertOk();

        $list = $this->actingAs($admin)->getJson('/api/v1/admin/order-refunds');
        $list->assertOk();
        $this->assertCount(1, $list->json('data'));
        $this->assertSame($konsumen->name, $list->json('data.0.customer_name'));
        $adjustmentId = $list->json('data.0.id');

        // Separation of duties: ADMIN may view the ledger but not settle it financially.
        $this->actingAs($admin)->patchJson("/api/v1/admin/order-refunds/{$adjustmentId}/status", ['refund_status' => 'processed'])
            ->assertStatus(403);
        $this->actingAs($keuangan)->patchJson("/api/v1/admin/order-refunds/{$adjustmentId}/status", ['refund_status' => 'processed'])
            ->assertOk()->assertJsonPath('data.refund_status', 'processed');

        $this->assertDatabaseHas('activity_logs', ['event' => 'order_item_adjustment.refund_status_changed']);
    }

    public function test_keuangan_can_view_and_mark_an_additional_payment_paid(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'keuangan' => $keuangan, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $this->configureBankTransfer($agen);
        $productA = $this->makeProduct($agen, 'Product A', 100000, 10);
        ['order' => $order, 'itemA' => $itemA] = $this->placeTwoProductOrder($agen, $konsumen, $productA, 1, $this->makeProduct($agen, 'Filler', 10000, 10), 1, 'bank_transfer');
        $order->update(['payment_status' => 'paid']);
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$itemA->id}/fulfillment", [
            'fulfilled_quantity' => 2, 'reason' => 'Extra', 'additional_payment_method' => 'cod',
        ])->assertOk();

        $list = $this->actingAs($admin)->getJson('/api/v1/admin/additional-payments');
        $list->assertOk();
        $paymentId = $list->json('data.0.id');

        // Separation of duties: ADMIN may view the ledger but not settle it financially.
        $this->actingAs($admin)->patchJson("/api/v1/admin/additional-payments/{$paymentId}/status", ['paid' => true])
            ->assertStatus(403);
        $this->actingAs($keuangan)->patchJson("/api/v1/admin/additional-payments/{$paymentId}/status", ['paid' => true])
            ->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertDatabaseHas('activity_logs', ['event' => 'order_additional_payment.status_changed']);
    }

    public function test_super_admin_can_filter_refunds_and_additional_payments_by_agent_id(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $this->configureBankTransfer($agen);
        $productA = $this->makeProduct($agen, 'Product A', 100000, 10);
        ['order' => $order, 'itemA' => $itemA] = $this->placeTwoProductOrder($agen, $konsumen, $productA, 5, $this->makeProduct($agen, 'Filler', 10000, 10), 1, 'bank_transfer');
        $order->update(['payment_status' => 'paid']);
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$itemA->id}/fulfillment", [
            'fulfilled_quantity' => 4, 'reason' => 'Shortfall',
        ])->assertOk();

        $otherBranch = $this->makeAgentBranch();
        $superAdmin = User::factory()->superAdmin()->create();

        $refunds = $this->actingAs($superAdmin)->getJson('/api/v1/admin/order-refunds?agent_id='.$agen->id);
        $refunds->assertOk();
        $this->assertCount(1, $refunds->json('data'));

        $refundsOther = $this->actingAs($superAdmin)->getJson('/api/v1/admin/order-refunds?agent_id='.$otherBranch['agen']->id);
        $refundsOther->assertOk();
        $this->assertCount(0, $refundsOther->json('data'));
    }
}
