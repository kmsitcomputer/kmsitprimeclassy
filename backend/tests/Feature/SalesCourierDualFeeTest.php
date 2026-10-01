<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Commission;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFee;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use App\Services\Order\CourierService;
use App\Services\User\UserManagementService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

class SalesCourierDualFeeTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_same_sales_kurir_receives_distinct_sales_and_courier_fees(): void
    {
        $fixture = $this->createFixture();
        $salesKurir = $fixture['sales_kurir'];

        $this->assertSame($salesKurir->id, $fixture['buyer']->sales_id);
        $this->assertSame($salesKurir->id, $fixture['buyer']->parent_id);
        $this->assertSame($salesKurir->id, $fixture['courier']->user_id);
        $this->assertSame('sales-kurir-sub', $salesKurir->role->slug);
        $this->assertStringStartsWith('SS-', $salesKurir->referral_code);

        // R-03 / decision A: the Sales-Kurir-Sub self-delivers a SUB-sourced order (first-class
        // path, no Courier assignment) and earns BOTH the sales fee and the courier fee.
        $order = $this->placeOrder($salesKurir, $fixture['buyer'], $fixture['product']);
        $item = $order->items()->firstOrFail();
        $shipment = $order->shipments()->firstOrFail();

        $this->assertSame('self_sub', $shipment->delivery_mode);
        $this->assertSame($salesKurir->id, $shipment->self_delivered_by_user_id);
        $this->assertNull($shipment->courier_id);

        $this->assertSame($salesKurir->id, $order->sales_id);
        $this->assertDatabaseHas('commissions', [
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'beneficiary_user_id' => $fixture['agent']->id,
            'beneficiary_role' => 'agent',
            'amount' => 1000,
        ]);
        $this->assertDatabaseHas('commissions', [
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'beneficiary_user_id' => $salesKurir->id,
            'beneficiary_role' => 'sales',
            'amount' => 500,
        ]);
        $this->assertDatabaseMissing('commissions', [
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'beneficiary_role' => 'courier',
        ]);

        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'dikirim', $salesKurir);
        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'terkirim', $salesKurir, UploadedFile::fake()->image('sales-kurir-sub-proof.jpg'));

        $salesFee = Commission::query()->where('order_item_id', $item->id)->where('beneficiary_user_id', $salesKurir->id)->where('beneficiary_role', 'sales')->get();
        $courierFee = Commission::query()->where('order_item_id', $item->id)->where('beneficiary_user_id', $salesKurir->id)->where('beneficiary_role', 'courier')->get();
        $agentFee = Commission::query()->where('order_item_id', $item->id)->where('beneficiary_user_id', $fixture['agent']->id)->where('beneficiary_role', 'agent')->get();

        $this->assertCount(1, $agentFee);
        $this->assertSame(1000.0, (float) $agentFee->first()->amount);
        $this->assertCount(1, $salesFee);
        $this->assertSame(500.0, (float) $salesFee->first()->amount);
        $this->assertCount(1, $courierFee);
        $this->assertSame(250.0, (float) $courierFee->first()->amount);
        $this->assertNotSame($salesFee->first()->beneficiary_role, $courierFee->first()->beneficiary_role);
        $this->assertSame(1750.0, (float) Commission::where('order_item_id', $item->id)->sum('amount'));
    }

    public function test_same_sales_kurir_fee_generation_is_idempotent(): void
    {
        $fixture = $this->createFixture();
        $order = $this->placeOrder($fixture['sales_kurir'], $fixture['buyer'], $fixture['product']);
        $shipment = $order->shipments()->firstOrFail();
        $item = $order->items()->firstOrFail();

        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'dikirim', $fixture['sales_kurir']);
        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'terkirim', $fixture['sales_kurir'], UploadedFile::fake()->image('proof.jpg'));

        app(CourierService::class)->recordCommissionsOnDelivery($order->fresh());
        app(CourierService::class)->recordCommissionsOnDelivery($order->fresh());

        $this->assertSame(1, Commission::where('order_item_id', $item->id)->where('beneficiary_role', 'agent')->count());
        $this->assertSame(1, Commission::where('order_item_id', $item->id)->where('beneficiary_role', 'sales')->count());
        $this->assertSame(1, Commission::where('order_item_id', $item->id)->where('beneficiary_role', 'courier')->count());
    }

    private function createFixture(): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        AgentProfile::create(['user_id' => $agent->id, 'store_name' => 'Dual Fee Branch', 'address' => 'Test', 'latitude' => -6.2, 'longitude' => 106.8]);
        $korsal = User::factory()->korsal()->create(['agent_id' => $agent->id, 'parent_id' => $agent->id]);
        $salesKurir = app(UserManagementService::class)->create($korsal, 'sales-kurir-sub', [
            'name' => 'Dual Fee Sales Kurir',
            'email' => 'sk-'.Str::uuid().'@example.test',
            'phone' => '0812000000',
            'password' => 'password123',
            'korsal_id' => $korsal->id,
        ]);
        $courier = $salesKurir->courierProfile()->firstOrFail();
        $admin = User::factory()->admin()->create(['agent_id' => $agent->id]);
        $registration = $this->postJson('/api/v1/auth/register', [
            'name' => 'Dual Fee Buyer',
            'email' => 'buyer-'.Str::uuid().'@example.test',
            'phone' => '0812111111',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'referral_code' => $salesKurir->referral_code,
        ])->assertCreated();
        $buyer = User::where('email', $registration->json('data.user.email'))->firstOrFail();

        $product = Product::create([
            'sku' => 'DUAL-FEE-'.Str::uuid(),
            'name' => 'Dual Fee Cake',
            'slug' => 'dual-fee-'.Str::uuid(),
            'has_variations' => false,
            'base_price' => 10000,
            'weight_grams' => 100,
            'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);
        // R-03: the order is SUB-sourced, so the Sales-Kurir-Sub self-delivers it from their own Sub Location.
        $location = WarehouseSubLocation::create(['agent_id' => $agent->id, 'code' => 'SK1', 'name' => 'SK1', 'created_by' => $agent->id]);
        $location->forceFill(['owner_user_id' => $salesKurir->id])->save();
        WarehouseStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $location->id, 'quantity' => 10]);
        ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'agent', 'amount' => 1000, 'is_active' => true]);
        ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'sales', 'amount' => 500, 'is_active' => true]);
        ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'courier', 'amount' => 250, 'is_active' => true]);

        return [
            'agent' => $agent,
            'korsal' => $korsal,
            'sales_kurir' => $salesKurir,
            'courier' => $courier,
            'admin' => $admin,
            'buyer' => $buyer,
            'product' => $product,
        ];
    }

    private function placeOrder(User $actor, User $buyer, Product $product): Order
    {
        $response = $this->actingAs($actor)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'konsumen_id' => $buyer->id,
            'stock_source' => 'sub',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'Dual Fee Buyer',
            'recipient_phone' => '0812111111',
            'address_line' => 'Test Address',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744,
            'longitude' => 107.609810,
        ]);
        $response->assertCreated();

        return Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
    }
}
