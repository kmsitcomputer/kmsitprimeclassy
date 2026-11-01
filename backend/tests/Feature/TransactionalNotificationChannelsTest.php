<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Mail\TransactionalNotificationMail;
use App\Models\AdminNotification;
use App\Models\AgentWhatsAppDevice;
use App\Models\BankTransferVerification;
use App\Models\EmailMessage;
use App\Models\Order;
use App\Models\RefundObligation;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Observers\WhatsAppLifecycleObserver;
use App\Services\Mail\SmtpSettings;
use App\Services\WhatsApp\OrderNotifier;
use App\Services\WhatsApp\WhatsAppEvents;
use App\Services\WhatsApp\WhatsAppSettings;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCrmFixtures;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/** Notification contract Paket 2/3: transactional e-mail, operator in-app centre, and the additional lifecycle events. */
class TransactionalNotificationChannelsTest extends TestCase
{
    use BuildsCrmFixtures;
    use HasTestRegion;
    use \Tests\Concerns\AssumesPairedAgentDevices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assumeEveryAgentHasAPairedDevice();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        Queue::fake();
    }

    private function smtp(bool $transactional = true): void
    {
        app(SmtpSettings::class)->update([
            'enabled' => true, 'host' => 'smtp.test', 'port' => 587, 'from_address' => 'noreply@primeclassy.test',
            'transactional_enabled' => $transactional,
        ], User::factory()->superAdmin()->create());
    }

    private function enableWhatsApp(array $events): void
    {
        app(WhatsAppSettings::class)->update([
            'enabled' => true, 'base_url' => 'http://gowa.test', 'username' => 'u', 'password' => 'p', 'device_id' => 'dev-1',
            'events' => array_fill_keys($events, true),
        ], User::factory()->superAdmin()->create());
    }

    private function proveNumber(User $user): void
    {
        \App\Models\IdentityOtpChallenge::create([
            'agent_id' => $user->agent_id ?? $user->id, 'subject_user_id' => $user->id, 'created_by' => $user->id,
            'purpose' => 'whatsapp_number', 'code_hash' => str_repeat('0', 64),
            'sent_to_email' => 'wa:'.\App\Support\WhatsAppNumber::msisdn($user->phone),
            'attempts' => 1, 'max_attempts' => 5, 'expires_at' => now()->addMinutes(5), 'verified_at' => now(), 'consumed_at' => now(),
        ]);
    }

    private function order(array $b): Order
    {
        $product = $this->stockedProduct($b['agen']);
        $id = $this->actingAs($b['agen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'konsumen_id' => $b['konsumen']->id,
            'payment_method_code' => 'cod', 'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. X',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ])->assertCreated()->json('data.id');

        return Order::withoutGlobalScopes()->findOrFail($id);
    }

    /* ----------------------------------------------------------------- e-mail */

    public function test_transactional_email_is_off_by_default_even_with_smtp_on(): void
    {
        $this->smtp(transactional: false);
        $this->order($this->branch());

        $this->assertSame(0, EmailMessage::query()->count());
        Queue::assertNotPushed(SendTransactionalEmailJob::class);
    }

    public function test_order_email_reaches_the_verified_creator_and_branch_admin_once_each(): void
    {
        $this->smtp();
        $b = $this->branch();
        $b['konsumen']->forceFill(['email' => 'budi@pelanggan.test', 'name' => 'Budi Santoso'])->save();
        $b['admin']->forceFill(['email' => 'admin@toko.test'])->save();
        $b['agen']->forceFill(['email' => 'agen@toko.test'])->save();
        $b['keuangan']->forceFill(['email_verified_at' => null])->save();

        $order = $this->order($b);
        // A replayed fact (same event, same subject) never creates a second row or a second job.
        app(OrderNotifier::class)->orderEvent($order, WhatsAppEvents::ORDER_CREATED);

        // admin (branch), creator (account buyer) and creator (the Agen who submitted the order — recorded submitter).
        $rows = EmailMessage::query()->where('event', 'order_created')->orderBy('recipient_email')->get();
        $this->assertSame(['admin@toko.test', 'agen@toko.test', 'budi@pelanggan.test'], $rows->pluck('recipient_email')->all());
        $this->assertSame(['admin', 'creator', 'creator'], $rows->pluck('recipient_role')->all());
        $this->assertSame("Pesanan {$order->order_no} Berhasil Dibuat", $rows[0]->subject);
        $buyerRow = $rows->firstWhere('recipient_email', 'budi@pelanggan.test');
        $this->assertStringContainsString('Halo Budi Santoso', $buyerRow->body);
        $this->assertStringNotContainsString('*', $buyerRow->body, 'WhatsApp markup never leaks into e-mail');
        $this->assertStringNotContainsString('Jangan membagikan OTP', $buyerRow->body, 'the WhatsApp footer is replaced by the e-mail footer');
        Queue::assertPushed(SendTransactionalEmailJob::class, 3);
    }

    public function test_an_unverified_address_receives_nothing(): void
    {
        $this->smtp();
        $b = $this->branch();
        foreach (['konsumen', 'admin', 'agen'] as $role) {
            $b[$role]->forceFill(['email_verified_at' => null])->save();
        }

        $this->order($b);

        $this->assertSame(0, EmailMessage::query()->count());
    }

    /** Only the account buyer keeps a verified address, so exactly one ledger row exists. */
    private function buyerOnly(array $b): void
    {
        $b['admin']->forceFill(['email_verified_at' => null])->save();
        $b['agen']->forceFill(['email_verified_at' => null])->save();
    }

    public function test_the_job_sends_once_and_records_the_outcome(): void
    {
        Mail::fake();
        $this->smtp();
        $b = $this->branch();
        $this->buyerOnly($b);
        $this->order($b);
        $row = EmailMessage::query()->sole();

        app()->call([new SendTransactionalEmailJob($row->id), 'handle']);
        app()->call([new SendTransactionalEmailJob($row->id), 'handle']);

        Mail::assertSent(TransactionalNotificationMail::class, 1);
        Mail::assertSent(TransactionalNotificationMail::class, fn ($m) => $m->hasTo($row->recipient_email) && $m->mailSubject === $row->subject);
        $this->assertSame(EmailMessage::SENT, $row->fresh()->status);
        $this->assertNotNull($row->fresh()->sent_at);
    }

    public function test_a_message_queued_before_email_was_switched_off_is_skipped(): void
    {
        Mail::fake();
        $this->smtp();
        $b = $this->branch();
        $this->buyerOnly($b);
        $this->order($b);
        $row = EmailMessage::query()->sole();

        app(SmtpSettings::class)->update(['transactional_enabled' => false], User::factory()->superAdmin()->create());
        app()->call([new SendTransactionalEmailJob($row->id), 'handle']);

        Mail::assertNothingSent();
        $this->assertSame([EmailMessage::SKIPPED, 'mail_disabled'], [$row->fresh()->status, $row->fresh()->skip_reason]);
    }

    /* ----------------------------------------------------------------- in-app centre */

    public function test_new_order_alert_reaches_only_its_branch_operators_and_the_api_is_owner_scoped(): void
    {
        $b = $this->branch();
        $other = $this->branch();
        $sa = User::factory()->superAdmin()->create();
        $order = $this->order($b);

        $recipients = AdminNotification::query()->where('type', 'order.created')->pluck('recipient_user_id')->sort()->values()->all();
        $this->assertSame(collect([$b['agen']->id, $b['admin']->id])->sort()->values()->all(), $recipients);

        $list = $this->actingAs($b['admin'])->getJson('/api/v1/notifications/admin')->assertOk();
        $this->assertSame(1, $list->json('meta.unread_count'));
        $this->assertStringContainsString($order->order_no, $list->json('data.0.body'));

        $mine = AdminNotification::query()->where('recipient_user_id', $b['admin']->id)->sole();
        // Another branch's operator, and a Super Admin, cannot read or mark someone else's row (same 404 as missing).
        $this->actingAs($other['admin'])->getJson('/api/v1/notifications/admin')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($other['admin'])->postJson("/api/v1/notifications/admin/{$mine->id}/read")->assertNotFound();
        $this->actingAs($sa)->postJson("/api/v1/notifications/admin/{$mine->id}/read")->assertNotFound();
        $this->assertNull($mine->fresh()->read_at);

        $this->actingAs($b['admin'])->postJson("/api/v1/notifications/admin/{$mine->id}/read")->assertOk();
        $this->assertNotNull($mine->fresh()->read_at);
        $this->actingAs($b['admin'])->getJson('/api/v1/notifications/admin/unread-count')->assertOk()->assertJsonPath('data.unread_count', 0);

        // Customer-side roles have no access to the operator centre at all.
        $this->actingAs($b['konsumen'])->getJson('/api/v1/notifications/admin')->assertForbidden();
    }

    public function test_delivery_failure_and_device_disconnect_raise_operator_alerts(): void
    {
        $b = $this->branch();
        $sa = User::factory()->superAdmin()->create();
        $order = $this->order($b);

        $m = WhatsAppMessage::query()->create([
            'idempotency_key' => 'txn:order_shipped:order:'.$order->id, 'purpose' => 'transaction', 'event' => 'order_shipped',
            'agent_id' => $b['agen']->id, 'order_id' => $order->id, 'subject_type' => 'order', 'subject_id' => $order->id,
            'recipient_phone' => '6281377700001', 'body' => 'x', 'status' => 'queued',
        ]);
        $m->forceFill(['status' => WhatsAppMessage::FAILED])->save();

        $failed = AdminNotification::query()->where('type', 'delivery.failed')->get();
        $this->assertEqualsCanonicalizing([$b['agen']->id, $b['admin']->id, $sa->id], $failed->pluck('recipient_user_id')->all());
        $this->assertSame('critical', $failed->first()->severity);
        $this->assertStringNotContainsString('6281377700001', $failed->first()->body, 'no phone number in an operator alert');

        $device = AgentWhatsAppDevice::query()->updateOrCreate(['agent_id' => $b['agen']->id], ['device_id' => 'dev-x', 'state' => AgentWhatsAppDevice::CONNECTED]);
        $device->forceFill(['state' => AgentWhatsAppDevice::PAIRING])->save();
        $device->forceFill(['state' => AgentWhatsAppDevice::CONNECTED])->save();
        $device->forceFill(['state' => AgentWhatsAppDevice::DISCONNECTED])->save();

        $titles = AdminNotification::query()->where('type', 'gateway.status')->where('recipient_user_id', $sa->id)->pluck('title')->all();
        $this->assertContains('Perangkat WhatsApp terputus', $titles);
        $this->assertContains('Perangkat WhatsApp terhubung kembali', $titles);
    }

    /* ----------------------------------------------------------------- additional events */

    public function test_refund_obligation_lifecycle_maps_to_requested_approved_and_rejected(): void
    {
        $b = $this->branch();
        $b['konsumen']->forceFill(['phone' => '081377700001'])->save();
        $this->proveNumber($b['konsumen']);
        $this->enableWhatsApp([WhatsAppEvents::REFUND_REQUESTED, WhatsAppEvents::REFUND_APPROVED, WhatsAppEvents::REFUND_REJECTED]);
        $order = $this->order($b);
        $observer = app(WhatsAppLifecycleObserver::class);

        $pending = (new RefundObligation(['order_id' => $order->id, 'obligation_no' => 'RO-TEST-1', 'amount' => '25000.00', 'status' => 'pending']))->forceFill(['id' => 901]);
        $observer->created($pending);
        $requested = WhatsAppMessage::query()->where('event', 'refund_requested')->sole();
        $this->assertStringContainsString('RO-TEST-1', $requested->body);
        $this->assertStringContainsString('belum berarti pengembalian dana telah disetujui', $requested->body);

        $pending->syncOriginal();
        $pending->status = 'approved';
        $pending->syncChanges();
        $observer->updated($pending);
        $this->assertNotNull(WhatsAppMessage::query()->where('event', 'refund_approved')->first());

        // partially_paid -> approved is a payment regression, never a second "approved" announcement.
        $regress = (new RefundObligation(['order_id' => $order->id, 'obligation_no' => 'RO-TEST-2', 'amount' => '1000.00', 'status' => 'partially_paid']))->forceFill(['id' => 902]);
        $regress->syncOriginal();
        $regress->status = 'approved';
        $regress->syncChanges();
        $observer->updated($regress);
        $this->assertSame(1, WhatsAppMessage::query()->where('event', 'refund_approved')->count());

        $nv = (new RefundObligation(['order_id' => $order->id, 'obligation_no' => 'RO-TEST-3', 'amount' => '5000.00', 'status' => 'needs_verification']))->forceFill(['id' => 903]);
        $nv->syncOriginal();
        $nv->status = 'cancelled';
        $nv->cancel_reason = 'needs_verification_exit_b';
        $nv->syncChanges();
        $observer->updated($nv);
        $rejected = WhatsAppMessage::query()->where('event', 'refund_rejected')->sole();
        $this->assertStringNotContainsString('needs_verification_exit_b', $rejected->body, 'internal cancel codes never reach the customer');

        // Derived children of an already-announced obligation stay silent.
        $child = (new RefundObligation(['order_id' => $order->id, 'obligation_no' => 'RO-TEST-4', 'amount' => '1.00', 'status' => 'pending', 'parent_obligation_id' => 901]))->forceFill(['id' => 904]);
        $observer->created($child);
        $this->assertSame(1, WhatsAppMessage::query()->where('event', 'refund_requested')->count());
    }

    public function test_rejected_transfer_proof_notifies_payment_failed_without_reaching_the_checkout_recipient(): void
    {
        $b = $this->branch();
        $b['konsumen']->forceFill(['phone' => '081377700001'])->save();
        $this->proveNumber($b['konsumen']);
        $this->enableWhatsApp([WhatsAppEvents::PAYMENT_FAILED]);
        $order = $this->order($b);
        $order->forceFill(['recipient_phone_snapshot' => '081399900002'])->save();

        $v = (new BankTransferVerification(['submitted_amount' => '150000.00', 'rejection_reason' => 'Nominal transfer tidak sesuai']))->forceFill(['id' => 77]);
        app(OrderNotifier::class)->paymentRejected($v, $order->id);

        $buyer = WhatsAppMessage::query()->where('event', 'payment_failed')->where('recipient_role', 'creator')->sole();
        $this->assertStringContainsString('Jumlah Diajukan: Rp 150.000', $buyer->body);
        $this->assertStringContainsString('Nominal transfer tidak sesuai', $buyer->body);
        $recipientRow = WhatsAppMessage::query()->where('event', 'payment_failed')->where('recipient_role', 'recipient')->first();
        $this->assertTrue($recipientRow === null || $recipientRow->status === 'skipped', 'financial events never reach the unproven checkout recipient');
        $this->assertNotNull(AdminNotification::query()->where('type', 'payment.failed')->first(), 'operators are told too');
    }

    public function test_a_return_completed_with_every_item_rejected_notifies_return_rejected(): void
    {
        $b = $this->branch();
        $b['konsumen']->forceFill(['phone' => '081377700001'])->save();
        $this->proveNumber($b['konsumen']);
        $this->enableWhatsApp([WhatsAppEvents::RETURN_REJECTED]);
        $order = $this->order($b);
        $item = $order->items()->firstOrFail();

        $return = ReturnRequest::create(['order_id' => $order->id, 'requested_by' => $b['konsumen']->id, 'reason' => 'Rusak', 'status' => 'requested']);
        ReturnItem::create(['return_id' => $return->id, 'order_item_id' => $item->id, 'quantity_returned' => 1, 'refund_amount' => 0, 'status' => 'rejected', 'refund_status' => 'not_required']);
        $return->update(['status' => 'completed']);

        $this->assertStringContainsString('RTN-'.str_pad((string) $return->id, 6, '0', STR_PAD_LEFT),
            WhatsAppMessage::query()->where('event', 'return_rejected')->sole()->body);

        // A completed return with at least one approved item is NOT a rejection.
        $return2 = ReturnRequest::create(['order_id' => $order->id, 'requested_by' => $b['konsumen']->id, 'reason' => 'Rusak', 'status' => 'requested']);
        ReturnItem::create(['return_id' => $return2->id, 'order_item_id' => $item->id, 'quantity_returned' => 1, 'refund_amount' => 0, 'status' => 'approved', 'refund_status' => 'pending']);
        $return2->update(['status' => 'completed']);
        $this->assertSame(1, WhatsAppMessage::query()->where('event', 'return_rejected')->count());
    }
}
