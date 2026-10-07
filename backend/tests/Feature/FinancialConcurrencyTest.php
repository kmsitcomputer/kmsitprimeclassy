<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderAdditionalPayment;
use App\Models\OrderItem;
use App\Models\OrderItemAdjustment;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ShippingConfiguration;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\Order\OrderFulfillmentService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\Support\ConcurrencyHarness;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

/**
 * R-03 / MAJOR-10: the adjustment-side financial guards must not take a financial-row lock — an
 * admin quantity adjustment (Order -> financial) racing a Keuangan settlement (financial -> Order)
 * must not deadlock. Both sides serialise; a conservative 422 on the adjustment side is acceptable.
 */
class FinancialConcurrencyTest extends TestCase
{
    use HasTestRegion;
    use RestoresIsolatedTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_increase_racing_a_pending_refund_processing_does_not_deadlock(): void
    {
        $base = $this->agentBase();

        for ($iteration = 1; $iteration <= 5; $iteration++) {
            $adjustment = $this->driveToPendingRefund($base);

            $report = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'fulfillment-increase', 'actor_id' => $base['admin']->id, 'subject_id' => $base['item']->id, 'extra' => ['quantity' => 3, 'method' => 'cod']],
                ['op' => 'refund-process', 'actor_id' => $base['admin']->id, 'subject_id' => $adjustment->id],
            );

            $this->assertTrue($report['different_connections']);
            $this->assertTrue($report['true_overlap']);
            $this->assertNoDeadlock($report);

            $item = OrderItem::query()->findOrFail($base['item']->id);
            $reserved = (int) ProductStock::withoutGlobalScopes()->where('agent_id', $base['agen']->id)->value('quantity_reserved');

