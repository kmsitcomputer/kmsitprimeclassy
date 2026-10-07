<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ShippingConfiguration;
use App\Models\User;
use App\Models\Voucher;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * IMP-002 — Vouchers.
 *
 * Server-authoritative: the client submits only the CODE; validity/
 * applicability/value/redemption-count are enforced in VoucherService +
 * OrderService. Historical orders keep their totals.
 */
class VoucherTest extends TestCase
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
        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true]);

        $productA = Product::create(['sku' => 'V-'.Str::uuid(), 'name' => 'Kue A', 'slug' => 'kue-a-'.uniqid(), 'has_variations' => false, 'base_price' => 100000, 'weight_grams' => 500, 'status' => 'active']);
        $productB = Product::create(['sku' => 'V-'.Str::uuid(), 'name' => 'Kue B', 'slug' => 'kue-b-'.uniqid(), 'has_variations' => false, 'base_price' => 20000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $productA->id, 'quantity_on_hand' => 50, 'quantity_reserved' => 0]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $productB->id, 'quantity_on_hand' => 50, 'quantity_reserved' => 0]);

        $this->b = compact('agen', 'admin', 'konsumen', 'productA', 'productB');
    }

    private function placeOrder(array $items, ?string $voucherCode = null): Order
    {
        $payload = [
            'payment_method_code' => 'cod',
            'items' => $items,
            'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ];
        if ($voucherCode !== null) {
            $payload['voucher_code'] = $voucherCode;
        }

        $id = $this->actingAs($this->b['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', $payload)->assertCreated()->json('data.id');

        return Order::withoutGlobalScopes()->findOrFail($id);
    }

    public function test_quote_and_order_apply_fixed_voucher_within_subtotal(): void
    {
        Voucher::create(['agent_id' => $this->b['agen']->id, 'code' => 'HEMAT10', 'name' => 'Diskon 10rb', 'type' => 'fixed', 'value' => 10000, 'is_active' => true]);

        $quote = $this->actingAs($this->b['konsumen'])->postJson('/api/v1/checkout/quote', [
            'items' => [['product_id' => $this->b['productA']->id, 'quantity' => 1]],
            'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6, 'voucher_code' => 'HEMAT10',
        ])->assertOk()->json('data');

        $this->assertSame(10000.0, (float) $quote['discount_amount']);
        $this->assertSame(90000.0, (float) $quote['total_amount']); // 100000 - 10000 + shipping 0... free shipping

        $order = $this->placeOrder([['product_id' => $this->b['productA']->id, 'quantity' => 1]], 'HEMAT10');
        $this->assertSame(10000.0, (float) $order->discount_amount);
        $this->assertSame(90000.0, (float) $order->total_amount);
    }

    public function test_invalid_disabled_expired_or_future_voucher_is_rejected(): void
    {
        // invalid code
        $this->placeOrderWithVoucherExpectingFailure('BOGUS');

        // disabled
        Voucher::create(['agent_id' => $this->b['agen']->id, 'code' => 'OFF', 'name' => 'Off', 'type' => 'fixed', 'value' => 10000, 'is_active' => false]);
        $this->placeOrderWithVoucherExpectingFailure('OFF');

        // expired
        Voucher::create(['agent_id' => $this->b['agen']->id, 'code' => 'EXP', 'name' => 'Exp', 'type' => 'fixed', 'value' => 10000, 'is_active' => true, 'valid_until' => now()->subDay()->toDateString()]);
        $this->placeOrderWithVoucherExpectingFailure('EXP');

        // not-yet-valid
        Voucher::create(['agent_id' => $this->b['agen']->id, 'code' => 'FUT', 'name' => 'Fut', 'type' => 'fixed', 'value' => 10000, 'is_active' => true, 'valid_from' => now()->addDay()->toDateString()]);
        $this->placeOrderWithVoucherExpectingFailure('FUT');
    }

    private function placeOrderWithVoucherExpectingFailure(string $code): void
    {
        $this->actingAs($this->b['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $this->b['productA']->id, 'quantity' => 1]],
                'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6, 'voucher_code' => $code,
            ])->assertStatus(422);
    }

    public function test_product_scoped_voucher_does_not_apply_to_other_product(): void
    {
        Voucher::create(['agent_id' => $this->b['agen']->id, 'code' => 'AONLY', 'name' => 'Kue A only', 'type' => 'fixed', 'value' => 10000, 'is_active' => true, 'product_id' => $this->b['productA']->id]);

        $response = $this->actingAs($this->b['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $this->b['productB']->id, 'quantity' => 1]],
                'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6, 'voucher_code' => 'AONLY',
            ])->assertStatus(422);

        $this->assertSame(0, Order::count(), 'the order must not be created when a scoped voucher does not apply');
    }

    public function test_limited_voucher_consumes_redemptions_and_rejects_when_exhausted(): void
    {
        Voucher::create(['agent_id' => $this->b['agen']->id, 'code' => 'TX2', 'name' => '2x', 'type' => 'fixed', 'value' => 10000, 'is_active' => true, 'max_uses' => 2]);

        $this->placeOrder([['product_id' => $this->b['productA']->id, 'quantity' => 1]], 'TX2');
        $this->placeOrder([['product_id' => $this->b['productA']->id, 'quantity' => 1]], 'TX2');

        $this->placeOrderWithVoucherExpectingFailure('TX2');
        $voucher = Voucher::query()->where('code', 'TX2')->firstOrFail();
        $this->assertSame(2, $voucher->used_count);
    }

    public function test_voucher_never_drives_total_negative_and_attribution_is_kept(): void
    {
        Voucher::create(['agent_id' => $this->b['agen']->id, 'code' => 'BIG', 'name' => 'Big', 'type' => 'fixed', 'value' => 500000, 'is_active' => true]);

        $order = $this->placeOrder([['product_id' => $this->b['productB']->id, 'quantity' => 1]], 'BIG');
        // capped at subtotal (20000), never negative.
        $this->assertSame(20000.0, (float) $order->discount_amount);
        $this->assertSame(0.0, (float) $order->total_amount);
        $this->assertNotNull($order->voucher_id);
    }
}