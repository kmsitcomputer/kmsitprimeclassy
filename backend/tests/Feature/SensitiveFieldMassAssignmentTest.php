<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFee;
use App\Models\ProductStock;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

class SensitiveFieldMassAssignmentTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_order_endpoint_ignores_reserved_payment_fee_and_stock_injection(): void
    {
        $fixture = $this->fixture();
        $stock = ProductStock::withoutGlobalScopes()->where('product_id', $fixture['product']->id)->firstOrFail();

        $response = $this->actingAs($fixture['buyer'])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [[
                    'product_id' => $fixture['product']->id,
                    'quantity' => 1,
                    'reserved' => 999999,
                    'quantity_reserved' => 999999,
                    'quantity_on_hand' => 999999,
                    'agent_id' => 999999,
                    'sales_fee_amount' => 999999,
                    'agent_fee_amount' => 999999,
                    'courier_fee_amount' => -50000,
                ]],
                'payment_status' => 'paid',
                'paid_amount' => 999999,
                'remaining_amount' => 0,
                'total_amount' => 1,
                'reserved' => 999999,
                'quantity_reserved' => 999999,
                'agent_id' => 999999,
                'parent_id' => 999999,
                'korsal_id' => 999999,
                'sales_id' => 999999,
                ...$this->destination(),
            ]);

        $response->assertCreated();
        $orderId = $response->json('data.id');
        $order = Order::withoutGlobalScopes()->findOrFail($orderId);
        $item = $order->items()->firstOrFail();
        $stock->refresh();

        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame(0.0, (float) $order->paid_amount);
        $this->assertSame(0.0, (float) $order->remaining_amount);
        $this->assertSame(1, (int) $stock->quantity_reserved);
        $this->assertSame(1000.0, (float) $item->agent_fee_amount);
        $this->assertSame(500.0, (float) $item->sales_fee_amount);
        $this->assertSame(250.0, (float) $item->courier_fee_amount);
        $this->assertNotSame(999999.0, (float) $item->agent_fee_amount);
        $this->assertNotSame(-50000.0, (float) $item->courier_fee_amount);
    }

    public function test_konsumen_cannot_mark_cod_paid_or_mutate_payment_authority(): void
    {
        $fixture = $this->fixture();
        $order = $this->actingAs($fixture['buyer'])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $fixture['product']->id, 'quantity' => 1]],
                ...$this->destination(),
            ])->assertCreated()->json('data');

        $this->actingAs($fixture['buyer'])
            ->patchJson('/api/v1/orders/'.$order['id'].'/payment/cod', [
                'paid' => true,
                'payment_status' => 'paid',
                'paid_amount' => 999999,
            ])->assertForbidden();

        $this->assertDatabaseHas('orders', [
            'id' => $order['id'],
            'payment_status' => 'unpaid',
            'paid_amount' => 0,
        ]);
    }

    /**
     * AUTHORITATIVE MODE: the legacy stock-adjust path is locked down, so a
     * malicious payload carrying quantity_on_hand / quantity_reserved / stock
     * is rejected outright (403) and the stored stock row is untouched.
     */
    public function test_authoritative_identity_and_stock_write_paths_ignore_client_network_fields(): void
    {
        $fixture = $this->fixture();

        $registration = $this->postJson('/api/v1/auth/register', [
            'name' => 'Injected Buyer',
            'email' => 'injected-'.Str::uuid().'@example.test',
            'phone' => '0812000000',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'referral_code' => null,
            'role' => 'admin',
            'role_id' => 999999,
            'agent_id' => 999999,
            'parent_id' => 999999,
            'korsal_id' => 999999,
            'sales_id' => 999999,
        ]);
        $registration->assertCreated();
        $created = User::where('email', $registration->json('data.user.email'))->firstOrFail();
        $this->assertSame('konsumen', $created->role?->slug);
        $this->assertNull($created->agent_id);
        $this->assertNull($created->parent_id);
        $this->assertNull($created->korsal_id);
        $this->assertNull($created->sales_id);

        $this->actingAs($fixture['admin'])
            ->postJson('/api/v1/stock/adjust', [
                'product_id' => $fixture['product']->id,
                'delta' => 1,
                'reason' => 'malicious stock payload',
                'quantity_on_hand' => 999999,
                'quantity_reserved' => 999999,
                'stock' => 999999,
            ])->assertForbidden();

        $this->assertDatabaseHas('product_stocks', [
            'agent_id' => $fixture['agent']->id,
            'product_id' => $fixture['product']->id,
            'quantity_on_hand' => 10,
            'quantity_reserved' => 0,
        ]);
    }

    private function fixture(): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $admin = User::factory()->admin()->create(['agent_id' => $agent->id]);
        $buyer = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        AgentProfile::create([
            'user_id' => $agent->id,
            'store_name' => 'Security Test Branch',
            'address' => 'Security Test Address',
            'latitude' => -6.2,
            'longitude' => 106.8,
        ]);
        $product = Product::create([
            'sku' => 'SEC-'.Str::uuid(),
            'name' => 'Security Test Cake',
            'slug' => 'security-'.Str::uuid(),
            'has_variations' => false,
            'base_price' => 10000,
            'weight_grams' => 100,
            'status' => 'active',
        ]);
        ProductStock::create([
            'agent_id' => $agent->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 10,
            'quantity_reserved' => 0,
        ]);
        ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'agent', 'amount' => 1000, 'is_active' => true]);
        ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'sales', 'amount' => 500, 'is_active' => true]);
        ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'courier', 'amount' => 250, 'is_active' => true]);

        return compact('agent', 'admin', 'buyer', 'product');
    }

    private function destination(): array
    {
        return [
            'recipient_name' => 'Security Buyer',
            'recipient_phone' => '0811000000',
            'address_line' => 'Security Test Address',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744,
            'longitude' => 107.609810,
        ];
    }
}
