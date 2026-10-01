<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderAdditionalPayment;
use App\Models\OrderItem;
use App\Models\OrderItemAdjustment;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ShippingConfiguration;
use App\Models\SubStockReservation;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use App\Services\Order\OrderFulfillmentService;
use App\Services\Stock\SubStockService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * R-03 / MAJOR-7: an operational quantity edit must not create contradictory outstanding financial
 * obligations. A pending fulfillment-refund blocks an increase; a pending additional payment blocks
 * a reduction. Once the obligation is terminal, existing payment rules apply again.
 */
class FinancialObligationReconciliationTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);

        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Toko', 'address' => 'Jl. X', 'latitude' => -6.2, 'longitude' => 106.8]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        $sub = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id]);
        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true]);

        $product = Product::create(['sku' => 'FIN-'.Str::uuid(), 'name' => 'Financial Cake', 'slug' => 'financial-'.uniqid(), 'has_variations' => false, 'base_price' => 10000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 20, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 30]);
        $location = WarehouseSubLocation::create(['agent_id' => $agen->id, 'code' => 'FIN1', 'name' => 'FIN1', 'created_by' => $agen->id]);
        $location->forceFill(['owner_user_id' => $sub->id])->save();
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $location->id, 'quantity' => 20]);

        $this->b = compact('agen', 'admin', 'konsumen', 'sub', 'location', 'product');
    }

    private function placeAgentItem(int $qty): OrderItem
    {
        $id = $this->actingAs($this->b['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $this->b['product']->id, 'quantity' => $qty]],
                'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated()->json('data.id');

        return OrderItem::where('order_id', $id)->firstOrFail();
    }

    private function placeSubItem(int $qty): OrderItem
    {
        $id = $this->actingAs($this->b['sub'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod', 'stock_source' => 'sub',
                'items' => [['product_id' => $this->b['product']->id, 'quantity' => $qty]],
                'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated()->json('data.id');

        return OrderItem::where('order_id', $id)->firstOrFail();
    }

    private function payInFull(OrderItem $item): Order
    {
        $order = Order::withoutGlobalScopes()->findOrFail($item->order_id);
        $order->update(['payment_status' => 'paid', 'paid_amount' => $order->total_amount, 'remaining_amount' => 0]);

        return $order->fresh();
    }

    private function assertRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected the adjustment to be rejected with 422.');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status());
        }
    }

    public function test_agent_increase_is_blocked_while_a_fulfillment_refund_is_pending(): void
    {
        $item = $this->placeAgentItem(3);
        $this->payInFull($item);

        app(OrderFulfillmentService::class)->adjustItemQuantity($item, 2, $this->b['admin'], 'kurang');

        $adjustment = OrderItemAdjustment::query()->where('order_item_id', $item->id)->firstOrFail();
        $this->assertSame('pending', $adjustment->refund_status);

        $item->refresh();
        $order = Order::withoutGlobalScopes()->findOrFail($item->order_id);
        $totalAfterReduce = (float) $order->total_amount;
        $reservedAfterReduce = (int) ProductStock::withoutGlobalScopes()->where('agent_id', $this->b['agen']->id)->value('quantity_reserved');

        $this->assertRejected(fn () => app(OrderFulfillmentService::class)->adjustItemQuantity($item, 3, $this->b['admin'], 'restore'));

        $item->refresh();
        $this->assertSame(2, $item->fulfilled_quantity);
        $this->assertSame(1, $item->cancelled_quantity);
        $this->assertSame($totalAfterReduce, (float) Order::withoutGlobalScopes()->findOrFail($item->order_id)->total_amount);
        $this->assertSame($reservedAfterReduce, (int) ProductStock::withoutGlobalScopes()->where('agent_id', $this->b['agen']->id)->value('quantity_reserved'));
        $this->assertSame(1, OrderItemAdjustment::query()->where('order_item_id', $item->id)->count());

        // Once the refund reaches a terminal state, normal payment rules apply again.
        $adjustment->update(['refund_status' => 'processed']);
        app(OrderFulfillmentService::class)->adjustItemQuantity($item, 3, $this->b['admin'], 'restore');
        $item->refresh();
        $this->assertSame(3, $item->fulfilled_quantity);
        $this->assertSame(0, $item->cancelled_quantity);
    }

    public function test_agent_reduction_is_blocked_while_an_additional_payment_is_pending(): void
    {
        $item = $this->placeAgentItem(3);
        $this->payInFull($item);

        // Fully settled order, increase -> pending additional-payment obligation (COD method needs no gateway config).
        app(OrderFulfillmentService::class)->adjustItemQuantity($item, 4, $this->b['admin'], 'lebih', 'cod');
        $payment = OrderAdditionalPayment::query()->latest('id')->firstOrFail();
        $this->assertSame('pending', $payment->status);

        $item->refresh();
        $totalAfterIncrease = (float) Order::withoutGlobalScopes()->findOrFail($item->order_id)->total_amount;
        $reservedAfterIncrease = (int) ProductStock::withoutGlobalScopes()->where('agent_id', $this->b['agen']->id)->value('quantity_reserved');

        $this->assertRejected(fn () => app(OrderFulfillmentService::class)->adjustItemQuantity($item, 3, $this->b['admin'], 'kembalikan'));

        $item->refresh();
        $this->assertSame(4, $item->fulfilled_quantity);
        $this->assertSame($totalAfterIncrease, (float) Order::withoutGlobalScopes()->findOrFail($item->order_id)->total_amount);
        $this->assertSame($reservedAfterIncrease, (int) ProductStock::withoutGlobalScopes()->where('agent_id', $this->b['agen']->id)->value('quantity_reserved'));

        // Terminal (failed) obligation -> reduction allowed again.
        $payment->update(['status' => 'failed']);
        app(OrderFulfillmentService::class)->adjustItemQuantity($item, 3, $this->b['admin'], 'kembalikan');
        $this->assertSame(3, $item->fresh()->fulfilled_quantity);
    }

    public function test_sub_increase_is_blocked_while_a_fulfillment_refund_is_pending(): void
    {
        $item = $this->placeSubItem(3);
        $this->payInFull($item);

        app(OrderFulfillmentService::class)->adjustItemQuantity($item, 2, $this->b['admin'], 'kurang');
        $adjustment = OrderItemAdjustment::query()->where('order_item_id', $item->id)->firstOrFail();
        $this->assertSame('pending', $adjustment->refund_status);

        $item->refresh();
        $reservedAfterReduce = (int) SubStockReservation::query()->where('order_item_id', $item->id)->value('quantity');

        $this->assertRejected(fn () => app(OrderFulfillmentService::class)->adjustItemQuantity($item, 3, $this->b['admin'], 'restore'));

        $item->refresh();
        $this->assertSame(2, $item->fulfilled_quantity);
        $this->assertSame(2, (int) SubStockReservation::query()->where('order_item_id', $item->id)->value('quantity'));
        $this->assertSame(2, $reservedAfterReduce);
        $this->assertSame(20, app(SubStockService::class)->physical($this->b['location']->id, $this->b['product']->id, null));
    }

    /* ---------------- MAJOR-11: second increase while additional payment pending ---------------- */

    public function test_agent_second_increase_is_blocked_while_additional_payment_is_pending(): void
    {
        $item = $this->placeAgentItem(3);
        $this->payInFull($item);

        app(OrderFulfillmentService::class)->adjustItemQuantity($item, 4, $this->b['admin'], 'lebih', 'cod');

        $payment = OrderAdditionalPayment::query()->latest('id')->firstOrFail();
        $this->assertSame('pending', $payment->status);
        $this->assertEquals(10000, (float) $payment->amount, 'additional obligation equals the one-unit delta');

        $item->refresh();
        $order = Order::withoutGlobalScopes()->findOrFail($item->order_id);
        $totalBefore = (float) $order->total_amount;
        $remainingBefore = (float) $order->remaining_amount;
        $reservedBefore = (int) ProductStock::withoutGlobalScopes()->where('agent_id', $this->b['agen']->id)->value('quantity_reserved');
        $txBefore = PaymentTransaction::query()->count();

        $this->assertRejected(fn () => app(OrderFulfillmentService::class)->adjustItemQuantity($item, 5, $this->b['admin'], 'lagi', 'cod'));

        $item->refresh();
        $order = Order::withoutGlobalScopes()->findOrFail($item->order_id);
        $this->assertSame(4, $item->fulfilled_quantity);
        $this->assertSame($totalBefore, (float) $order->total_amount);
        $this->assertSame($remainingBefore, (float) $order->remaining_amount);
        $this->assertSame($reservedBefore, (int) ProductStock::withoutGlobalScopes()->where('agent_id', $this->b['agen']->id)->value('quantity_reserved'));
        $this->assertSame(1, OrderAdditionalPayment::query()->where('order_id', $order->id)->count(), 'exactly one pending obligation');
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame($txBefore, PaymentTransaction::query()->count(), 'no second payment transaction');

        // Once settled (paid), the next increase proceeds and creates the next obligation.
        app(OrderFulfillmentService::class)->markAdditionalPaymentPaid($payment->fresh(), $this->b['admin'], true);
        app(OrderFulfillmentService::class)->adjustItemQuantity($item, 5, $this->b['admin'], 'lagi', 'cod');
        $this->assertSame(5, $item->fresh()->fulfilled_quantity);
    }

    public function test_sub_second_increase_is_blocked_while_additional_payment_is_pending(): void
    {
        $item = $this->placeSubItem(3);
        $this->payInFull($item);

        app(OrderFulfillmentService::class)->adjustItemQuantity($item, 4, $this->b['admin'], 'lebih', 'cod');
        $payment = OrderAdditionalPayment::query()->latest('id')->firstOrFail();
        $this->assertSame('pending', $payment->status);

        $item->refresh();
        $reservedBefore = (int) SubStockReservation::query()->where('order_item_id', $item->id)->value('quantity');
        $totalBefore = (float) Order::withoutGlobalScopes()->findOrFail($item->order_id)->total_amount;

        $this->assertRejected(fn () => app(OrderFulfillmentService::class)->adjustItemQuantity($item, 5, $this->b['admin'], 'lagi', 'cod'));

        $item->refresh();
        $this->assertSame(4, $item->fulfilled_quantity);
        $this->assertSame($totalBefore, (float) Order::withoutGlobalScopes()->findOrFail($item->order_id)->total_amount);
        $this->assertSame($reservedBefore, (int) SubStockReservation::query()->where('order_item_id', $item->id)->value('quantity'));
        $this->assertSame(20, app(SubStockService::class)->physical($this->b['location']->id, $this->b['product']->id, null));

        app(OrderFulfillmentService::class)->markAdditionalPaymentPaid($payment->fresh(), $this->b['admin'], true);
        app(OrderFulfillmentService::class)->adjustItemQuantity($item, 5, $this->b['admin'], 'lagi', 'cod');
        $this->assertSame(5, $item->fresh()->fulfilled_quantity);
    }
}
