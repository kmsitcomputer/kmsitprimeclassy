<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AgentPaymentGatewayConfig;
use App\Models\AgentProfile;
use App\Models\BankTransferVerification;
use App\Models\Order;
use App\Models\OrderDueDateChange;
use App\Models\OrderInvoiceSnapshot;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ShippingConfiguration;
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
 * MASTER V2 — Improvements 05–11 and 13 (payment core): installments, actual-amount verification,
 * cash receipts, per-order due date, invoice issuance policy, private proofs and upload limits.
 */
class MasterV2PaymentCoreTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    /** @var array<string, User|Product> */
    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        Storage::fake('public');
        Storage::fake('local');
        $this->b = $this->branch();
    }

    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Toko', 'address' => 'Jl. X', 'latitude' => -6.2, 'longitude' => 106.8166]);
        $bank = PaymentMethod::query()->where('code', 'bank_transfer')->firstOrFail();
        AgentPaymentGatewayConfig::create([
            'agent_id' => $agen->id, 'payment_method_id' => $bank->id, 'environment' => 'sandbox',
            'config' => ['bank_name' => 'BCA', 'account_name' => 'PT Prime', 'account_number' => '123456'],
        ]);
        ShippingConfiguration::create([
            'agent_id' => $agen->id, 'price_per_km' => 2000, 'minimum_distance_km' => 0,
            'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true,
        ]);
        $product = Product::create([
            'sku' => 'MV2-'.Str::uuid(), 'name' => 'Kue', 'slug' => 'kue-mv2-'.uniqid(),
            'has_variations' => false, 'base_price' => 500000, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 50, 'quantity_reserved' => 0]);

        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $sales = User::factory()->sales()->create(['agent_id' => $agen->id, 'parent_id' => $korsal->id, 'korsal_id' => $korsal->id]);

        return [
            'agen' => $agen, 'product' => $product, 'korsal' => $korsal, 'sales' => $sales,
            'admin' => User::factory()->admin()->create(['agent_id' => $agen->id]),
            'keuangan' => User::factory()->keuangan()->create(['agent_id' => $agen->id]),
            'konsumen' => User::factory()->konsumen()->create([
                'agent_id' => $agen->id, 'parent_id' => $sales->id, 'korsal_id' => $korsal->id, 'sales_id' => $sales->id,
            ]),
        ];
    }

    private function payload(string $method = 'bank_transfer'): array
    {
        return [
            'payment_method_code' => $method,
            'items' => [['product_id' => $this->b['product']->id, 'quantity' => 1]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810,
        ];
    }

    /** Konsumen checks out with Bank Transfer (the only method they are offered). */
    private function transferOrder(): Order
    {
        $this->actingAs($this->b['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', $this->payload())->assertCreated();

        return Order::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function proof(User $as, Order $order, string $name = 'p.jpg')
    {
        return $this->actingAs($as)->postJson("/api/v1/orders/{$order->id}/payment/proof", ['proof' => UploadedFile::fake()->image($name)]);
    }

    private function verify(User $as, Order $order, array $extra = [])
    {
        return $this->actingAs($as)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true] + $extra);
    }

    // ---- Improvement 07 / Scenario D ---------------------------------------------------------------

    public function test_finance_verifies_the_actual_amount_and_the_submitted_amount_is_preserved(): void
    {
        $order = $this->transferOrder();
        $total = (float) $order->total_amount;
        $this->assertGreaterThan(200000, $total);
        $this->proof($this->b['konsumen'], $order)->assertOk();

        // A different actual amount demands a reason.
        $this->verify($this->b['keuangan'], $order, ['verified_amount' => 200000])->assertStatus(422);
        $this->assertSame('pending', BankTransferVerification::query()->latest('id')->first()->status);

        $this->verify($this->b['keuangan'], $order, ['verified_amount' => 200000, 'amount_adjustment_reason' => 'Dana masuk Rp200.000'])->assertOk();

        $v = BankTransferVerification::query()->latest('id')->first();
        $this->assertSame('verified', $v->status);
        $this->assertEquals($total, (float) $v->paymentTransaction->amount, 'submitted amount is never overwritten');
        $this->assertEquals(200000, (float) $v->verified_amount);
        $this->assertSame($this->b['keuangan']->id, $v->verified_by);
        $this->assertNotNull($v->verified_at);

        $fresh = $order->fresh();
        $this->assertEquals(200000, (float) $fresh->paid_amount);
        $this->assertEquals($total - 200000, (float) $fresh->remaining_amount);
        $this->assertEquals($total, (float) $fresh->total_amount);
        $this->assertSame('partially_paid', $fresh->payment_status);

        // Second verification is refused (no double counting).
        $this->verify($this->b['keuangan'], $order)->assertStatus(422);
        $this->assertEquals(200000, (float) $order->fresh()->paid_amount);
    }

    public function test_verified_amount_cannot_exceed_the_outstanding_balance(): void
    {
        $order = $this->transferOrder();
        $this->proof($this->b['konsumen'], $order)->assertOk();

        $this->verify($this->b['keuangan'], $order, ['verified_amount' => (float) $order->total_amount + 1, 'amount_adjustment_reason' => 'x'])->assertStatus(422);
        $this->assertEquals(0, (float) $order->fresh()->paid_amount);
    }

    public function test_self_verification_is_forbidden_and_admin_cannot_verify(): void
    {
        $order = $this->transferOrder();
        // Keuangan records the proof himself -> must not approve his own record.
        $this->proof($this->b['keuangan'], $order)->assertOk();
        $this->verify($this->b['keuangan'], $order)->assertForbidden();

        $this->verify($this->b['admin'], $order)->assertForbidden();
        $this->verify($this->b['sales'], $order)->assertForbidden();
        $this->assertEquals(0, (float) $order->fresh()->paid_amount);
    }

    // ---- Improvement 11 / Scenario B,F -------------------------------------------------------------

    public function test_transfer_invoice_is_issued_once_on_the_first_verified_payment_and_never_reissued(): void
    {
        $order = $this->transferOrder();

        // Pending first payment: no invoice.
        $this->proof($this->b['konsumen'], $order)->assertOk();
        $this->assertNull($order->fresh()->invoice_issued_at);
        $this->actingAs($this->b['konsumen'])->getJson("/api/v1/orders/{$order->id}/invoice")->assertStatus(409);

        // Rejected first payment: still no invoice.
        $this->actingAs($this->b['keuangan'])->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => false, 'rejection_reason' => 'buram'])->assertOk();
        $this->assertNull($order->fresh()->invoice_issued_at);

        // Re-submit, then a PARTIAL verified payment issues the invoice.
        $this->proof($this->b['konsumen'], $order)->assertOk();
        $this->verify($this->b['keuangan'], $order, ['verified_amount' => 100000, 'amount_adjustment_reason' => 'sebagian'])->assertOk();

        $issuedAt = $order->fresh()->invoice_issued_at;
        $this->assertNotNull($issuedAt);
        $this->assertSame(1, OrderInvoiceSnapshot::query()->where('order_id', $order->id)->count());

        // Later installment: same invoice (number + issue time), one more balance snapshot.
        $order->update(['status' => 'diproses']);
        $remaining = (float) $order->fresh()->remaining_amount;
        $this->actingAs($this->b['konsumen'])->postJson("/api/v1/orders/{$order->id}/payment/installment", ['amount' => $remaining])->assertOk();
        $this->proof($this->b['konsumen'], $order)->assertOk();
        $this->verify($this->b['keuangan'], $order)->assertOk();

        $final = $order->fresh();
        $this->assertSame('paid', $final->payment_status);
        $this->assertEquals($issuedAt->toDateTimeString(), $final->invoice_issued_at->toDateTimeString());
        $this->assertSame(2, OrderInvoiceSnapshot::query()->where('order_id', $order->id)->count());
        $this->assertSame([1, 2], OrderInvoiceSnapshot::query()->where('order_id', $order->id)->orderBy('version')->pluck('version')->all());
        $this->assertSame(1, ActivityLog::query()->where('event', 'invoice.issued')->where('subject_id', $order->id)->count());
        $this->actingAs($this->b['konsumen'])->get("/api/v1/orders/{$order->id}/invoice")->assertOk();
    }

    public function test_cod_invoice_is_issued_when_the_order_is_created(): void
    {
        $this->actingAs($this->b['agen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', ['konsumen_id' => $this->b['konsumen']->id] + $this->payload('cod'))->assertCreated();
        $order = Order::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertNotNull($order->invoice_issued_at);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame(1, OrderInvoiceSnapshot::query()->where('order_id', $order->id)->count());
    }

    // ---- Improvement 05 / 06 / 08 ------------------------------------------------------------------

    public function test_installment_rules_status_amount_and_idempotency(): void
    {
        $order = $this->transferOrder();
        $this->proof($this->b['konsumen'], $order)->assertOk();
        $this->verify($this->b['keuangan'], $order, ['verified_amount' => 100000, 'amount_adjustment_reason' => 'DP'])->assertOk();
        $remaining = (float) $order->fresh()->remaining_amount;

        // Not yet in fulfilment (diterima): refused.
        $this->actingAs($this->b['konsumen'])->postJson("/api/v1/orders/{$order->id}/payment/installment", ['amount' => 1000])->assertStatus(422);

        $order->update(['status' => 'diproses']);
        $url = "/api/v1/orders/{$order->id}/payment/installment";
        $this->actingAs($this->b['konsumen'])->postJson($url, ['amount' => $remaining + 1])->assertStatus(422);
        $this->actingAs($this->b['konsumen'])->postJson($url, ['amount' => 0])->assertStatus(422);

        $first = $this->actingAs($this->b['konsumen'])->postJson($url, ['amount' => 50000])->assertOk();
        $this->assertFalse($first->json('data.replay'));
        $replay = $this->actingAs($this->b['konsumen'])->postJson($url, ['amount' => 50000])->assertOk();
        $this->assertTrue($replay->json('data.replay'));
        $this->assertSame($first->json('data.transaction.id'), $replay->json('data.transaction.id'));
        // A different nominal while one is unresolved is refused; pending money never reduces the balance.
        $this->actingAs($this->b['konsumen'])->postJson($url, ['amount' => 60000])->assertStatus(422);
        $this->assertEquals($remaining, (float) $order->fresh()->remaining_amount);

        // Another konsumen cannot touch it.
        $other = User::factory()->konsumen()->create(['agent_id' => $this->b['agen']->id]);
        $this->actingAs($other)->postJson($url, ['amount' => 50000])->assertForbidden();
    }

    public function test_sales_records_cash_but_cannot_verify_and_finance_verifies_once(): void
    {
        $order = $this->transferOrder();
        $this->proof($this->b['konsumen'], $order)->assertOk();
        $this->verify($this->b['keuangan'], $order, ['verified_amount' => 100000, 'amount_adjustment_reason' => 'DP'])->assertOk();
        $order->update(['status' => 'diproses']);
        $before = (float) $order->fresh()->remaining_amount;
        $url = "/api/v1/orders/{$order->id}/payment/installment";

        // Cash needs a receipt reference or photo.
        $this->actingAs($this->b['sales'])->postJson($url, ['amount' => 100000, 'channel' => 'cash'])->assertStatus(422);
        // The konsumen themselves never record cash.
        $this->actingAs($this->b['konsumen'])->postJson($url, ['amount' => 100000, 'channel' => 'cash', 'receipt_reference' => 'T-1'])->assertForbidden();
        // Out-of-scope sales is refused.
        $strangerSales = User::factory()->sales()->create(['agent_id' => $this->b['agen']->id, 'parent_id' => $this->b['korsal']->id, 'korsal_id' => $this->b['korsal']->id]);
        $this->actingAs($strangerSales)->postJson($url, ['amount' => 100000, 'channel' => 'cash', 'receipt_reference' => 'T-1'])->assertForbidden();

        $this->actingAs($this->b['sales'])->postJson($url, ['amount' => 100000, 'channel' => 'cash', 'receipt_reference' => 'KWI-001'])->assertOk();
        $v = BankTransferVerification::query()->latest('id')->first();
        $this->assertSame('cash', $v->channel);
        $this->assertSame('pending', $v->status);
        $this->assertSame($this->b['sales']->id, $v->cash_received_by_user_id);
        $this->assertSame($this->b['sales']->id, $v->submitted_by_user_id);
        $this->assertNotNull($v->cash_received_at);
        $this->assertSame('KWI-001', $v->receipt_reference);
        $this->assertEquals($before, (float) $order->fresh()->remaining_amount, 'pending cash does not reduce the receivable');

        // Sales can not approve their own record (route gate).
        $this->verify($this->b['sales'], $order)->assertForbidden();
        // Finance approves a smaller actual amount; counted exactly once.
        $this->verify($this->b['keuangan'], $order, ['verified_amount' => 80000, 'amount_adjustment_reason' => 'kurang setor'])->assertOk();
        $this->assertEquals($before - 80000, (float) $order->fresh()->remaining_amount);
        $this->verify($this->b['keuangan'], $order)->assertStatus(422);
        $this->assertEquals($before - 80000, (float) $order->fresh()->remaining_amount);
    }

    public function test_fulfilment_proceeds_while_the_receivable_stays_open(): void
    {
        $order = $this->transferOrder();
        $this->proof($this->b['konsumen'], $order)->assertOk();
        $this->verify($this->b['keuangan'], $order, ['verified_amount' => 100000, 'amount_adjustment_reason' => 'DP'])->assertOk();

        $order->update(['status' => 'terkirim']);
        $fresh = $order->fresh();
        $this->assertSame('partially_paid', $fresh->payment_status);
        $this->assertGreaterThan(0, (float) $fresh->remaining_amount);
    }

    // ---- Improvement 10 ----------------------------------------------------------------------------

    public function test_due_date_is_per_order_audited_and_restricted_to_admin_and_finance(): void
    {
        $order = $this->transferOrder();
        $this->proof($this->b['konsumen'], $order)->assertOk();
        $this->verify($this->b['keuangan'], $order, ['verified_amount' => 100000, 'amount_adjustment_reason' => 'DP'])->assertOk();
        $issuedAt = $order->fresh()->invoice_issued_at;
        $url = "/api/v1/orders/{$order->id}/due-date";
        $date = now()->addDays(10)->toDateString();

        $this->actingAs($this->b['sales'])->putJson($url, ['due_date' => $date, 'reason' => 'x'])->assertForbidden();
        $this->actingAs($this->b['konsumen'])->putJson($url, ['due_date' => $date, 'reason' => 'x'])->assertForbidden();
        $this->actingAs($this->b['admin'])->putJson($url, ['due_date' => $date])->assertStatus(422); // reason required

        $this->actingAs($this->b['admin'])->putJson($url, ['due_date' => $date, 'reason' => 'Kesepakatan awal'])->assertOk();
        $this->actingAs($this->b['keuangan'])->putJson($url, ['due_date' => now()->addDays(20)->toDateString(), 'reason' => 'Diperpanjang'])->assertOk();

        $changes = OrderDueDateChange::query()->where('order_id', $order->id)->orderBy('id')->get();
        $this->assertCount(2, $changes);
        $this->assertNull($changes[0]->old_due_date);
        $this->assertSame($date, $changes[0]->new_due_date->toDateString());
        $this->assertSame($date, $changes[1]->old_due_date->toDateString());
        $this->assertSame($this->b['keuangan']->id, $changes[1]->changed_by);
        $this->assertEquals($issuedAt->toDateTimeString(), $order->fresh()->invoice_issued_at->toDateTimeString(), 'due date never moves the invoice issue date');

        // The konsumen sees the due date of their own order; it is per order.
        $this->actingAs($this->b['konsumen'])->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()->assertJsonPath('data.due_date', now()->addDays(20)->toDateString())->assertJsonPath('data.is_overdue', false);
        $second = $this->transferOrder();
        $this->assertNull($second->fresh()->due_date);
    }

    // ---- Improvement 13 / Scenario H ---------------------------------------------------------------

    public function test_new_proofs_are_private_and_served_only_through_the_authorized_endpoint(): void
    {
        $order = $this->transferOrder();
        $this->proof($this->b['konsumen'], $order)->assertOk();
        $v = BankTransferVerification::query()->latest('id')->first();

        $this->assertSame('local', $v->proof_disk);
        Storage::disk('local')->assertExists($v->proof_image_path);
        Storage::disk('public')->assertMissing($v->proof_image_path);

        $url = "/api/v1/payment-proofs/{$v->id}";
        $this->actingAs($this->b['konsumen'])->get($url)->assertOk();
        $this->actingAs($this->b['keuangan'])->get($url)->assertOk();
        $this->actingAs($this->b['sales'])->get($url)->assertOk(); // in referral scope
        $other = User::factory()->konsumen()->create(['agent_id' => $this->b['agen']->id]);
        $this->actingAs($other)->get($url)->assertForbidden();
        $otherBranch = User::factory()->agen()->create();
        $otherBranch->update(['agent_id' => $otherBranch->id]);
        $this->actingAs($otherBranch)->get($url)->assertStatus(403)->assertStatus(403);

        foreach (['kurir', 'gudang', 'koordinatorKurir'] as $role) {
            $actor = User::factory()->{$role}()->create(['agent_id' => $this->b['agen']->id]);
            $this->actingAs($actor)->get($url)->assertForbidden();
        }

        // The exposed URL is the API route, never a public storage URL.
        $json = $this->actingAs($this->b['konsumen'])->getJson("/api/v1/orders/{$order->id}")->json();
        $this->assertStringContainsString("/api/v1/payment-proofs/{$v->id}", json_encode($json, JSON_UNESCAPED_SLASHES));
        Storage::disk('local')->delete($v->proof_image_path);
        $this->actingAs($this->b['konsumen'])->get($url)->assertNotFound();
    }

    public function test_legacy_public_proofs_stay_readable(): void
    {
        $order = $this->transferOrder();
        $tx = $order->paymentTransactions()->firstOrFail();
        Storage::disk('public')->put('payments/bank-transfer-proofs/legacy.jpg', 'legacy-bytes');
        $v = BankTransferVerification::create([
            'payment_transaction_id' => $tx->id, 'bank_name' => 'BCA', 'account_name' => 'x', 'account_number' => '1',
            'proof_image_path' => 'payments/bank-transfer-proofs/legacy.jpg', 'status' => 'pending',
        ]);
        $this->assertSame('public', $v->fresh()->proof_disk);
        $this->actingAs($this->b['konsumen'])->get("/api/v1/payment-proofs/{$v->id}")->assertOk();
    }

    public function test_payment_proof_size_boundary_exactly_5_mib_accepted_and_over_rejected(): void
    {
        $order = $this->transferOrder();
        $at = UploadedFile::fake()->image('at.jpg', 100, 100)->size(5120);   // 5 MiB = 5,242,880 bytes
        $this->actingAs($this->b['konsumen'])->postJson("/api/v1/orders/{$order->id}/payment/proof", ['proof' => $at])->assertOk();

        $order2 = $this->transferOrder();
        $over = UploadedFile::fake()->image('over.jpg', 100, 100)->size(5121);
        $this->actingAs($this->b['konsumen'])->postJson("/api/v1/orders/{$order2->id}/payment/proof", ['proof' => $over])->assertStatus(422);
    }

    public function test_spoofed_mime_and_disallowed_formats_are_rejected(): void
    {
        $order = $this->transferOrder();
        // Laravel's Testing\File derives its mime from the NAME, so it cannot
        // exercise the CONTENT-based (finfo) validation that real uploads go
        // through. A plain UploadedFile with a spoofed client mime + PHP body
        // mimics a real attack payload and proves the server rejects it.
        $spoofPath = tempnam(sys_get_temp_dir(), 'evil');
        file_put_contents($spoofPath, '<?php echo 1;');
        $fake = new UploadedFile($spoofPath, 'evil.jpg', 'image/jpeg', null, true);
        $this->actingAs($this->b['konsumen'])->postJson("/api/v1/orders/{$order->id}/payment/proof", ['proof' => $fake])->assertStatus(422);
        $gif = UploadedFile::fake()->image('a.gif');
        $this->actingAs($this->b['konsumen'])->postJson("/api/v1/orders/{$order->id}/payment/proof", ['proof' => $gif])->assertStatus(422);
        $svg = UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $this->actingAs($this->b['konsumen'])->postJson("/api/v1/orders/{$order->id}/payment/proof", ['proof' => $svg])->assertStatus(422);
        $this->assertSame(0, BankTransferVerification::query()->count());
    }
}
