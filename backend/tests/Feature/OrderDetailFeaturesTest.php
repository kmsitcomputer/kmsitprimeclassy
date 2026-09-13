<?php

namespace Tests\Feature;

use App\Models\AgentPaymentGatewayConfig;
use App\Models\AgentProfile;
use App\Models\CodPaymentProof;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * Order detail page requirements (Blueprint §Order Detail): courier delivery
 * proof, konsumen COD payment proof + admin confirmation, and the extra
 * fields the detail page needs (konsumen block, lat/long, shipping provider).
 */
class OrderDetailFeaturesTest extends TestCase
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
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id, 'phone' => '081234']);
        $kurir = User::factory()->kurir()->create(['agent_id' => $agen->id]);
        Courier::create(['type' => 'internal', 'user_id' => $kurir->id, 'agent_id' => $agen->id, 'name' => $kurir->name, 'is_active' => true]);

        return compact('agen', 'admin', 'keuangan', 'konsumen', 'kurir');
    }

    private function placeOrder(User $konsumen, string $paymentMethod = 'cod'): Order
    {
        $product = Product::create(['sku' => 'TEST-'.\Illuminate\Support\Str::uuid(), 
            'name' => 'Kue Detail', 'slug' => Str::slug('Kue Detail').'-'.uniqid(),
            'has_variations' => false, 'base_price' => 40000, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $konsumen->agent_id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => $paymentMethod,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);
        $response->assertCreated();

        return Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
    }

    /* ---------------------------------------------------------------
     * Order detail resource additions
     * ------------------------------------------------------------- */

    public function test_order_detail_exposes_konsumen_block_and_delivery_coordinates(): void
    {
        $branch = $this->makeAgentBranch();
        $order = $this->placeOrder($branch['konsumen']);

        $response = $this->actingAs($branch['admin'])->getJson("/api/v1/orders/{$order->id}");
        $response->assertOk();

        $this->assertSame($branch['konsumen']->name, $response->json('data.konsumen.name'));
        $this->assertSame('081234', $response->json('data.konsumen.phone'));
        $this->assertNotNull($response->json('data.latitude'));
        $this->assertNotNull($response->json('data.longitude'));
    }

    /* ---------------------------------------------------------------
     * Kurir delivery proof
     * ------------------------------------------------------------- */

    public function test_kurir_cannot_mark_terkirim_without_a_delivery_proof_photo(): void
    {
        $branch = $this->makeAgentBranch();
        $order = $this->placeOrder($branch['konsumen']);

        $shipmentId = Shipment::where('order_id', $order->id)->value('id');
        $this->actingAs($branch['kurir'])->patchJson("/api/v1/shipments/{$shipmentId}/status", ['status' => 'dikirim'])->assertOk();

        $this->actingAs($branch['kurir'])->patchJson("/api/v1/shipments/{$shipmentId}/status", ['status' => 'terkirim'])
            ->assertStatus(422);

        $this->assertSame('dikirim', OrderItem::where('order_id', $order->id)->value('status'));
    }

    public function test_kurir_can_mark_terkirim_with_a_delivery_proof_photo_and_it_is_exposed_on_the_order(): void
    {
        $branch = $this->makeAgentBranch();
        $order = $this->placeOrder($branch['konsumen']);

        $shipmentId = Shipment::where('order_id', $order->id)->value('id');
        $this->actingAs($branch['kurir'])->patchJson("/api/v1/shipments/{$shipmentId}/status", ['status' => 'dikirim'])->assertOk();

        $this->actingAs($branch['kurir'])->patch("/api/v1/shipments/{$shipmentId}/status", [
            'status' => 'terkirim', 'proof' => UploadedFile::fake()->image('bukti.jpg'),
        ])->assertOk();

        $this->assertSame('terkirim', OrderItem::where('order_id', $order->id)->value('status'));
        $this->assertDatabaseHas('shipments', ['id' => $shipmentId, 'status' => 'delivered']);

        $view = $this->actingAs($branch['admin'])->getJson("/api/v1/orders/{$order->id}");
        $view->assertOk();
        $this->assertNotNull($view->json('data.items.0.delivery_proof_url'));
    }

    /* ---------------------------------------------------------------
     * COD payment proof
     * ------------------------------------------------------------- */

    public function test_konsumen_can_submit_cod_proof_and_it_does_not_mark_the_order_paid_by_itself(): void
    {
        $branch = $this->makeAgentBranch();
        $order = $this->placeOrder($branch['konsumen'], 'cod');

        $response = $this->actingAs($branch['konsumen'])->post("/api/v1/orders/{$order->id}/payment/cod-proof", [
            'proof' => UploadedFile::fake()->image('cod-proof.jpg'),
        ]);
        $response->assertOk();

        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertDatabaseHas('cod_payment_proofs', ['status' => 'pending']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'payment.cod_proof_submitted']);
    }

    public function test_keuangan_confirming_a_cod_proof_marks_the_order_paid(): void
    {
        $branch = $this->makeAgentBranch();
        $order = $this->placeOrder($branch['konsumen'], 'cod');

        $this->actingAs($branch['konsumen'])->post("/api/v1/orders/{$order->id}/payment/cod-proof", [
            'proof' => UploadedFile::fake()->image('cod-proof.jpg'),
        ])->assertOk();

        $proofId = CodPaymentProof::first()->id;

        $confirm = $this->actingAs($branch['keuangan'])->patchJson("/api/v1/admin/cod-payment-proofs/{$proofId}/confirm", [
            'confirmed' => true,
        ]);
        $confirm->assertOk();
        // Same relations as show()/updateStatus() — the order-detail page renders straight
        // off this response and crashes (blank screen) if 'items'/'couriers' are missing.
        $this->assertNotNull($confirm->json('data.items'));
        $this->assertNotNull($confirm->json('data.couriers'));

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertDatabaseHas('cod_payment_proofs', ['id' => $proofId, 'status' => 'confirmed']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'payment.cod_proof_confirmed']);
    }

    public function test_keuangan_can_reject_a_cod_proof_without_marking_the_order_paid(): void
    {
        $branch = $this->makeAgentBranch();
        $order = $this->placeOrder($branch['konsumen'], 'cod');

        $this->actingAs($branch['konsumen'])->post("/api/v1/orders/{$order->id}/payment/cod-proof", [
            'proof' => UploadedFile::fake()->image('cod-proof.jpg'),
        ])->assertOk();
        $proofId = CodPaymentProof::first()->id;

        $this->actingAs($branch['keuangan'])->patchJson("/api/v1/admin/cod-payment-proofs/{$proofId}/confirm", [
            'confirmed' => false, 'rejection_reason' => 'Foto tidak jelas',
        ])->assertOk();

        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertDatabaseHas('cod_payment_proofs', ['id' => $proofId, 'status' => 'rejected']);
    }

    public function test_only_the_owning_konsumen_may_submit_cod_proof_for_their_order(): void
    {
        $branch = $this->makeAgentBranch();
        $order = $this->placeOrder($branch['konsumen'], 'cod');
        $otherKonsumen = User::factory()->konsumen()->create(['agent_id' => $branch['agen']->id]);

        $this->actingAs($otherKonsumen)->post("/api/v1/orders/{$order->id}/payment/cod-proof", [
            'proof' => UploadedFile::fake()->image('cod-proof.jpg'),
        ])->assertStatus(403);
    }

    public function test_cod_proof_cannot_be_submitted_for_a_non_cod_order(): void
    {
        $branch = $this->makeAgentBranch();
        $bankTransfer = PaymentMethod::where('code', 'bank_transfer')->firstOrFail();
        AgentPaymentGatewayConfig::create([
            'agent_id' => $branch['agen']->id, 'payment_method_id' => $bankTransfer->id, 'environment' => 'sandbox',
            'config' => ['bank_name' => 'BCA', 'account_name' => 'QA', 'account_number' => '123'],
        ]);
        $order = $this->placeOrder($branch['konsumen'], 'bank_transfer');

        $this->actingAs($branch['konsumen'])->post("/api/v1/orders/{$order->id}/payment/cod-proof", [
            'proof' => UploadedFile::fake()->image('cod-proof.jpg'),
        ])->assertStatus(422);
    }

    public function test_only_keuangan_may_confirm_a_cod_proof(): void
    {
        $branch = $this->makeAgentBranch();
        $order = $this->placeOrder($branch['konsumen'], 'cod');

        $this->actingAs($branch['konsumen'])->post("/api/v1/orders/{$order->id}/payment/cod-proof", [
            'proof' => UploadedFile::fake()->image('cod-proof.jpg'),
        ])->assertOk();
        $proofId = CodPaymentProof::first()->id;

        // Separation of duties — operational roles and the konsumen are refused.
        foreach (['kurir', 'konsumen', 'admin', 'agen'] as $role) {
            $this->actingAs($branch[$role])->patchJson("/api/v1/admin/cod-payment-proofs/{$proofId}/confirm", ['confirmed' => true])
                ->assertStatus(403);
        }

        // Keuangan holds the authority.
        $this->actingAs($branch['keuangan'])->patchJson("/api/v1/admin/cod-payment-proofs/{$proofId}/confirm", ['confirmed' => true])
            ->assertOk();
    }

    /* ---------------------------------------------------------------
     * Return evidence is now mandatory
     * ------------------------------------------------------------- */

    public function test_return_request_without_evidence_photo_is_rejected(): void
    {
        $branch = $this->makeAgentBranch();
        $order = $this->placeOrder($branch['konsumen']);
        $shipmentId = Shipment::where('order_id', $order->id)->value('id');
        $this->actingAs($branch['kurir'])->patch("/api/v1/shipments/{$shipmentId}/status", ['status' => 'dikirim'])->assertOk();
        $this->actingAs($branch['kurir'])->patch("/api/v1/shipments/{$shipmentId}/status", [
            'status' => 'terkirim', 'proof' => UploadedFile::fake()->image('bukti.jpg'),
        ])->assertOk();

        $item = OrderItem::where('order_id', $order->id)->firstOrFail();

        $this->actingAs($branch['konsumen'])->postJson("/api/v1/orders/{$order->id}/returns", [
            'reason' => 'Rusak', 'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
        ])->assertStatus(422);
    }
}
