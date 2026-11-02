<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Models\AdminNotification;
use App\Models\AgentPaymentGatewayConfig;
use App\Models\AgentProfile;
use App\Models\BankTransferVerification;
use App\Models\EmailMessage;
use App\Models\IdentityOtpChallenge;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ShippingConfiguration;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\Mail\SmtpSettings;
use App\Services\Order\OrderService;
use App\Services\Payment\PaymentService;
use App\Services\WhatsApp\WhatsAppEvents;
use App\Services\WhatsApp\WhatsAppSettings;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * payment_proof_uploaded: a consumer's bank-transfer proof notifies the branch's KEUANGAN staff only, on WhatsApp,
 * e-mail and the in-app centre. The upload never marks the order paid.
 */
class PaymentProofNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        Storage::fake('public');
        Storage::fake('local');
        Queue::fake();
    }

    private function smtp(): void
    {
        app(SmtpSettings::class)->update([
            'enabled' => true, 'host' => 'smtp.test', 'port' => 587, 'from_address' => 'noreply@primeclassy.test',
            'transactional_enabled' => true,
        ], User::factory()->superAdmin()->create());
    }

    private function enableWhatsApp(): void
    {
        app(WhatsAppSettings::class)->update([
            'enabled' => true, 'base_url' => 'http://gowa.test', 'username' => 'u', 'password' => 'p', 'device_id' => 'dev-1',
            'events' => [WhatsAppEvents::PAYMENT_PROOF_UPLOADED => true],
        ], User::factory()->superAdmin()->create());
    }

    private function proveNumber(User $user): void
    {
        IdentityOtpChallenge::create([
            'agent_id' => $user->agent_id ?? $user->id, 'subject_user_id' => $user->id, 'created_by' => $user->id,
            'purpose' => 'whatsapp_number', 'code_hash' => str_repeat('0', 64),
            'sent_to_email' => 'wa:'.\App\Support\WhatsAppNumber::msisdn($user->phone),
            'attempts' => 1, 'max_attempts' => 5, 'expires_at' => now()->addMinutes(5), 'verified_at' => now(), 'consumed_at' => now(),
        ]);
    }

    /** One branch with a bank-transfer order placed for its konsumen (same fixture shape as PaymentProofConcurrencyTest). */
    private function branchWithOrder(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Toko Proof', 'address' => 'Proof', 'latitude' => -6.2, 'longitude' => 106.8]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $agen->id, 'phone' => '081377700002']);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id, 'phone' => '081377700001']);

        $product = Product::create([
            'sku' => 'PROOF-'.Str::uuid(), 'name' => 'Proof Cake', 'slug' => 'proof-'.Str::uuid(),
            'has_variations' => false, 'base_price' => 100000, 'weight_grams' => 1000, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);
        $bank = PaymentMethod::query()->where('code', 'bank_transfer')->firstOrFail();
        AgentPaymentGatewayConfig::create([
            'agent_id' => $agen->id, 'payment_method_id' => $bank->id, 'environment' => 'sandbox',
            'config' => ['bank_name' => 'BCA', 'account_name' => 'PT Prime', 'account_number' => '123456'],
        ]);
        ShippingConfiguration::create([
            'agent_id' => $agen->id, 'price_per_km' => 2000, 'minimum_distance_km' => 0,
            'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true,
        ]);
        $order = app(OrderService::class)->createOrder(
            $konsumen,
            [['product_id' => $product->id, 'quantity' => 1]],
            ['recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Proof', 'village_id' => null, 'latitude' => -6.2, 'longitude' => 106.8],
            $konsumen, 'bank_transfer', null, 'proof-'.Str::uuid(), null, null, null, null, null,
        );

        return compact('agen', 'admin', 'keuangan', 'konsumen', 'order');
    }

    /** A second branch with its own Keuangan, to prove there is no cross-branch leak. */
    private function otherBranchKeuangan(): User
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Cabang Lain', 'address' => 'Lain', 'latitude' => -6.3, 'longitude' => 106.9]);

        return User::factory()->keuangan()->create(['agent_id' => $agen->id, 'phone' => '081377700009']);
    }

    private function upload(array $b, string $content = 'proof-bytes-A'): BankTransferVerification
    {
        $transaction = PaymentTransaction::query()->where('order_id', $b['order']->id)->latest('id')->firstOrFail();

        return app(PaymentService::class)->submitBankTransferProof(
            $transaction, UploadedFile::fake()->createWithContent('proof.jpg', $content), $b['konsumen'],
        );
    }

    /* ----------------------------------------------------------------- recipients */

    public function test_in_app_alert_reaches_only_the_branch_keuangan(): void
    {
        $b = $this->branchWithOrder();
        $other = $this->otherBranchKeuangan();

        $this->upload($b);

        $recipients = AdminNotification::query()->where('type', 'payment.proof_uploaded')->pluck('recipient_user_id')->all();
        $this->assertSame([$b['keuangan']->id], $recipients, 'only the Keuangan of the order branch is notified');
        $this->assertFalse(in_array($other->id, $recipients, true), 'another branch keuangan never sees it');

        $this->actingAs($b['keuangan'])->getJson('/api/v1/notifications/admin')->assertOk()->assertJsonPath('meta.unread_count', 1);
        foreach ([$b['admin'], $b['agen'], $b['konsumen'], User::factory()->superAdmin()->create()] as $role) {
            $this->assertSame(0, AdminNotification::query()->where('recipient_user_id', $role->id)->where('type', 'payment.proof_uploaded')->count());
        }
    }

    public function test_email_goes_only_to_the_verified_keuangan_of_the_branch(): void
    {
        $this->smtp();
        $b = $this->branchWithOrder();
        $b['keuangan']->forceFill(['email' => 'keuangan@toko.test'])->save();
        $b['admin']->forceFill(['email' => 'admin@toko.test'])->save();

        $this->upload($b);

        $rows = EmailMessage::query()->where('event', WhatsAppEvents::PAYMENT_PROOF_UPLOADED)->get();
        $this->assertSame(['finance'], $rows->pluck('recipient_role')->unique()->values()->all());
        $this->assertSame(['keuangan@toko.test'], $rows->pluck('recipient_email')->all());
        $this->assertStringContainsString('Halo '.$b['keuangan']->name, $rows[0]->body);
        $this->assertStringContainsString('Menunggu Verifikasi Keuangan', $rows[0]->body);
        $this->assertStringNotContainsString('admin@toko.test', $rows->pluck('recipient_email')->implode(','));
        Queue::assertPushed(SendTransactionalEmailJob::class, fn ($job) => $job->messageId === $rows[0]->id);
    }

    public function test_unverified_keuangan_email_is_recorded_as_skipped_and_never_queued(): void
    {
        $this->smtp();
        $b = $this->branchWithOrder();
        $b['keuangan']->forceFill(['email_verified_at' => null])->save();

        $this->upload($b);

        $row = EmailMessage::query()->where('event', WhatsAppEvents::PAYMENT_PROOF_UPLOADED)->sole();
        $this->assertSame([EmailMessage::SKIPPED, 'email_not_verified'], [$row->status, $row->skip_reason]);
        Queue::assertNotPushed(SendTransactionalEmailJob::class, fn ($job) => $job->messageId === $row->id);
    }

    public function test_whatsapp_goes_to_the_keuangan_with_a_proof_and_carries_the_claimed_amount_and_absolute_link(): void
    {
        config(['app.frontend_url' => 'https://shop.test']);
        $this->enableWhatsApp();
        $b = $this->branchWithOrder();
        $this->proveNumber($b['keuangan']);

        $this->upload($b);

        $rows = WhatsAppMessage::query()->where('event', WhatsAppEvents::PAYMENT_PROOF_UPLOADED)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(['finance', 'queued'], [$rows[0]->recipient_role, $rows[0]->status]);
        $this->assertSame('6281377700002', $rows[0]->recipient_phone);
        $this->assertStringContainsString('Halo '.$b['keuangan']->name, $rows[0]->body);
        $this->assertStringContainsString('Nominal yang Diklaim: Rp 100.000', $rows[0]->body);
        $this->assertStringContainsString("https://shop.test/orders/{$b['order']->id}", $rows[0]->body);
        $this->assertStringNotContainsString('/orders/'.$b['order']->order_no, $rows[0]->body);
    }

    public function test_a_keuangan_without_a_whatsapp_proof_is_skipped_not_sent_to_anyone_else(): void
    {
        $this->enableWhatsApp();
        $b = $this->branchWithOrder();

        $this->upload($b);

        $row = WhatsAppMessage::query()->where('event', WhatsAppEvents::PAYMENT_PROOF_UPLOADED)->sole();
        $this->assertSame([WhatsAppMessage::SKIPPED, 'number_not_verified'], [$row->status, $row->skip_reason]);
        $this->assertSame(0, WhatsAppMessage::query()->where('recipient_phone', '6281377700001')->where('event', WhatsAppEvents::PAYMENT_PROOF_UPLOADED)->count());
    }

    /* ----------------------------------------------------------------- idempotency and safety */

    public function test_the_same_file_uploaded_again_never_notifies_twice(): void
    {
        $this->smtp();
        $this->enableWhatsApp();
        $b = $this->branchWithOrder();
        $b['keuangan']->forceFill(['email' => 'keuangan@toko.test'])->save();
        $this->proveNumber($b['keuangan']);

        $this->upload($b, 'identical-bytes');
        $this->upload($b, 'identical-bytes');

        $this->assertSame(1, AdminNotification::query()->where('type', 'payment.proof_uploaded')->count());
        $this->assertSame(1, EmailMessage::query()->where('event', WhatsAppEvents::PAYMENT_PROOF_UPLOADED)->count());
        $this->assertSame(1, WhatsAppMessage::query()->where('event', WhatsAppEvents::PAYMENT_PROOF_UPLOADED)->count());
    }

    public function test_a_different_file_is_a_genuinely_new_upload_and_notifies_again(): void
    {
        $b = $this->branchWithOrder();

        $this->upload($b, 'first-file');
        $this->upload($b, 'second-file');

        $this->assertSame(2, AdminNotification::query()->where('recipient_user_id', $b['keuangan']->id)->where('type', 'payment.proof_uploaded')->count());
    }

    public function test_a_rolled_back_upload_notifies_nobody(): void
    {
        $this->smtp();
        $this->enableWhatsApp();
        $b = $this->branchWithOrder();
        $b['keuangan']->forceFill(['email' => 'keuangan@toko.test'])->save();
        $this->proveNumber($b['keuangan']);
        $transaction = PaymentTransaction::query()->where('order_id', $b['order']->id)->latest('id')->firstOrFail();

        try {
            DB::transaction(function () use ($transaction, $b) {
                app(PaymentService::class)->submitBankTransferProof($transaction, UploadedFile::fake()->createWithContent('p.jpg', 'x'), $b['konsumen']);
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException) {
            // expected: the outer transaction is rolled back
        }

        $this->assertSame(0, AdminNotification::query()->where('type', 'payment.proof_uploaded')->count());
        $this->assertSame(0, EmailMessage::query()->where('event', WhatsAppEvents::PAYMENT_PROOF_UPLOADED)->count());
        $this->assertSame(0, WhatsAppMessage::query()->where('event', WhatsAppEvents::PAYMENT_PROOF_UPLOADED)->count());
    }

    public function test_the_upload_never_marks_the_order_paid(): void
    {
        $b = $this->branchWithOrder();

        $this->upload($b);

        $this->assertNotSame('paid', $b['order']->fresh()->payment_status);
        $this->assertSame('pending_verification', $b['order']->fresh()->payment_status);
        $this->assertSame(0, BankTransferVerification::query()->where('status', 'verified')->count());
    }

    public function test_whatsapp_being_off_does_not_block_the_upload(): void
    {
        $b = $this->branchWithOrder();
        $verification = $this->upload($b);

        $this->assertSame('pending', $verification->fresh()->status, 'the proof is saved even with the gateway off');
        $this->assertSame(0, WhatsAppMessage::query()->where('event', WhatsAppEvents::PAYMENT_PROOF_UPLOADED)->count());
    }

    public function test_a_branch_without_keuangan_falls_back_to_nobody_not_to_admin(): void
    {
        $b = $this->branchWithOrder();
        $b['keuangan']->delete();

        $this->upload($b);

        $this->assertSame(0, AdminNotification::query()->where('type', 'payment.proof_uploaded')->count());
        $this->assertSame(0, AdminNotification::query()->where('recipient_user_id', $b['admin']->id)->where('type', 'payment.proof_uploaded')->count());
    }
}
