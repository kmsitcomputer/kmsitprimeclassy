<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Models\User;
use App\Services\Order\OrderFulfillmentService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * R-03 / decision G: an Agent partial split must keep the order-generated StockRequest consistent —
 * quantity conservation, no duplicate StockRequestItem per OrderItem, and an explicit 422 when the
 * fulfilled remainder cannot cover the moved quantity.
 */
class StockRequestSplitTest extends TestCase
{
    use RefreshDatabase;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        $this->b = $this->fixture();
    }

    private function fixture(int $fulfilled = 0, int $remaining = 10): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Toko', 'address' => 'Jl. X', 'latitude' => -6.2, 'longitude' => 106.8]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        $product = Product::create(['sku' => 'SR-'.Str::uuid(), 'name' => 'Split Cake', 'slug' => 'split-'.uniqid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 10]);

        $order = Order::create([
            'order_no' => 'SPL-'.Str::random(12), 'konsumen_id' => $konsumen->id, 'agent_id' => $agen->id,
            'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'),
            'status' => 'diproses', 'payment_status' => 'unpaid',
            'subtotal_amount' => 10000, 'total_amount' => 10000,
            'recipient_name_snapshot' => 'Test', 'recipient_phone_snapshot' => '0811', 'address_snapshot' => 'Test',
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku,
            'unit_price_snapshot' => 1000, 'subtotal_snapshot' => 10000,
            'original_quantity' => 10, 'fulfilled_quantity' => 10,
            'requested_delivery_date' => '2026-11-01', 'status' => 'diproses',
        ]);
        $request = StockRequest::create(['agent_id' => $agen->id, 'order_id' => $order->id, 'request_number' => 'SR-'.Str::uuid(), 'status' => $fulfilled > 0 ? 'partial' : 'pending']);
        $requestItem = StockRequestItem::create(['stock_request_id' => $request->id, 'order_item_id' => $item->id, 'product_id' => $product->id, 'sku_snapshot' => $product->sku, 'requested_qty' => 10, 'fulfilled_qty' => $fulfilled, 'remaining_qty' => $remaining]);

        // The split is driven through the reschedule service, which the Human's LOCKED rule
        // (2026-10-07) allows ONLY for Kurir Online. This fixture creates the order row directly
        // and seeds no shipping provider, so the canonical field is set here explicitly.
        Shipment::create([
            'order_id' => $order->id, 'shipping_provider_code' => 'openroute', 'status' => 'pending',
            'delivery_mode' => \App\Models\Shipment::DELIVERY_MODE_STANDARD,
        ]);
        $item->update(['shipment_id' => Shipment::query()->where('order_id', $order->id)->value('id')]);

        return compact('agen', 'admin', 'konsumen', 'product', 'order', 'item', 'request', 'requestItem');
    }

    public function test_pending_request_split_conserves_quantities_and_creates_one_child_line(): void
    {
        $child = app(OrderFulfillmentService::class)->rescheduleItemDeliveryDate($this->b['item'], '2026-12-01', $this->b['admin'], 'split', 4);

        $parentLine = $this->b['requestItem']->fresh();
        $childLine = StockRequestItem::where('order_item_id', $child->id)->firstOrFail();

        $this->assertSame(6, $parentLine->requested_qty);
        $this->assertSame(6, $parentLine->remaining_qty);
        $this->assertSame(0, $parentLine->fulfilled_qty);
        $this->assertSame(4, $childLine->requested_qty);
        $this->assertSame(4, $childLine->remaining_qty);
        $this->assertSame(0, $childLine->fulfilled_qty);

        $this->assertSame(10, (int) StockRequestItem::where('stock_request_id', $this->b['request']->id)->sum('requested_qty'));
        $this->assertSame(10, (int) StockRequestItem::where('stock_request_id', $this->b['request']->id)->sum('remaining_qty'));
        $this->assertSame(1, StockRequestItem::where('order_item_id', $child->id)->count());
    }

    public function test_partially_fulfilled_request_split_carves_only_from_the_remainder(): void
    {
        $this->b = $this->fixture(fulfilled: 3, remaining: 7);

        $child = app(OrderFulfillmentService::class)->rescheduleItemDeliveryDate($this->b['item'], '2026-12-01', $this->b['admin'], 'split', 4);

        $parentLine = $this->b['requestItem']->fresh();
        $childLine = StockRequestItem::where('order_item_id', $child->id)->firstOrFail();

        $this->assertSame(3, $parentLine->fulfilled_qty, 'fulfilled warehouse history is never rewritten');
        $this->assertSame(3, $parentLine->remaining_qty);
        $this->assertSame(6, $parentLine->requested_qty);
        $this->assertSame(0, $childLine->fulfilled_qty);
        $this->assertSame(4, $childLine->requested_qty);

        $lines = StockRequestItem::where('stock_request_id', $this->b['request']->id)->get();
        $this->assertSame(10, (int) $lines->sum('requested_qty'));
        $this->assertSame(3, (int) $lines->sum('fulfilled_qty'));
        $this->assertSame(7, (int) $lines->sum('remaining_qty'));
    }

    public function test_split_is_rejected_when_the_fulfilled_remainder_cannot_cover_it(): void
    {
        $this->b = $this->fixture(fulfilled: 10, remaining: 0);

        $this->expectException(ApiException::class);
        try {
            app(OrderFulfillmentService::class)->rescheduleItemDeliveryDate($this->b['item'], '2026-12-01', $this->b['admin'], 'split', 1);
        } finally {
            $this->assertSame(1, OrderItem::where('order_id', $this->b['order']->id)->count());
            $this->assertSame(10, $this->b['requestItem']->fresh()->requested_qty);
            $this->assertSame(1, StockRequestItem::where('stock_request_id', $this->b['request']->id)->count());
        }
    }

    public function test_split_when_there_is_no_stock_request_still_works(): void
    {
        StockRequestItem::query()->delete();
        $this->b['request']->delete();

        $child = app(OrderFulfillmentService::class)->rescheduleItemDeliveryDate($this->b['item'], '2026-12-01', $this->b['admin'], 'split', 4);

        $this->assertSame(4, $child->fulfilled_quantity);
        $this->assertSame(0, StockRequestItem::count());
    }
}
