<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AgentPaymentGatewayConfig;
use App\Models\AgentProfile;
use App\Models\CodPaymentProof;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ShippingConfiguration;
use App\Models\User;
use App\Services\Referral\ReferralReassignmentService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * IMP-001 Feature B: Korsal/Sales pay ON BEHALF of a konsumen inside their own referral scope.
 * The order owner never changes, nothing is marked paid by the payer.
 * UAT-008: in-scope Sales/Korsal/Sales-Kurir-Sub additionally verify the
 * required payment/pelunasan through the canonical PaymentService
 * (server-side payOnBehalf scope); the direct COD paid/unpaid toggle stays
 * keuangan-only.
 */
class AssistedConsumerPaymentTest extends TestCase
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

    private function branch(string $label = 'A'): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko '.$label, 'address' => 'Jl. '.$label,
            'latitude' => -6.2, 'longitude' => 106.8166,
        ]);
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $agen->id]);
        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $sales = User::factory()->sales()->create(['agent_id' => $agen->id, 'parent_id' => $korsal->id, 'korsal_id' => $korsal->id]);
        $konsumen = User::factory()->konsumen()->create([
            'agent_id' => $agen->id, 'parent_id' => $sales->id, 'korsal_id' => $korsal->id, 'sales_id' => $sales->id,
        ]);

        $bank = PaymentMethod::query()->where('code', 'bank_transfer')->firstOrFail();
        AgentPaymentGatewayConfig::create([
            'agent_id' => $agen->id, 'payment_method_id' => $bank->id, 'environment' => 'sandbox',
            'config' => ['bank_name' => 'BCA', 'account_name' => 'PT Prime', 'account_number' => '123456'],
        ]);
        ShippingConfiguration::create([
            'agent_id' => $agen->id, 'price_per_km' => 2000, 'minimum_distance_km' => 0,
            'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true,
        ]);

        return compact('agen', 'keuangan', 'korsal', 'sales', 'konsumen');
    }

    private function placeOrder(User $konsumen, User $agen, string $method = 'bank_transfer'): Order
    {
        $product = Product::create([
            'sku' => 'T-'.Str::uuid(), 'name' => 'Kue', 'slug' => 'kue-'.uniqid(),
            'has_variations' => false, 'base_price' => 100000, 'weight_grams' => 1000, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);

        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => $method,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810,
            ]);
        $response->assertCreated();

        return Order::query()->withoutGlobalScopes()->findOrFail($response->json('data.id'));
    }

    private function proof(User $actor, Order $order, string $path = 'payment/proof'): TestResponse
    {
        return $this->actingAs($actor)->postJson("/api/v1/orders/{$order->id}/{$path}", [
            'proof' => UploadedFile::fake()->image('proof.jpg'),
        ]);
    }

    public function test_sales_in_scope_submits_a_proof_on_behalf_without_paying_or_taking_ownership(): void
    {
        ['agen' => $agen, 'sales' => $sales, 'konsumen' => $konsumen] = $this->branch();
        $order = $this->placeOrder($konsumen, $agen);

        $response = $this->proof($sales, $order)->assertOk();

        $response->assertJsonPath('data.transaction.bank_transfer_verification.status', 'pending')
            ->assertJsonPath('data.transaction.bank_transfer_verification.submitted_on_behalf', true)
            ->assertJsonPath('data.transaction.bank_transfer_verification.submitted_by.id', $sales->id);

        $order->refresh();
        $this->assertSame($konsumen->id, $order->konsumen_id, 'order owner is never rewritten to the payer');
        $this->assertSame('pending_verification', $order->payment_status);
        $this->assertSame(0.0, (float) $order->paid_amount, 'the payer cannot mark anything paid');

        $log = ActivityLog::query()->where('event', 'payment.proof_submitted')->latest('id')->firstOrFail();
        $this->assertSame($sales->id, $log->causer_id);
        $this->assertSame($konsumen->id, $log->properties['on_behalf_of_konsumen_id']);
    }

    public function test_korsal_in_scope_submits_via_sales_downline_and_via_direct_referral(): void
    {
        ['agen' => $agen, 'korsal' => $korsal, 'konsumen' => $viaSales] = $this->branch();
        $direct = User::factory()->konsumen()->create([
            'agent_id' => $agen->id, 'parent_id' => $korsal->id, 'korsal_id' => $korsal->id, 'sales_id' => null,
        ]);

        $this->proof($korsal, $this->placeOrder($viaSales, $agen))->assertOk();
        $this->proof($korsal, $this->placeOrder($direct, $agen))->assertOk();
    }

    public function test_out_of_scope_payers_are_denied(): void
    {
        ['agen' => $agen, 'korsal' => $korsal, 'sales' => $sales, 'konsumen' => $konsumen] = $this->branch();
        $order = $this->placeOrder($konsumen, $agen);

        $otherSales = User::factory()->sales()->create(['agent_id' => $agen->id, 'parent_id' => $korsal->id, 'korsal_id' => $korsal->id]);
        $otherKorsal = User::factory()->korsal()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $this->proof($otherSales, $order)->assertForbidden();
        $this->proof($otherKorsal, $order)->assertForbidden();

        $b = $this->branch('B');
        $this->assertContains($this->proof($b['sales'], $order)->status(), [403, 404]);
        $this->assertContains($this->proof($b['korsal'], $order)->status(), [403, 404]);

        $this->assertDatabaseCount('bank_transfer_verifications', 0);
    }

    public function test_scope_follows_the_current_referral_after_an_audited_reassignment(): void
    {
        ['agen' => $agen, 'korsal' => $korsal, 'sales' => $oldSales, 'konsumen' => $konsumen] = $this->branch();
        $order = $this->placeOrder($konsumen, $agen);
        $newSales = User::factory()->sales()->create(['agent_id' => $agen->id, 'parent_id' => $korsal->id, 'korsal_id' => $korsal->id]);

        app(ReferralReassignmentService::class)->reassignKonsumenToSales($konsumen, $newSales->id, $agen);

        $this->proof($oldSales, $order)->assertForbidden();
        $this->proof($newSales, $order)->assertOk();
    }

    /** IMP-004 (deferred from IMP-001 §24): the NEW sales may also OPEN the older order's detail after an audited reassignment; the old sales keeps its historical snapshot access. */
    public function test_order_view_follows_the_current_referral_after_an_audited_reassignment(): void
    {
        ['agen' => $agen, 'korsal' => $korsal, 'sales' => $oldSales, 'konsumen' => $konsumen] = $this->branch();
        $order = $this->placeOrder($konsumen, $agen);
        $this->assertSame($oldSales->id, $order->sales_id, 'the order snapshot still records the ORIGINAL sales');

        $newSales = User::factory()->sales()->create(['agent_id' => $agen->id, 'parent_id' => $korsal->id, 'korsal_id' => $korsal->id]);
        app(ReferralReassignmentService::class)->reassignKonsumenToSales($konsumen, $newSales->id, $agen);

        // The new sales can now open the order detail (current referral chain).
        $this->actingAs($newSales)->getJson("/api/v1/orders/{$order->id}")->assertOk();

        // The old sales KEEPS access to its historical snapshot (never silently
        // remap historical ownership — audit continuity; the order's sales_id
        // is preserved).
        $this->actingAs($oldSales)->getJson("/api/v1/orders/{$order->id}")->assertOk();

        // A sales that is neither the snapshot owner nor the current scope is denied.
        $thirdSales = User::factory()->sales()->create(['agent_id' => $agen->id, 'parent_id' => $korsal->id, 'korsal_id' => $korsal->id]);
        $this->actingAs($thirdSales)->getJson("/api/v1/orders/{$order->id}")->assertForbidden();

        // Cross-agent sales is never granted the reassigned order.
        $b = $this->branch('B');
        $this->actingAs($b['sales'])->getJson("/api/v1/orders/{$order->id}")->assertStatus(404);
    }

    /** IMP-004 symmetric korsal leg: when the sales is audited-reassigned to a new korsal, the new korsal can view the konsumen's orders (current chain) while the old korsal keeps its snapshot. */
    public function test_order_view_follows_the_current_korsal_after_a_sales_reassignment(): void
    {
        ['agen' => $agen, 'korsal' => $oldKorsal, 'sales' => $sales, 'konsumen' => $konsumen] = $this->branch();
        $order = $this->placeOrder($konsumen, $agen);
        $this->assertSame($oldKorsal->id, $order->korsal_id, 'order snapshot records the ORIGINAL korsal');

        $newKorsal = User::factory()->korsal()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        app(ReferralReassignmentService::class)->reassignSalesToKorsal($sales, $newKorsal->id, $agen);

        // The new korsal sees the order (konsumen's current korsal_id is the new one).
        $this->actingAs($newKorsal)->getJson("/api/v1/orders/{$order->id}")->assertOk();

        // The old korsal keeps historical snapshot access; unrelated korsal is denied.
        $this->actingAs($oldKorsal)->getJson("/api/v1/orders/{$order->id}")->assertOk();
        $unrelated = User::factory()->korsal()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $this->actingAs($unrelated)->getJson("/api/v1/orders/{$order->id}")->assertForbidden();
    }

    public function test_sales_and_korsal_are_not_payment_approvers_even_in_scope(): void
    {
        // Human rule: Sales/Korsal may upload proof in scope but can NEVER approve/reject (route gate AND controller).
        ['agen' => $agen, 'sales' => $sales, 'korsal' => $korsal, 'konsumen' => $konsumen, 'keuangan' => $keuangan] = $this->branch();
        $order = $this->placeOrder($konsumen, $agen);
        $this->proof($sales, $order)->assertOk();

        foreach ([$sales, $korsal] as $actor) {
            $this->actingAs($actor)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertForbidden();
            $this->actingAs($actor)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => false, 'rejection_reason' => 'x'])->assertForbidden();
        }
        $order->refresh();
        $this->assertSame(0.0, (float) $order->paid_amount);
        $this->assertSame('pending_verification', $order->payment_status);

        $this->actingAs($keuangan)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertOk();
        $this->assertSame((float) $order->fresh()->total_amount, (float) $order->fresh()->paid_amount);
        $log = ActivityLog::query()->where('event', 'payment.bank_transfer_verified')->latest('id')->firstOrFail();
        $this->assertSame($keuangan->id, $log->causer_id);
    }

    public function test_keuangan_verification_still_applies_and_a_verified_proof_cannot_be_overwritten(): void
    {
        ['agen' => $agen, 'keuangan' => $keuangan, 'sales' => $sales, 'konsumen' => $konsumen] = $this->branch();
        $order = $this->placeOrder($konsumen, $agen);
        $this->proof($sales, $order)->assertOk();

        $this->actingAs($keuangan)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertOk();
        $order->refresh();
        $this->assertSame('paid', $order->payment_status);

        $this->proof($sales, $order)->assertStatus(422);
        $this->proof($konsumen, $order)->assertStatus(422);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_cancelled_order_refuses_a_new_proof(): void
    {
        ['agen' => $agen, 'sales' => $sales, 'konsumen' => $konsumen] = $this->branch();
        $order = $this->placeOrder($konsumen, $agen);
        $order->update(['status' => 'dibatalkan']);

        $this->proof($sales, $order)->assertStatus(422);
    }

    public function test_konsumen_own_submission_is_recorded_as_not_on_behalf(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        $order = $this->placeOrder($konsumen, $agen);

        $this->proof($konsumen, $order)->assertOk()
            ->assertJsonPath('data.transaction.bank_transfer_verification.submitted_on_behalf', false)
            ->assertJsonPath('data.transaction.bank_transfer_verification.submitted_by.id', $konsumen->id);
    }

    public function test_sales_uploads_cod_proof_but_cannot_confirm_it(): void
    {
        ['agen' => $agen, 'sales' => $sales, 'korsal' => $korsal, 'konsumen' => $konsumen, 'keuangan' => $keuangan] = $this->branch();
        $order = $this->placeOrder($konsumen, $agen, 'cod');

        $this->proof($sales, $order, 'payment/cod-proof')->assertOk()
            ->assertJsonPath('data.transaction.cod_payment_proof.submitted_on_behalf', true);
        $proof = CodPaymentProof::query()->firstOrFail();
        $this->assertSame($sales->id, $proof->submitted_by_user_id);

        foreach ([$sales, $korsal] as $actor) {
            $this->actingAs($actor)->patchJson("/api/v1/admin/cod-payment-proofs/{$proof->id}/confirm", ['confirmed' => true])->assertForbidden();
            $this->actingAs($actor)->patchJson("/api/v1/admin/cod-payment-proofs/{$proof->id}/confirm", ['confirmed' => false, 'rejection_reason' => 'x'])->assertForbidden();
        }
        $this->assertSame('unpaid', $order->fresh()->payment_status);

        $this->actingAs($keuangan)->patchJson("/api/v1/admin/cod-payment-proofs/{$proof->id}/confirm", ['confirmed' => true])->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_sales_kurir_sub_in_scope_is_allowed_and_unrelated_or_cross_agent_is_denied(): void
    {
        ['agen' => $agen, 'korsal' => $korsal, 'konsumen' => $konsumen] = $this->branch();
        $sub = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id, 'parent_id' => $korsal->id, 'korsal_id' => $korsal->id]);
        $referred = User::factory()->konsumen()->create([
            'agent_id' => $agen->id, 'parent_id' => $sub->id, 'korsal_id' => $korsal->id, 'sales_id' => $sub->id,
        ]);
        $unrelatedSub = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id, 'parent_id' => $korsal->id, 'korsal_id' => $korsal->id]);

        $own = $this->placeOrder($referred, $agen);
        $this->proof($sub, $own)->assertOk()->assertJsonPath('data.transaction.bank_transfer_verification.submitted_by.id', $sub->id);
        $this->proof($unrelatedSub, $own)->assertForbidden();
        // The same Sub cannot pay for a konsumen that belongs to someone else.
        $this->proof($sub, $this->placeOrder($konsumen, $agen))->assertForbidden();

        $b = $this->branch('B');
        $foreignSub = User::factory()->salesKurirSub()->create(['agent_id' => $b['agen']->id, 'parent_id' => $b['korsal']->id, 'korsal_id' => $b['korsal']->id]);
        $this->assertContains($this->proof($foreignSub, $own)->status(), [403, 404]);
    }

    public function test_forged_scope_fields_in_the_request_are_ignored(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        $order = $this->placeOrder($konsumen, $agen);
        $outsider = User::factory()->sales()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);

        $this->actingAs($outsider)->postJson("/api/v1/orders/{$order->id}/payment/proof", [
            'proof' => UploadedFile::fake()->image('p.jpg'),
            'konsumen_id' => $konsumen->id, 'sales_id' => $outsider->id, 'on_behalf_of' => $konsumen->id,
        ])->assertForbidden();
        $this->assertDatabaseCount('bank_transfer_verifications', 0);
    }

    public function test_dp_assisted_proof_keeps_canonical_partial_payment_and_remaining_balance(): void
    {
        ['agen' => $agen, 'keuangan' => $keuangan, 'sales' => $sales, 'konsumen' => $konsumen] = $this->branch();
        $product = Product::create([
            'sku' => 'T-'.Str::uuid(), 'name' => 'Kue DP', 'slug' => 'kue-dp-'.uniqid(),
            'has_variations' => false, 'base_price' => 1000000, 'weight_grams' => 1000, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);
        $created = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'down_payment', 'dp_amount' => 300000,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810,
        ])->assertCreated();
        $order = Order::query()->withoutGlobalScopes()->findOrFail($created->json('data.id'));

        $this->proof($sales, $order)->assertOk();
        $this->assertSame(0.0, (float) $order->fresh()->paid_amount, 'nothing counts until Keuangan verifies');

        $this->actingAs($keuangan)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertOk();
        $order->refresh();
        $this->assertSame('partially_paid', $order->payment_status);
        $this->assertSame(300000.0, (float) $order->paid_amount);
        $this->assertSame((float) $order->total_amount - 300000.0, (float) $order->remaining_amount);

        // Re-submitting the verified DP proof must not be able to double-count it on a second verification.
        $this->proof($sales, $order)->assertStatus(422);
        $this->assertSame(300000.0, (float) $order->fresh()->paid_amount);
    }
}
