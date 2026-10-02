<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\Order\DeliveryGroupInvoiceService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

class DeliveryGroupInvoiceTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function fixture(): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        AgentProfile::create(['user_id' => $agent->id, 'store_name' => 'Invoice test', 'address' => 'x', 'latitude' => -6.2, 'longitude' => 106.8]);
        $admin = User::factory()->admin()->create(['agent_id' => $agent->id]);
        $buyer = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        $otherBuyer = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        $foreignBuyer = User::factory()->konsumen()->create(['agent_id' => User::factory()->agen()->create()->id]);
        $products = collect(['Invoice A' => 10000, 'Invoice B' => 12000, 'Invoice C' => 8000])->map(function ($price, $name) use ($agent) {
            $product = Product::create(['sku' => 'INV-'.Str::uuid(), 'name' => $name, 'slug' => 'inv-'.uniqid(), 'has_variations' => false, 'base_price' => $price, 'weight_grams' => 100, 'status' => 'active']);
            ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);
            WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 10]);

            return $product;
        })->values();

        $response = $this->actingAs($buyer)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [
                ['product_id' => $products[0]->id, 'quantity' => 2],
                ['product_id' => $products[1]->id, 'quantity' => 1],
                ['product_id' => $products[2]->id, 'quantity' => 3],
            ],
            'recipient_name' => 'Invoice Buyer', 'recipient_phone' => '0811', 'address_line' => 'Invoice Street',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ])->assertCreated();
        $order = Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
        $items = $order->items()->orderBy('id')->get();
        $firstDate = now()->addDays(5)->toDateString();
        $secondDate = now()->addDays(8)->toDateString();
        $firstShipment = $items[0]->shipment;
        $secondShipment = $firstShipment->replicate();
        $secondShipment->save();

        $items[0]->update(['requested_delivery_date' => $firstDate, 'shipment_id' => $firstShipment->id]);
        $items[1]->update(['requested_delivery_date' => $firstDate, 'shipment_id' => $firstShipment->id]);
        $items[2]->update(['requested_delivery_date' => $secondDate, 'shipment_id' => $secondShipment->id]);

        $courierUser = User::factory()->kurir()->create(['agent_id' => $agent->id]);
        $courier = Courier::create(['type' => 'internal', 'user_id' => $courierUser->id, 'agent_id' => $agent->id, 'name' => 'Kurir Budi', 'is_active' => true]);
        $firstShipment->update([
            'shipping_provider_code' => 'openroute', 'courier_id' => $courier->id,
            'shipping_fee_snapshot' => 12, 'provider_meta' => ['rule' => 'distance_rate_applied'],
        ]);
        $secondShipment->update([
            'shipping_provider_code' => 'rajaongkir', 'shipping_fee_snapshot' => 28,
            'provider_meta' => ['courier' => 'jne', 'service' => 'REG'],
        ]);

        $subtotal = (float) $order->subtotal_amount;
        $order->update([
            'shipping_fee_amount' => 40,
            'admin_fee_amount' => 0,
            'total_amount' => $subtotal + 40,
            'dp_amount' => 50,
            'paid_amount' => 60,
            'remaining_amount' => $subtotal + 40 - 60,
            'payment_status' => 'partially_paid',
        ]);

        return compact('agent', 'admin', 'buyer', 'otherBuyer', 'foreignBuyer', 'order', 'items', 'firstDate', 'secondDate');
    }

    public function test_pdf_is_one_order_one_date_and_allocates_initial_dp_only_to_earliest_group(): void
    {
        $f = $this->fixture();
        $service = app(DeliveryGroupInvoiceService::class);
        $first = $service->forDate($f['order']->fresh(), $f['firstDate']);
        $second = $service->forDate($f['order']->fresh(), $f['secondDate']);

        $this->assertSame([$f['items'][0]->product_name_snapshot, $f['items'][1]->product_name_snapshot], array_column($first['items'], 'product_name'));
        $this->assertSame([$f['items'][2]->product_name_snapshot], array_column($second['items'], 'product_name'));
        $this->assertSame(['Kurir Online'], $first['delivery_methods']);
        $this->assertSame(['Kurir Budi'], $first['courier_names']);
        $this->assertSame(['JNE REG'], $second['delivery_methods']);
        $this->assertSame([], $second['courier_names'], 'expedition must not invent a courier person');
        $this->assertSame('12,00', $first['shipping_fee']);
        $this->assertSame('28,00', $second['shipping_fee']);
        $this->assertSame('32.012,00', $first['group_total']);
        $this->assertSame('24.028,00', $second['group_total']);
        $this->assertSame(3, $first['total_item_count']);
        $this->assertSame(3, $second['total_item_count']);
        $this->assertSame('50,00', $first['payment']['verified_dp_credit']);
        $this->assertSame('50,00', $first['payment']['requested_dp']);
        $this->assertNull($second['payment']['verified_dp_credit']);
        $this->assertNull($second['payment']['requested_dp']);

        $groups = collect($this->actingAs($f['buyer'])->getJson("/api/v1/orders/{$f['order']->id}")->assertOk()->json('data.delivery_groups'));
        $displayedFirst = $groups->firstWhere('delivery_date', $f['firstDate']);
        $displayedSecond = $groups->firstWhere('delivery_date', $f['secondDate']);
        $this->assertSame(['Kurir Online'], $displayedFirst['delivery_methods']);
        $this->assertSame(['Kurir Budi'], $displayedFirst['courier_names']);
        $this->assertSame([$f['items'][0]->product_name_snapshot, $f['items'][1]->product_name_snapshot], array_column($displayedFirst['items'], 'product_name'));
        $this->assertSame(['JNE REG'], $displayedSecond['delivery_methods']);
        $this->assertSame([], $displayedSecond['courier_names']);
        $this->assertSame([$f['items'][2]->product_name_snapshot], array_column($displayedSecond['items'], 'product_name'));
    }

    public function test_pdf_route_is_order_authorized_and_does_not_write_payment_transactions(): void
    {
        $f = $this->fixture();
        $url = "/api/v1/orders/{$f['order']->id}/delivery-groups/{$f['firstDate']}/invoice";
        $transactionsBefore = PaymentTransaction::where('order_id', $f['order']->id)->count();

        $pdf = $this->actingAs($f['buyer'])->get($url)->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertSame($transactionsBefore, PaymentTransaction::where('order_id', $f['order']->id)->count());

        $this->actingAs($f['admin'])->get($url)->assertOk();
        $this->actingAs($f['otherBuyer'])->get($url)->assertForbidden();
        $this->actingAs($f['foreignBuyer'])->get($url)->assertNotFound();
        $this->actingAs($f['buyer'])->get("/api/v1/orders/{$f['order']->id}/delivery-groups/{$f['secondDate']}/invoice")->assertOk();
        $this->actingAs($f['buyer'])->get("/api/v1/orders/{$f['order']->id}/delivery-groups/2026-12-31/invoice")->assertNotFound();
        $this->assertSame($transactionsBefore, PaymentTransaction::where('order_id', $f['order']->id)->count());
    }
}