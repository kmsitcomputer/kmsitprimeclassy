<?php

namespace Tests\Feature;

use App\Models\AgentPaymentGatewayConfig;
use App\Models\AgentProfile;
use App\Models\Commission;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
     * Simulates a verified transfer that actually settled the order —
     * sets paid_amount/remaining_amount consistently with payment_status,
     * not just the status flag alone. The refund/additional-payment eligibility
     * formulas (OrderFulfillmentService) key off ACTUAL paid_amount, not
     * payment_status, so a test must set both together or it exercises a
     * state PaymentService itself could never actually produce.
     */
    private function payInFull(Order $order): Order
    {
        $order->update(['payment_status' => 'paid', 'paid_amount' => $order->total_amount, 'remaining_amount' => 0]);

        return $order->fresh();
    }

    /** @return array{order: Order, item: OrderItem} */
    private function placeSingleItemOrder(User $konsumen, Product $product, int $quantity, string $paymentMethodCode = 'cod'): array
    {
        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => $paymentMethodCode,
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);
        $response->assertCreated();

        $order = Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();

        return compact('order', 'item');
    }

    /** @return array{order: Order, item: OrderItem} */
    private function placeDpOrder(User $konsumen, Product $product, float $dpAmount): array
    {
        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'down_payment', 'dp_amount' => $dpAmount,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);
        $response->assertCreated();

        $order = Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();

        return compact('order', 'item');
    }

    /** Runs the real proof-upload + Keuangan-verify flow against whichever manual transaction is currently latest (DP, or a later settlement). */
    private function verifyLatestManualPayment(User $konsumen, User $keuangan, Order $order): Order
    {
        $this->actingAs($konsumen)->postJson("/api/v1/orders/{$order->id}/payment/proof", [
            'proof' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertOk();
        $this->actingAs($keuangan)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertOk();

        return $order->fresh();
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
        $order = $this->payInFull($order);

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
        $order = $this->payInFull($order);

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
        $order = $this->payInFull($order);

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

        $order = $this->payInFull($order);
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
        $order = $this->payInFull($order);
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
        $order = $this->payInFull($order);
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
        $order = $this->payInFull($order);
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

    /* ---------------------------------------------------------------
     * Canonical order-total recalculation (OrderTotalCalculator)
     * ------------------------------------------------------------- */

    public function test_adding_item_recalculates_order_total(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, 'Kue COD', 100000, 10);
        ['order' => $order, 'item' => $item] = $this->placeSingleItemOrder($konsumen, $product, 5);
        $this->assertEquals(500000, (float) $order->total_amount);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/fulfillment", [
            'fulfilled_quantity' => 6, 'reason' => 'Tambah 1 unit',
        ])->assertOk();

        $order = $order->fresh();
        $this->assertEquals(600000, (float) $order->subtotal_amount);
        $this->assertEquals(600000, (float) $order->total_amount);
        $this->assertEquals(600000, (float) $order->remaining_amount);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertDatabaseMissing('order_additional_payments', ['order_id' => $order->id]);
    }

    public function test_removing_item_recalculates_order_total(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, 'Kue COD', 100000, 10);
        ['order' => $order, 'item' => $item] = $this->placeSingleItemOrder($konsumen, $product, 5);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/fulfillment", [
            'fulfilled_quantity' => 4, 'reason' => 'Kurangi 1 unit',
        ])->assertOk();

        $order = $order->fresh();
        $this->assertEquals(400000, (float) $order->total_amount);
        $this->assertEquals(400000, (float) $order->remaining_amount);
        $this->assertDatabaseMissing('order_item_adjustments', ['order_item_id' => $item->id]);
    }

    public function test_updating_quantity_recalculates_order_total_both_directions(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $product = $this->makeProduct($agen, 'Kue COD', 50000, 20);
        ['order' => $order, 'item' => $item] = $this->placeSingleItemOrder($konsumen, $product, 4);
        $this->assertEquals(200000, (float) $order->total_amount);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/fulfillment", [
            'fulfilled_quantity' => 7, 'reason' => 'Naik',
        ])->assertOk();
        $this->assertEquals(350000, (float) $order->fresh()->total_amount);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/fulfillment", [
            'fulfilled_quantity' => 2, 'reason' => 'Turun',
        ])->assertOk();
        $this->assertEquals(100000, (float) $order->fresh()->total_amount);
    }

    /* ---------------------------------------------------------------
     * DP — increase/reduce, remaining balance, additional payment, refund
     * ------------------------------------------------------------- */

    /** Blueprint DP example: 500k total, 200k DP verified, +100k product -> 600k total, remaining 400k, NO additional payment. */
    public function test_dp_order_increase_updates_remaining_balance_without_additional_payment(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'keuangan' => $keuangan, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $this->configureBankTransfer($agen);
        $productA = $this->makeProduct($agen, 'Produk Utama', 400000, 10);
        $productB = $this->makeProduct($agen, 'Produk Tambahan', 100000, 10);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'down_payment', 'dp_amount' => 200000,
            'items' => [['product_id' => $productA->id, 'quantity' => 1], ['product_id' => $productB->id, 'quantity' => 1]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);
        $response->assertCreated();
        $order = Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
        $itemB = OrderItem::where('order_id', $order->id)->where('product_id', $productB->id)->firstOrFail();

        $order = $this->verifyLatestManualPayment($konsumen, $keuangan, $order);
        $this->assertSame('partially_paid', $order->payment_status);
        $this->assertEquals(200000, (float) $order->paid_amount);
        $this->assertEquals(300000, (float) $order->remaining_amount);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$itemB->id}/fulfillment", [
            'fulfilled_quantity' => 2, 'reason' => 'Tambah 1 unit produk tambahan',
        ])->assertOk();

        $order = $order->fresh();
        $this->assertEquals(600000, (float) $order->total_amount);
        $this->assertEquals(200000, (float) $order->paid_amount, 'the verified DP payment itself never changes');
        $this->assertEquals(400000, (float) $order->remaining_amount);
        $this->assertSame('partially_paid', $order->payment_status);
        $this->assertDatabaseMissing('order_additional_payments', ['order_id' => $order->id]);
    }

    public function test_dp_reduction_without_overpayment_does_not_create_refund(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'keuangan' => $keuangan, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $this->configureBankTransfer($agen);
        $productA = $this->makeProduct($agen, 'Produk Utama', 400000, 10);
        $productB = $this->makeProduct($agen, 'Produk Kurang', 100000, 10);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'down_payment', 'dp_amount' => 200000,
            'items' => [['product_id' => $productA->id, 'quantity' => 1], ['product_id' => $productB->id, 'quantity' => 1]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);
        $response->assertCreated();
        $order = Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
        $itemB = OrderItem::where('order_id', $order->id)->where('product_id', $productB->id)->firstOrFail();

        $order = $this->verifyLatestManualPayment($konsumen, $keuangan, $order);
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();

        // Remove the 100k item entirely -> new total 400k, paid still 200k < 400k -> no refund, just outstanding.
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$itemB->id}/fulfillment", [
            'fulfilled_quantity' => 0, 'reason' => 'Batal produk tambahan',
        ])->assertOk();

        $order = $order->fresh();
        $this->assertEquals(400000, (float) $order->total_amount);
        $this->assertEquals(200000, (float) $order->remaining_amount);
        $this->assertDatabaseMissing('order_item_adjustments', ['order_item_id' => $itemB->id]);
    }

    /** Blueprint DP overpayment example: 500k total, 300k DP verified, remove 300k of product -> 200k total, paid 300k -> 100k refund eligible. */
    public function test_dp_reduction_with_overpayment_creates_refund_eligibility(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'keuangan' => $keuangan, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $this->configureBankTransfer($agen);
        $productA = $this->makeProduct($agen, 'Produk Tetap', 200000, 10);
        $productB = $this->makeProduct($agen, 'Produk Dibatalkan', 300000, 10);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'down_payment', 'dp_amount' => 300000,
            'items' => [['product_id' => $productA->id, 'quantity' => 1], ['product_id' => $productB->id, 'quantity' => 1]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);
        $response->assertCreated();
        $order = Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
        $itemB = OrderItem::where('order_id', $order->id)->where('product_id', $productB->id)->firstOrFail();

        $order = $this->verifyLatestManualPayment($konsumen, $keuangan, $order);
        $this->assertEquals(300000, (float) $order->paid_amount);
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$itemB->id}/fulfillment", [
            'fulfilled_quantity' => 0, 'reason' => 'Batal produk 300k',
        ])->assertOk();

        $order = $order->fresh();
        $this->assertEquals(200000, (float) $order->total_amount);
        $this->assertEquals(0, (float) $order->remaining_amount);
        $this->assertDatabaseHas('order_item_adjustments', [
            'order_item_id' => $itemB->id, 'refund_amount' => 100000, 'refund_status' => 'pending',
        ]);
    }

    /* ---------------------------------------------------------------
     * Invariants
     * ------------------------------------------------------------- */

    public function test_remaining_balance_never_negative_after_reduction_on_a_fully_paid_order(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $this->configureBankTransfer($agen);
        $productA = $this->makeProduct($agen, 'Produk A', 100000, 10);
        ['order' => $order, 'item' => $itemA] = $this->placeSingleItemOrder($konsumen, $productA, 5, 'bank_transfer');
        $order = $this->payInFull($order);
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$itemA->id}/fulfillment", [
            'fulfilled_quantity' => 4, 'reason' => 'Kurangi',
        ])->assertOk();

        $order = $order->fresh();
        $this->assertGreaterThanOrEqual(0, (float) $order->remaining_amount);
        $this->assertEquals(0, (float) $order->remaining_amount);
    }

    /** Two sequential reductions on the same fully-paid order: the second refund must be the INCREMENTAL overpayment only, never re-refunding money the first adjustment already covers. */
    public function test_refund_amount_never_exceeds_actual_overpayment_across_sequential_reductions(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $this->configureBankTransfer($agen);
        $productA = $this->makeProduct($agen, 'Produk A', 100000, 10);
        $productB = $this->makeProduct($agen, 'Produk B', 100000, 10);
        ['order' => $order, 'itemA' => $itemA, 'itemB' => $itemB] = $this->placeTwoProductOrder($agen, $konsumen, $productA, 5, $productB, 5, 'bank_transfer');
        $order = $this->payInFull($order);
        $this->assertEquals(1000000, (float) $order->paid_amount);
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();

        // First reduction: -1 unit of A (100k) -> overpaid 100k.
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$itemA->id}/fulfillment", [
            'fulfilled_quantity' => 4, 'reason' => 'Kurangi A',
        ])->assertOk();
        $this->assertDatabaseHas('order_item_adjustments', ['order_item_id' => $itemA->id, 'refund_amount' => 100000]);

        // Second reduction: -1 unit of B (100k) -> total overpaid now 200k, but only the NEW 100k belongs to this adjustment.
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$itemB->id}/fulfillment", [
            'fulfilled_quantity' => 4, 'reason' => 'Kurangi B',
        ])->assertOk();
        $this->assertDatabaseHas('order_item_adjustments', ['order_item_id' => $itemB->id, 'refund_amount' => 100000]);

        $totalRefunded = (float) \App\Models\OrderItemAdjustment::query()
            ->whereIn('order_item_id', [$itemA->id, $itemB->id])->sum('refund_amount');
        $order = $order->fresh();
        $actualOverpaid = max(0.0, (float) $order->paid_amount - (float) $order->total_amount);
        $this->assertEquals(200000, $totalRefunded);
        $this->assertEquals($actualOverpaid, $totalRefunded, 'sum of refund rows must never exceed (or fall short of) actual overpayment');
    }

    public function test_payment_status_reflects_actual_paid_amount_through_additional_payment_lifecycle(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'keuangan' => $keuangan, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $this->configureBankTransfer($agen);
        $productA = $this->makeProduct($agen, 'Produk A', 100000, 10);
        ['order' => $order, 'item' => $itemA] = $this->placeSingleItemOrder($konsumen, $productA, 5, 'bank_transfer');
        $order = $this->payInFull($order);
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$itemA->id}/fulfillment", [
            'fulfilled_quantity' => 6, 'reason' => 'Tambah 1 unit', 'additional_payment_method' => 'transfer',
        ])->assertOk();

        // Total grew to 600k but paid_amount is still the original 500k -> no longer fully paid.
        $order = $order->fresh();
        $this->assertEquals(600000, (float) $order->total_amount);
        $this->assertEquals(500000, (float) $order->paid_amount);
        $this->assertEquals(100000, (float) $order->remaining_amount);
        $this->assertSame('partially_paid', $order->payment_status);

        $additionalPaymentId = $itemA->fresh()->additional_payment_id;
        $this->actingAs($keuangan)->patchJson("/api/v1/admin/additional-payments/{$additionalPaymentId}/status", ['paid' => true])->assertOk();

        // Once actually marked paid, the 100k folds into paid_amount and the order reaches PAID again.
        $order = $order->fresh();
        $this->assertEquals(600000, (float) $order->paid_amount);
        $this->assertEquals(0, (float) $order->remaining_amount);
        $this->assertSame('paid', $order->payment_status);

        // Re-marking the same (already 'paid') additional payment is rejected, never double-applies the 100k again.
        $this->actingAs($keuangan)->patchJson("/api/v1/admin/additional-payments/{$additionalPaymentId}/status", ['paid' => true])->assertStatus(422);
        $this->assertEquals(600000, (float) $order->fresh()->paid_amount);
    }

    /** Processing a refund is real money leaving — paid_amount must actually decrease, not just flip a status label. */
    public function test_processing_a_refund_decreases_paid_amount(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'keuangan' => $keuangan, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $this->configureBankTransfer($agen);
        $productA = $this->makeProduct($agen, 'Produk A', 100000, 10);
        ['order' => $order, 'item' => $itemA] = $this->placeSingleItemOrder($konsumen, $productA, 5, 'bank_transfer');
        $order = $this->payInFull($order);
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$itemA->id}/fulfillment", [
            'fulfilled_quantity' => 4, 'reason' => 'Kurangi',
        ])->assertOk();

        $adjustmentId = \App\Models\OrderItemAdjustment::where('order_item_id', $itemA->id)->value('id');
        $this->assertEquals(500000, (float) $order->fresh()->paid_amount, 'still the original paid amount before processing below');

        $this->actingAs($keuangan)->patchJson("/api/v1/admin/order-refunds/{$adjustmentId}/status", ['refund_status' => 'processed'])->assertOk();

        $order = $order->fresh();
        $this->assertEquals(400000, (float) $order->paid_amount, 'the 100k refund must actually leave paid_amount once processed');
        $this->assertEquals(0, (float) $order->remaining_amount);

        // Re-processing an already-processed refund is rejected, never double-decrements paid_amount again.
        $this->actingAs($keuangan)->patchJson("/api/v1/admin/order-refunds/{$adjustmentId}/status", ['refund_status' => 'processed'])->assertStatus(422);
        $this->assertEquals(400000, (float) $order->fresh()->paid_amount);
    }

    /* ---------------------------------------------------------------
     * Regression: courier assignment / multi-courier untouched by adjustment
     * ------------------------------------------------------------- */

    public function test_order_adjustment_preserves_courier_item_assignments(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $kurirBudi = User::factory()->kurir()->create(['agent_id' => $agen->id, 'name' => 'Budi Kurir']);
        Courier::create(['type' => 'internal', 'user_id' => $kurirBudi->id, 'agent_id' => $agen->id, 'name' => 'Budi Kurir', 'is_active' => true]);
        $kurirAndi = User::factory()->kurir()->create(['agent_id' => $agen->id, 'name' => 'Andi Kurir']);
        Courier::create(['type' => 'internal', 'user_id' => $kurirAndi->id, 'agent_id' => $agen->id, 'name' => 'Andi Kurir', 'is_active' => true]);

        $productA = $this->makeProduct($agen, 'Produk A', 100000, 10);
        $productB = $this->makeProduct($agen, 'Produk B', 50000, 10);
        ['order' => $order, 'itemA' => $itemA, 'itemB' => $itemB] = $this->placeTwoProductOrder($agen, $konsumen, $productA, 5, $productB, 3);

        $courierBudiId = Courier::where('user_id', $kurirBudi->id)->value('id');
        $courierAndiId = Courier::where('user_id', $kurirAndi->id)->value('id');
        $this->actingAs($admin)->patchJson("/api/v1/shipments/{$itemA->shipment_id}/courier", ['courier_id' => $courierBudiId])->assertOk();
        $this->actingAs($admin)->patchJson("/api/v1/shipments/{$itemB->shipment_id}/courier", ['courier_id' => $courierAndiId])->assertOk();

        // Adjust item A only.
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$itemA->id}/fulfillment", [
            'fulfilled_quantity' => 4, 'reason' => 'Kurangi A',
        ])->assertOk();

        // Item B's own shipment/courier assignment is completely untouched.
        $itemB->refresh();
        $this->assertSame($courierAndiId, \App\Models\Shipment::find($itemB->shipment_id)->courier_id);
        $this->assertNotSame($itemA->fresh()->shipment_id, $itemB->shipment_id);
    }
}