            $this->assertSame('processed', OrderItemAdjustment::query()->findOrFail($adjustment->id)->refund_status);
            $this->assertContains($item->fulfilled_quantity, [2, 3], 'one serialized valid outcome');
            $this->assertSame($item->fulfilled_quantity, $reserved, 'inventory/reservation conserved');
            $this->assertSame(3 - $item->fulfilled_quantity, $item->cancelled_quantity);
        }
    }

    public function test_reduction_racing_an_additional_payment_settlement_does_not_deadlock(): void
    {
        $base = $this->agentBase();

        for ($iteration = 1; $iteration <= 5; $iteration++) {
            $payment = $this->driveToPendingAdditionalPayment($base);

            $report = (new ConcurrencyHarness)->runServiceRace(
                ['op' => 'fulfillment-reduce', 'actor_id' => $base['admin']->id, 'subject_id' => $base['item']->id, 'extra' => ['quantity' => 3]],
                ['op' => 'additional-settle', 'actor_id' => $base['admin']->id, 'subject_id' => $payment->id, 'extra' => ['paid' => false]],
            );

            $this->assertTrue($report['different_connections']);
            $this->assertTrue($report['true_overlap']);
            $this->assertNoDeadlock($report);

            $item = OrderItem::query()->findOrFail($base['item']->id);
            $reserved = (int) ProductStock::withoutGlobalScopes()->where('agent_id', $base['agen']->id)->value('quantity_reserved');

            $this->assertSame('failed', OrderAdditionalPayment::query()->findOrFail($payment->id)->status);
            $this->assertContains($item->fulfilled_quantity, [3, 4], 'one serialized valid outcome');
            $this->assertSame($item->fulfilled_quantity, $reserved, 'inventory/reservation conserved');
        }
    }

    public function test_distinct_refunds_on_same_order_preserve_both_money_movements(): void
    {
        $base = $this->agentBase();
        $service = app(OrderFulfillmentService::class);
        $service->adjustItemQuantity($base['item'], 2, $base['admin'], 'first reduction');
        $service->adjustItemQuantity($base['item']->fresh(), 1, $base['admin'], 'second reduction');
        $refunds = OrderItemAdjustment::where('order_item_id', $base['item']->id)->where('refund_status', 'pending')->orderBy('id')->get();
        $this->assertCount(2, $refunds);

        // Two DIFFERENT refund rows of the SAME order are guarded by two
        // different locks, so the canonical Order row lock is what serializes
        // them. The result must not depend on read ordering any more: each
        // processor re-reads the CURRENT paid_amount under the Order lock, so
        // both movements survive (30000 -> 20000 -> 10000).
        $report = (new ConcurrencyHarness)->runServiceRace(
            ['op' => 'refund-process', 'actor_id' => $base['admin']->id, 'subject_id' => $refunds[0]->id],
            ['op' => 'refund-process', 'actor_id' => $base['admin']->id, 'subject_id' => $refunds[1]->id],
        );
        $this->assertTrue($report['different_connections']);
        $this->assertSame('success', $report['a']['outcome'], json_encode($report));
        $this->assertSame('success', $report['b']['outcome'], json_encode($report));
        $this->assertNoDeadlock($report);
        $order = Order::withoutGlobalScopes()->findOrFail($base['item']->order_id);
        $this->assertSame(10000.0, (float) $order->paid_amount, 'both 10000 refunds must leave 10000 of the original 30000 paid');
        $this->assertSame(0.0, (float) $order->remaining_amount);
        $this->assertSame(2, OrderItemAdjustment::where('order_item_id', $base['item']->id)->where('refund_status', 'processed')->count());
    }

    private function assertNoDeadlock(array $report): void
    {
        foreach (['a', 'b'] as $side) {
            $payload = $report[$side] ?? [];
            $message = strtolower((string) ($payload['message'] ?? ''));
            $this->assertStringNotContainsString('deadlock', $message, 'no deadlock allowed');
            $this->assertStringNotContainsString('lock wait timeout', $message, 'no lock-wait timeout allowed');
            $this->assertNotSame(1213, (int) ($payload['error_code'] ?? 0), 'no MySQL deadlock (1213)');
            $this->assertNotSame(1205, (int) ($payload['error_code'] ?? 0), 'no lock-wait timeout (1205)');
        }
    }

    /** Committed agent fixture: order/item at qty 3, fully paid, no obligations yet. */
    private function agentBase(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'FinRace Branch', 'address' => 'Test', 'latitude' => -6.2, 'longitude' => 106.8]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true]);
        $product = Product::create(['sku' => 'FR-'.Str::uuid(), 'name' => 'Fin Race Cake', 'slug' => 'fr-'.uniqid(), 'has_variations' => false, 'base_price' => 10000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 30]);

        $id = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $product->id, 'quantity' => 3]],
                'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated()->json('data.id');

        $item = OrderItem::where('order_id', $id)->firstOrFail();
        $this->payInFull($item);

        return compact('agen', 'admin', 'konsumen', 'product', 'item');
    }

    private function payInFull(OrderItem $item): void
    {
        $order = Order::withoutGlobalScopes()->findOrFail($item->order_id);
        $order->update(['payment_status' => 'paid', 'paid_amount' => $order->total_amount, 'remaining_amount' => 0]);
    }

    /** Force the order's paid amount (used to neutralise stale obligations while resetting). */
    private function setPaid(OrderItem $item, float $amount): void
    {
        Order::withoutGlobalScopes()->whereKey($item->order_id)->update([
            'paid_amount' => $amount,
            'remaining_amount' => 0,
            'payment_status' => $amount > 0 ? 'paid' : 'unpaid',
        ]);
    }

    /** Normalise the item back to 3/active, then reduce to 2 to create a fresh pending refund. */
    private function driveToPendingRefund(array $base): OrderItemAdjustment
    {
        OrderItemAdjustment::query()->where('order_item_id', $base['item']->id)->update(['refund_status' => 'processed']);
        OrderAdditionalPayment::query()->where('order_id', $base['item']->order_id)->update(['status' => 'failed']);

        $item = OrderItem::query()->findOrFail($base['item']->id);
        $this->setPaid($item, 0);
        if ((int) $item->fulfilled_quantity !== 3) {
            app(OrderFulfillmentService::class)->adjustItemQuantity($item, 3, $base['admin'], 'reset', 'cod');
        }
        OrderAdditionalPayment::query()->where('order_id', $base['item']->order_id)->update(['status' => 'failed']);

        // Fully paid at qty 3, then reduce -> overpayment -> pending refund.
        $item = OrderItem::query()->findOrFail($base['item']->id);
        $this->payInFull($item);
        app(OrderFulfillmentService::class)->adjustItemQuantity($item, 2, $base['admin'], 'reset');

        return OrderItemAdjustment::query()->latest('id')->firstOrFail();
    }

    /** Normalise the item back to 3/active, then increase to 4 to create a fresh pending obligation. */
    private function driveToPendingAdditionalPayment(array $base): OrderAdditionalPayment
    {
        OrderAdditionalPayment::query()->where('order_id', $base['item']->order_id)->update(['status' => 'failed']);
        OrderItemAdjustment::query()->where('order_item_id', $base['item']->id)->update(['refund_status' => 'processed']);

        $item = OrderItem::query()->findOrFail($base['item']->id);
        $this->setPaid($item, 0);
        if ((int) $item->fulfilled_quantity !== 3) {
            app(OrderFulfillmentService::class)->adjustItemQuantity($item, 3, $base['admin'], 'reset');
        }
        OrderAdditionalPayment::query()->where('order_id', $base['item']->order_id)->update(['status' => 'failed']);
        OrderItemAdjustment::query()->where('order_item_id', $base['item']->id)->update(['refund_status' => 'processed']);

        // Fully settled at qty 3, then increase -> pending additional payment.
        $item = OrderItem::query()->findOrFail($base['item']->id);
        $this->payInFull($item);
        app(OrderFulfillmentService::class)->adjustItemQuantity($item, 4, $base['admin'], 'reset', 'cod');

        return OrderAdditionalPayment::query()->latest('id')->firstOrFail();
    }
}
