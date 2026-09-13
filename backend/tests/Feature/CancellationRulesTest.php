<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AgentPaymentGatewayConfig;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * Blueprint §Cancellation: COD may still be cancelled while 'diproses';
 * non-COD only while 'diterima' — enforced identically for every role,
 * including super_admin ("tidak boleh mengubah status order jika
 * spesifikasi menyatakan demikian").
 */
class CancellationRulesTest extends TestCase
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
        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id]);
        $sales = User::factory()->sales()->create(['agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'referral_code' => 'S-'.uniqid()]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $superAdmin = User::factory()->create(['role_id' => Role::where('slug', 'super_admin')->value('id')]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'sales_id' => $sales->id]);

        return compact('agen', 'korsal', 'sales', 'admin', 'superAdmin', 'konsumen');
    }

    private function placeOrder(User $agen, User $konsumen, string $paymentMethodCode): Order
    {
        $product = Product::create(['sku' => 'TEST-'.\Illuminate\Support\Str::uuid(), 
            'name' => 'Cancel Test Cake', 'slug' => 'cancel-test-'.uniqid(),
            'has_variations' => false, 'base_price' => 100000, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);

        if ($paymentMethodCode === 'bank_transfer') {
            $method = PaymentMethod::where('code', 'bank_transfer')->firstOrFail();
            AgentPaymentGatewayConfig::updateOrCreate(
                ['agent_id' => $agen->id, 'payment_method_id' => $method->id, 'environment' => 'sandbox'],
                ['config' => ['bank_name' => 'BCA', 'account_name' => 'QA', 'account_number' => '123']]
            );
        }

        $orderId = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => $paymentMethodCode,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ])->json('data.id');

        return Order::withoutGlobalScopes()->findOrFail($orderId);
    }

    public function test_cod_order_can_still_be_cancelled_while_diproses(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        // COD order is already 'diproses' on creation.
        $order = $this->placeOrder($agen, $konsumen, 'cod');

        $response = $this->actingAs($konsumen)->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'Berubah pikiran']);
        $response->assertOk()->assertJsonPath('data.status', 'dibatalkan');

        // The cancel response must carry the same relations show()/updateStatus() do —
        // the frontend order-detail page renders straight off this response and crashes
        // (blank screen) if 'items'/'couriers' are silently missing.
        $this->assertNotNull($response->json('data.items'));
        $this->assertCount(1, $response->json('data.items'));
        $this->assertNotNull($response->json('data.couriers'));
    }

    public function test_non_cod_order_cannot_be_cancelled_once_diproses_by_any_role_including_super_admin(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'superAdmin' => $superAdmin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $order = $this->placeOrder($agen, $konsumen, 'bank_transfer');

        // Verify payment so it can advance to diproses.
        $order->update(['payment_status' => 'paid']);
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();

        $this->actingAs($konsumen)->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'X'])->assertStatus(422);
        $this->actingAs($admin)->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'X'])->assertStatus(422);
        // "Super Admin tidak boleh mengubah status order jika spesifikasi menyatakan demikian" — same rule, no bypass.
        $this->actingAs($superAdmin)->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'X'])->assertStatus(422);

        $this->assertSame('diproses', $order->fresh()->status);
    }

    public function test_non_cod_order_can_be_cancelled_while_still_diterima(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $order = $this->placeOrder($agen, $konsumen, 'bank_transfer');

        $this->actingAs($konsumen)->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'Belum bayar'])
            ->assertOk()->assertJsonPath('data.status', 'dibatalkan');
    }

    public function test_konsumen_cannot_cancel_another_konsumens_order(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $order = $this->placeOrder($agen, $konsumen, 'cod');

        $otherKonsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        $this->actingAs($otherKonsumen)->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'Not mine'])->assertStatus(403);
    }

    public function test_sales_and_korsal_can_cancel_orders_in_their_own_downline(): void
    {
        ['agen' => $agen, 'sales' => $sales, 'korsal' => $korsal, 'konsumen' => $konsumen] = $this->makeAgentBranch();

        $orderForSales = $this->placeOrder($agen, $konsumen, 'cod');
        $this->actingAs($sales)->postJson("/api/v1/orders/{$orderForSales->id}/cancel", ['reason' => 'Sales cancels'])
            ->assertOk()->assertJsonPath('data.status', 'dibatalkan');

        $orderForKorsal = $this->placeOrder($agen, $konsumen, 'cod');
        $this->actingAs($korsal)->postJson("/api/v1/orders/{$orderForKorsal->id}/cancel", ['reason' => 'Korsal cancels'])
            ->assertOk()->assertJsonPath('data.status', 'dibatalkan');
    }

    public function test_sales_cannot_cancel_an_order_outside_their_own_downline(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $order = $this->placeOrder($agen, $konsumen, 'cod');

        $korsal2 = User::factory()->korsal()->create(['agent_id' => $agen->id]);
        $unrelatedSales = User::factory()->sales()->create(['agent_id' => $agen->id, 'korsal_id' => $korsal2->id, 'referral_code' => 'S2-'.uniqid()]);

        $this->actingAs($unrelatedSales)->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'Not my downline'])->assertStatus(403);
    }

    public function test_cancellation_stores_full_audit_trail_with_actor_role_and_reason(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $order = $this->placeOrder($agen, $konsumen, 'cod');

        $this->actingAs($admin)->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'Stok habis permanen'])->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'causer_id' => $admin->id, 'event' => 'order.cancelled', 'description' => 'Stok habis permanen',
            'subject_type' => Order::class, 'subject_id' => $order->id,
        ]);

        $log = ActivityLog::where('event', 'order.cancelled')->where('subject_id', $order->id)->firstOrFail();
        $this->assertSame('admin', $log->properties['actor_role']);
    }

    public function test_cancelling_releases_reserved_stock(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->makeAgentBranch();
        $order = $this->placeOrder($agen, $konsumen, 'cod');
        $item = $order->items()->first();

        $stockBefore = ProductStock::withoutGlobalScopes()->where('product_id', $item->product_id)->first();
        $this->assertSame(1, $stockBefore->quantity_reserved);

        $this->actingAs($konsumen)->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'X'])->assertOk();

        $stockAfter = ProductStock::withoutGlobalScopes()->where('product_id', $item->product_id)->first();
        $this->assertSame(0, $stockAfter->quantity_reserved);
    }
}
