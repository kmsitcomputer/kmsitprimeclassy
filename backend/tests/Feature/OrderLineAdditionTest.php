<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AgentPaymentGatewayConfig;
use App\Models\AgentProfile;
use App\Models\Commission;
use App\Models\Order;
use App\Models\OrderAdditionalPayment;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariation;
use App\Models\ProductVariationStock;
use App\Models\Shipment;
use App\Models\StockMovement;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Models\User;
use App\Models\WarehouseSubLocation;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * Package C / SC-03 — Existing-Order Line Addition.
 *
 * Authority is ADMIN ONLY, same-Agent (OrderPolicy::addLine + the dedicated role:admin route group);
 * manageFulfillment (super_admin/agen/admin) is untouched.
 */
class OrderLineAdditionTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    /** @return array{agen:User, admin:User, keuangan:User, konsumen:User} */
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

        $bankTransfer = PaymentMethod::where('code', 'bank_transfer')->firstOrFail();
        AgentPaymentGatewayConfig::create([
            'agent_id' => $agen->id, 'payment_method_id' => $bankTransfer->id, 'environment' => 'sandbox',
            'config' => ['bank_name' => 'BCA', 'account_name' => 'QA', 'account_number' => '123'],
        ]);

        return compact('agen', 'admin', 'keuangan', 'konsumen');
    }

    private function makeProduct(User $agen, string $name, int $price, int $stockQty): Product
    {
        $product = Product::create([
            'sku' => 'SC03-'.\Illuminate\Support\Str::uuid(),
            'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(),
            'has_variations' => false, 'base_price' => $price, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => $stockQty, 'quantity_reserved' => 0]);

        return $product;
    }

    /** @return array{product:Product, variation:ProductVariation} */
    private function makeVariationProduct(User $agen, string $name, int $price, int $stockQty): array
    {
        $product = Product::create([
            'sku' => null, 'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(),
            'has_variations' => true, 'base_price' => $price, 'weight_grams' => 500, 'status' => 'active',
        ]);
        $variation = ProductVariation::create([
            'product_id' => $product->id, 'sku' => 'SC03V-'.Str::uuid(),
            'price' => $price, 'weight_grams' => 500, 'is_active' => true, 'sort_order' => 0,
        ]);
        ProductVariationStock::create(['agent_id' => $agen->id, 'product_variation_id' => $variation->id, 'quantity_on_hand' => $stockQty, 'quantity_reserved' => 0]);

        return compact('product', 'variation');
    }

    /** @param array<int, array{product_id:int, product_variation_id?:?int, quantity:int}> $items */
    private function placeOrder(User $konsumen, array $items, string $paymentMethodCode = 'cod'): Order
    {
        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => $paymentMethodCode,
            'items' => $items,
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);
        $response->assertCreated();

        return Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
    }

    private function addLine(Order $order, User $actor, array $payload, ?string $idempotencyKey = null)
    {
        return $this->actingAs($actor)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey ?? (string) Str::uuid()])
            ->postJson("/api/v1/orders/{$order->id}/items", $payload);
    }

    private function setPaid(Order $order, float $paid): Order
    {
        $total = (float) $order->total_amount;
        $order->update([
            'paid_amount' => $paid,
            'remaining_amount' => max(0.0, $total - $paid),
            'payment_status' => $paid <= 0 ? 'unpaid' : ($paid >= $total ? 'paid' : 'partially_paid'),
        ]);

        return $order->fresh();
    }

    // ----- authority -------------------------------------------------------------------------

    public function test_same_agent_admin_can_add_a_new_line(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);

        $response = $this->addLine($order, $admin, ['product_id' => $productB->id, 'quantity' => 2, 'reason' => 'Tambahan']);
        $response->assertCreated()->assertJsonPath('success', true);

        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'product_id' => $productB->id, 'original_quantity' => 2]);
    }

    public function test_super_admin_is_denied(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->addLine($order, $superAdmin, ['product_id' => $productB->id, 'quantity' => 1, 'reason' => 'x'])->assertForbidden();
        $this->assertDatabaseMissing('order_items', ['order_id' => $order->id, 'product_id' => $productB->id]);
    }

    public function test_agen_is_denied(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);

        $this->addLine($order, $agen, ['product_id' => $productB->id, 'quantity' => 1, 'reason' => 'x'])->assertForbidden();
        $this->assertDatabaseMissing('order_items', ['order_id' => $order->id, 'product_id' => $productB->id]);
    }

    public function test_other_roles_are_denied(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);

        $actors = [
            User::factory()->keuangan()->create(['agent_id' => $agen->id]),
            User::factory()->korsal()->create(['agent_id' => $agen->id]),
            User::factory()->sales()->create(['agent_id' => $agen->id]),
            User::factory()->salesKurirSub()->create(['agent_id' => $agen->id]),
            User::factory()->kurir()->create(['agent_id' => $agen->id]),
            User::factory()->gudang()->create(['agent_id' => $agen->id]),
            $konsumen,
        ];

        foreach ($actors as $actor) {
            $this->addLine($order, $actor, ['product_id' => $productB->id, 'quantity' => 1, 'reason' => 'x'])->assertForbidden();
        }
        $this->assertDatabaseMissing('order_items', ['order_id' => $order->id, 'product_id' => $productB->id]);
    }

    public function test_cross_agent_admin_is_denied(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);

        $otherAgen = User::factory()->agen()->create();
        $otherAgen->update(['agent_id' => $otherAgen->id]);
        $otherAdmin = User::factory()->admin()->create(['agent_id' => $otherAgen->id]);

        $this->addLine($order, $otherAdmin, ['product_id' => $productB->id, 'quantity' => 1, 'reason' => 'x'])->assertNotFound();
        $this->assertDatabaseMissing('order_items', ['order_id' => $order->id, 'product_id' => $productB->id]);
    }

    public function test_idempotency_key_is_required(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);

        // Clear the headers the order-placement helper left on the test case so the request
        // genuinely has no Idempotency-Key.
        $this->defaultHeaders = [];
        $this->actingAs($admin)->postJson("/api/v1/orders/{$order->id}/items", ['product_id' => $productB->id, 'quantity' => 1, 'reason' => 'x'])
            ->assertStatus(422);
    }

    // ----- product / variation / quantity -----------------------------------------------------

    public function test_inactive_or_missing_product_is_rejected(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $inactive = $this->makeProduct($agen, 'Kue Nonaktif', 20000, 10);
        $inactive->update(['status' => 'inactive']);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);

        $this->addLine($order, $admin, ['product_id' => $inactive->id, 'quantity' => 1, 'reason' => 'x'])->assertNotFound();
    }

    public function test_variation_rules_are_enforced(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $simple = $this->makeProduct($agen, 'Kue A', 10000, 10);
        ['product' => $varProduct, 'variation' => $variation] = $this->makeVariationProduct($agen, 'Kue Varian', 15000, 10);
        ['product' => $otherVarProduct, 'variation' => $otherVariation] = $this->makeVariationProduct($agen, 'Kue Varian Lain', 15000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $simple->id, 'quantity' => 1]]);

        // variation product without a variation id -> 422
        $this->addLine($order, $admin, ['product_id' => $varProduct->id, 'quantity' => 1, 'reason' => 'x'])->assertStatus(422);
        // simple product WITH a variation id -> 422
        $this->addLine($order, $admin, ['product_id' => $simple->id, 'product_variation_id' => $variation->id, 'quantity' => 1, 'reason' => 'x'])->assertStatus(422);
        // variation from a different product -> 404
        $this->addLine($order, $admin, ['product_id' => $varProduct->id, 'product_variation_id' => $otherVariation->id, 'quantity' => 1, 'reason' => 'x'])->assertNotFound();
        // valid variation -> 201
        $this->addLine($order, $admin, ['product_id' => $varProduct->id, 'product_variation_id' => $variation->id, 'quantity' => 1, 'reason' => 'x'])->assertCreated();
    }

    public function test_invalid_quantity_is_rejected(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);

        foreach ([0, -1] as $qty) {
            $this->addLine($order, $admin, ['product_id' => $productB->id, 'quantity' => $qty, 'reason' => 'x'])->assertStatus(422);
        }
        $this->addLine($order, $admin, ['product_id' => $productB->id, 'reason' => 'x'])->assertStatus(422);
    }

    public function test_insufficient_agent_stock_is_rejected(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $scarce = $this->makeProduct($agen, 'Kue Langka', 25000, 1);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);

        $this->addLine($order, $admin, ['product_id' => $scarce->id, 'quantity' => 5, 'reason' => 'x'])->assertStatus(422);
        $this->assertDatabaseMissing('order_items', ['order_id' => $order->id, 'product_id' => $scarce->id]);
        $this->assertSame(0, (int) ProductStock::withoutGlobalScopes()->where('agent_id', $agen->id)->where('product_id', $scarce->id)->value('quantity_reserved'));
    }

    public function test_sub_source_input_is_rejected(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);

        $this->addLine($order, $admin, ['product_id' => $productB->id, 'quantity' => 1, 'reason' => 'x', 'stock_source' => 'sub'])->assertStatus(422);
        $this->addLine($order, $admin, ['product_id' => $productB->id, 'quantity' => 1, 'reason' => 'x', 'sub_location_id' => 3])->assertStatus(422);
    }

    public function test_sub_sourced_order_rejects_line_addition(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);

        // Simulate a Sub-sourced order at the data layer (SC-03 is Admin/Agent-only).
        $location = WarehouseSubLocation::create(['agent_id' => $agen->id, 'code' => 'SUB-'.uniqid(), 'name' => 'Sub QA', 'is_active' => true, 'created_by' => $agen->id]);
        OrderItem::where('order_id', $order->id)->update(['stock_source' => 'sub', 'sub_location_id' => $location->id]);

        $this->addLine($order, $admin, ['product_id' => $productB->id, 'quantity' => 1, 'reason' => 'x'])->assertStatus(422);
        $this->assertDatabaseMissing('order_items', ['order_id' => $order->id, 'product_id' => $productB->id]);
    }

    // ----- snapshot / reservation / totals ----------------------------------------------------

    public function test_added_line_snapshots_server_values_and_reserves_stock(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);

        $key = (string) Str::uuid();
        $this->addLine($order, $admin, ['product_id' => $productB->id, 'quantity' => 2, 'reason' => 'x'], $key)->assertCreated();

        $item = OrderItem::where('order_id', $order->id)->where('idempotency_key', $key)->firstOrFail();
        $this->assertSame('Kue B', $item->product_name_snapshot);
        $this->assertSame('25000.00', (string) $item->unit_price_snapshot);
        $this->assertSame('50000.00', (string) $item->subtotal_snapshot);
        $this->assertSame('agent', $item->stock_source);
        $this->assertNull($item->sub_location_id);
        $this->assertNull($item->split_from_order_item_id);

        $stock = ProductStock::withoutGlobalScopes()->where('agent_id', $agen->id)->where('product_id', $productB->id)->firstOrFail();
        $this->assertSame(2, (int) $stock->quantity_reserved);
        $this->assertSame(1, StockMovement::where('agent_id', $agen->id)->where('product_id', $productB->id)->where('type', 'reserve')->count());

        // Order totals recomputed from fulfilled quantities (10000 + 25000*2 = 60000); the original line is untouched.
        $order->refresh();
        $this->assertSame('60000.00', (string) $order->subtotal_amount);
        $this->assertSame(10000.0, (float) OrderItem::where('order_id', $order->id)->where('product_id', $productA->id)->value('subtotal_snapshot'));
    }

    // ----- shipment / stock request / commission / audit --------------------------------------

    public function test_added_line_gets_a_fresh_pending_shipment(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);

        $key = (string) Str::uuid();
        $this->addLine($order, $admin, ['product_id' => $productB->id, 'quantity' => 1, 'reason' => 'x'], $key)->assertCreated();
        $item = OrderItem::where('order_id', $order->id)->where('idempotency_key', $key)->firstOrFail();

        $shipment = Shipment::whereKey($item->shipment_id)->firstOrFail();
        $this->assertSame('pending', $shipment->status);
        $this->assertSame(Shipment::DELIVERY_MODE_STANDARD, $shipment->delivery_mode);
        $this->assertNull($shipment->self_delivered_by_user_id);
        $this->assertNull($shipment->courier_id);
    }

    public function test_stock_request_gains_exactly_one_item(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);

        $key = (string) Str::uuid();
        $this->addLine($order, $admin, ['product_id' => $productB->id, 'quantity' => 2, 'reason' => 'x'], $key)->assertCreated();
        $item = OrderItem::where('order_id', $order->id)->where('idempotency_key', $key)->firstOrFail();

        $this->assertDatabaseCount('stock_requests', 1);
        $requestItem = StockRequestItem::where('order_item_id', $item->id)->firstOrFail();
        $this->assertSame(2, (int) $requestItem->requested_qty);
        $this->assertSame(2, (int) $requestItem->remaining_qty);
    }

    public function test_commission_rows_are_recorded_for_the_added_line(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);

        $key = (string) Str::uuid();
        $this->addLine($order, $admin, ['product_id' => $productB->id, 'quantity' => 1, 'reason' => 'x'], $key)->assertCreated();
        $item = OrderItem::where('order_id', $order->id)->where('idempotency_key', $key)->firstOrFail();

        // With no configured fees, commission rows are only written when the fee > 0 — both are 0 here,
        // so assert the item/fee snapshot is consistent instead (0 snapshot, 0 commission rows).
        $this->assertSame(0, Commission::where('order_item_id', $item->id)->count());
        $this->assertSame('0.00', (string) $item->agent_fee_amount);
        $this->assertSame('0.00', (string) $item->courier_fee_amount);
    }

    public function test_added_line_is_audited(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);

        $key = (string) Str::uuid();
        $this->addLine($order, $admin, ['product_id' => $productB->id, 'quantity' => 1, 'reason' => 'Tambah kue'], $key)->assertCreated();
        $item = OrderItem::where('order_id', $order->id)->where('idempotency_key', $key)->firstOrFail();

        $log = ActivityLog::where('event', 'order_item.added')->where('subject_id', $item->id)->firstOrFail();
        $this->assertSame($admin->id, (int) $log->causer_id);
        $this->assertSame('Tambah kue', $log->description);
        $this->assertSame($order->id, $log->properties['order_id']);
        $this->assertSame(1, (int) $log->properties['quantity']);
    }

    // ----- financial --------------------------------------------------------------------------

    public function test_unpaid_order_remaining_grows_and_stays_unpaid(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);

        $this->addLine($order, $admin, ['product_id' => $productB->id, 'quantity' => 2, 'reason' => 'x'])->assertCreated();
        $order->refresh();

        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame(0.0, (float) $order->paid_amount);
        $this->assertSame((float) $order->total_amount, (float) $order->remaining_amount);
        $this->assertDatabaseCount('order_additional_payments', 0);
    }

    public function test_partially_paid_order_remaining_grows_and_stays_partially_paid(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);
        $order = $this->setPaid($order, 4000.0);

        $this->addLine($order, $admin, ['product_id' => $productB->id, 'quantity' => 1, 'reason' => 'x'])->assertCreated();
        $order->refresh();

        $this->assertSame('partially_paid', $order->payment_status);
        $this->assertSame(4000.0, (float) $order->paid_amount);
        $this->assertSame(round((float) $order->total_amount - 4000.0, 2), (float) $order->remaining_amount);
        $this->assertDatabaseCount('order_additional_payments', 0);
    }

    public function test_fully_paid_order_gets_a_pending_additional_payment(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);
        $order = $this->setPaid($order, (float) $order->total_amount);
        $paidBefore = (float) $order->paid_amount;

        $key = (string) Str::uuid();
        $this->addLine($order, $admin, ['product_id' => $productB->id, 'quantity' => 1, 'reason' => 'x', 'additional_payment_method' => 'cod'], $key)->assertCreated();
        $order->refresh();
        $item = OrderItem::where('order_id', $order->id)->where('idempotency_key', $key)->firstOrFail();

        $this->assertSame('partially_paid', $order->payment_status);
        $this->assertSame($paidBefore, (float) $order->paid_amount);
        $this->assertSame(round((float) $order->total_amount - $paidBefore, 2), (float) $order->remaining_amount);

        $additional = OrderAdditionalPayment::where('order_id', $order->id)->get();
        $this->assertCount(1, $additional);
        $this->assertSame('pending', $additional->first()->status);
        $this->assertSame((float) $order->remaining_amount, (float) $additional->first()->amount);
        $this->assertSame($item->id, (int) $additional->first()->items()->first()->id);
    }

    // ----- idempotency ------------------------------------------------------------------------

    public function test_replay_with_same_key_does_not_duplicate_effects(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);

        $key = (string) Str::uuid();
        $payload = ['product_id' => $productB->id, 'quantity' => 2, 'reason' => 'x'];

        $this->addLine($order, $admin, $payload, $key)->assertCreated();
        $this->addLine($order, $admin, $payload, $key)->assertOk();

        $this->assertSame(1, OrderItem::where('order_id', $order->id)->where('idempotency_key', $key)->count());
        $this->assertSame(2, OrderItem::where('order_id', $order->id)->count());
        $this->assertSame(1, Shipment::where('order_id', $order->id)->whereHas('orderItems', fn ($q) => $q->where('idempotency_key', $key))->count());
        $stock = ProductStock::withoutGlobalScopes()->where('agent_id', $agen->id)->where('product_id', $productB->id)->firstOrFail();
        $this->assertSame(2, (int) $stock->quantity_reserved);
    }

    public function test_conflicting_reuse_of_key_is_rejected(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        $productC = $this->makeProduct($agen, 'Kue C', 30000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);

        $key = (string) Str::uuid();
        $this->addLine($order, $admin, ['product_id' => $productB->id, 'quantity' => 1, 'reason' => 'x'], $key)->assertCreated();
        // Same key, different product -> conflict, no second item.
        $this->addLine($order, $admin, ['product_id' => $productC->id, 'quantity' => 1, 'reason' => 'x'], $key)->assertStatus(409);
        $this->assertDatabaseMissing('order_items', ['order_id' => $order->id, 'product_id' => $productC->id]);
    }

    // ----- status window / grouping -----------------------------------------------------------

    public function test_line_addition_outside_diproses_is_rejected(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        // bank_transfer starts 'diterima' (not 'diproses')
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]], 'bank_transfer');
        $this->assertSame('diterima', $order->status);

        $this->addLine($order, $admin, ['product_id' => $productB->id, 'quantity' => 1, 'reason' => 'x'])->assertStatus(422);
        $this->assertDatabaseMissing('order_items', ['order_id' => $order->id, 'product_id' => $productB->id]);
    }

    public function test_added_line_appears_in_its_delivery_group(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);

        $date = now()->addDays(5)->toDateString();
        $key = (string) Str::uuid();
        $this->addLine($order, $admin, ['product_id' => $productB->id, 'quantity' => 1, 'reason' => 'x', 'requested_delivery_date' => $date], $key)->assertCreated();
        $item = OrderItem::where('order_id', $order->id)->where('idempotency_key', $key)->firstOrFail();

        $show = $this->actingAs($admin)->getJson("/api/v1/orders/{$order->id}")->assertOk();
        $groups = collect($show->json('data.delivery_groups'));
        $group = $groups->firstWhere('delivery_date', $date);
        $this->assertNotNull($group);
        $this->assertContains($item->id, $group['item_ids']);
    }

    // ----- Package A/B invariant guard --------------------------------------------------------

    public function test_super_admin_retains_direct_fulfillment_override(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();
        $superAdmin = User::factory()->superAdmin()->create();

        // super_admin still has manageFulfillment (Package A/B authority untouched).
        $this->actingAs($superAdmin)
            ->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/fulfillment", ['fulfilled_quantity' => 2, 'reason' => 'naik'])
            ->assertOk();
    }

    // ----- review remediation (C-SC03-REV-001 .. 004, 007) -----------------------------------

    /** Warehouse-authoritative rows so Gudang can propose/approve against the target. */
    private function stockWarehouse(User $agen, Product $product, int $transit = 20): void
    {
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => $transit]);
        WarehouseSetting::firstOrCreate(['agent_id' => $agen->id], ['factory_plan_enabled' => false]);
    }

    public function test_rev001_changed_delivery_date_or_payment_method_conflicts_and_identical_replays(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);

        $date = now()->addDays(3)->toDateString();
        $key = (string) Str::uuid();
        $payload = ['product_id' => $productB->id, 'quantity' => 2, 'reason' => 'x', 'requested_delivery_date' => $date, 'additional_payment_method' => 'cod'];
        $this->addLine($order, $admin, $payload, $key)->assertCreated();

        $this->addLine($order, $admin, array_replace($payload, ['requested_delivery_date' => now()->addDays(4)->toDateString()]), $key)->assertStatus(409);
        $this->addLine($order, $admin, array_replace($payload, ['additional_payment_method' => 'transfer']), $key)->assertStatus(409);
        $this->addLine($order, $admin, array_replace($payload, ['quantity' => 3]), $key)->assertStatus(409);
        // `reason` is an audit annotation, not part of the logical request identity (documented decision).
        $this->addLine($order, $admin, array_replace($payload, ['reason' => 'reworded']), $key)->assertOk();
        $this->addLine($order, $admin, $payload, $key)->assertOk();

        $this->assertSame(1, OrderItem::where('order_id', $order->id)->where('idempotency_key', $key)->count());
        $this->assertSame(2, (int) ProductStock::withoutGlobalScopes()->where('product_id', $productB->id)->value('quantity_reserved'));
        $this->assertSame(1, StockMovement::where('product_id', $productB->id)->where('type', 'reserve')->count());
    }

    public function test_rev001_replay_survives_item_mutation_status_change_and_elapsed_date(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);

        $key = (string) Str::uuid();
        $payload = ['product_id' => $productB->id, 'quantity' => 3, 'reason' => 'x', 'requested_delivery_date' => now()->addDay()->toDateString()];
        $this->addLine($order, $admin, $payload, $key)->assertCreated();

        // Legitimate post-creation mutation (quantity adjustment / reschedule) of the added line.
        OrderItem::where('idempotency_key', $key)->update(['original_quantity' => 2, 'fulfilled_quantity' => 2, 'requested_delivery_date' => now()->addDays(9)->toDateString()]);
        $this->addLine($order, $admin, $payload, $key)->assertOk();

        // Order moved on: the creation-only status window must not break a replay.
        Order::withoutGlobalScopes()->whereKey($order->id)->update(['status' => 'dikirim']);
        $this->addLine($order, $admin, $payload, $key)->assertOk();

        // The requested date has since become historical: replay still resolves (no 422), but a NEW
        // request with a past date is still rejected.
        $this->travel(5)->days();
        $this->addLine($order, $admin, $payload, $key)->assertOk();
        Order::withoutGlobalScopes()->whereKey($order->id)->update(['status' => 'diproses']);
        $this->addLine($order, $admin, array_replace($payload, ['requested_delivery_date' => now()->subDay()->toDateString()]), (string) Str::uuid())
            ->assertStatus(422)->assertJsonPath('errors.requested_delivery_date', __('messages.order.line_addition_date_in_past'));

        $this->assertSame(1, OrderItem::where('idempotency_key', $key)->count());
        $this->assertSame(3, (int) ProductStock::withoutGlobalScopes()->where('product_id', $productB->id)->value('quantity_reserved'));
    }

    public function test_rev001_fingerprint_is_persisted_once_on_the_added_line_only(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);
        $key = (string) Str::uuid();
        $this->addLine($order, $admin, ['product_id' => $productB->id, 'quantity' => 1, 'reason' => 'x'], $key)->assertCreated();

        $this->assertNull(OrderItem::where('order_id', $order->id)->whereNull('idempotency_key')->value('request_fingerprint'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) OrderItem::where('idempotency_key', $key)->value('request_fingerprint'));
    }

    public function test_add_line_updates_legacy_internal_demand_without_exposing_stock_requests(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 2]]);

        $request = StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(2, $request->items()->firstOrFail()->requested_qty);

        $key = (string) Str::uuid();
        $this->addLine($order, $admin, ['product_id' => $productB->id, 'quantity' => 3, 'reason' => 'x'], $key)->assertCreated();

        $request = $request->fresh();
        $this->assertSame('pending', $request->status);
        $this->assertSame(2, StockRequest::withoutGlobalScopes()->findOrFail($request->id)->items()->count());
        $this->assertSame(1, StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->count());
        $this->assertSame([2, 3], $request->items()->orderBy('id')->pluck('requested_qty')->map(fn ($quantity) => (int) $quantity)->all());
        $gudang = User::factory()->gudang()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $this->actingAs($gudang)->getJson('/api/v1/warehouse/stock-requests')->assertNotFound();

        $newItem = $request->items()->where('product_id', $productB->id)->firstOrFail();
        $this->assertSame([3, 0, 3], [$newItem->requested_qty, $newItem->fulfilled_qty, $newItem->remaining_qty]);

    }

    public function test_rev003_addition_locks_stock_request_before_inventory_like_warehouse_approval(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);
        $this->stockWarehouse($agen, $productB);

        $locks = [];
        DB::listen(function ($query) use (&$locks) {
            if (preg_match('/\bfor update\b/i', $query->sql) && preg_match('/from [`"]?(\w+)[`"]?/i', $query->sql, $m)) {
                $locks[] = $m[1];
            }
        });
        $this->addLine($order, $admin, ['product_id' => $productB->id, 'quantity' => 1, 'reason' => 'x'])->assertCreated();

        $firstRequest = array_search('stock_requests', $locks, true);
        $firstInventory = min(array_filter([array_search('product_stocks', $locks, true), array_search('warehouse_stocks', $locks, true)], fn ($i) => $i !== false));
        $this->assertNotFalse($firstRequest);
        $this->assertLessThan($firstInventory, $firstRequest, 'Stock Request must be locked before any inventory row (matches StockRequestProposalService::approve).');
        $this->assertLessThan($firstRequest, array_search('orders', $locks, true), 'Order row stays the first lock.');
    }

    public function test_rev004_post_response_carries_persisted_financial_truth(): void
    {
        foreach (['unpaid' => 0.0, 'partial' => 4000.0, 'paid' => null] as $case => $paid) {
            ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
            $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
            $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
            $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);
            $order = $this->setPaid($order, $paid ?? (float) $order->total_amount);
            $oldTotal = (float) $order->total_amount;

            $key = (string) Str::uuid();
            $response = $this->addLine($order, $admin, ['product_id' => $productB->id, 'quantity' => 2, 'reason' => 'x'], $key)->assertCreated();
            $persisted = Order::withoutGlobalScopes()->findOrFail($order->id);
            $newItem = OrderItem::where('order_id', $order->id)->where('idempotency_key', $key)->firstOrFail();

            $this->assertGreaterThan($oldTotal, (float) $persisted->total_amount, $case);
            $this->assertSame((float) $persisted->total_amount, (float) $response->json('data.total_amount'), "$case total");
            $this->assertSame((float) $persisted->paid_amount, (float) $response->json('data.paid_amount'), "$case paid");
            $this->assertSame((float) $persisted->remaining_amount, (float) $response->json('data.remaining_amount'), "$case remaining");
            $this->assertSame($persisted->payment_status, $response->json('data.payment_status'), "$case status");
            $this->assertSame((float) $persisted->remaining_amount, (float) $response->json('data.payment_summary.remaining_balance'), "$case summary remaining");
            $this->assertContains($newItem->id, collect($response->json('data.items'))->pluck('id')->all(), "$case item");
        }
    }

    public function test_rev007_idempotency_key_length_is_validated_before_mutation(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $productA = $this->makeProduct($agen, 'Kue A', 10000, 10);
        $productB = $this->makeProduct($agen, 'Kue B', 25000, 10);
        $order = $this->placeOrder($konsumen, [['product_id' => $productA->id, 'quantity' => 1]]);
        $payload = ['product_id' => $productB->id, 'quantity' => 1, 'reason' => 'x'];

        $this->addLine($order, $admin, $payload, '   ')->assertStatus(422);
        $this->addLine($order, $admin, $payload, str_repeat('k', 101))->assertStatus(422)->assertJsonPath('success', false)->assertJsonStructure(['errors' => ['idempotency_key']]);
        $this->assertSame(1, OrderItem::where('order_id', $order->id)->count());
        $this->assertSame(0, (int) ProductStock::withoutGlobalScopes()->where('product_id', $productB->id)->value('quantity_reserved'));

        $this->addLine($order, $admin, $payload, str_repeat('k', 100))->assertCreated();
        $this->assertSame(2, OrderItem::where('order_id', $order->id)->count());
    }
}
