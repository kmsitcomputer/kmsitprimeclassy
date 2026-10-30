<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Courier;
use App\Models\Order;
use App\Models\RefundPayout;
use App\Models\ReturnRequest;
use App\Models\Shipment;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\OrderNotifier;
use App\Services\WhatsApp\WhatsAppDelivery;
use App\Services\WhatsApp\WhatsAppEvents;
use App\Services\WhatsApp\WhatsAppSettings;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCrmFixtures;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/** Transactional WhatsApp notifications driven by REAL order/return/refund lifecycle facts. HTTP is faked, never real. */
class WhatsAppNotificationTest extends TestCase
{
    use BuildsCrmFixtures;
    use HasTestRegion;
    use \Tests\Concerns\AssumesPairedAgentDevices;
    use RefreshDatabase;

    private const BASE = 'http://gowa.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assumeEveryAgentHasAPairedDevice();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function fake(array|\Closure $stubs = []): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake($stubs);
    }

    private function ok(string $id = 'WA-1'): array
    {
        return [self::BASE.'/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => $id]], 200)];
    }

    /** Gateway configured + the given events switched on. */
    private function enable(array $events, array $extra = []): void
    {
        app(WhatsAppSettings::class)->update($extra + [
            'enabled' => true, 'base_url' => self::BASE, 'username' => 'u', 'password' => 'p', 'device_id' => 'dev-1',
            'max_attempts' => 3, 'backoff_seconds' => 10,
            'events' => array_fill_keys($events, true),
        ], User::factory()->superAdmin()->create());
    }

    /** Gives the account a phone AND a valid number proof (what a completed OTP confirmation leaves behind). */
    private function proveNumber(User $user): void
    {
        \App\Models\IdentityOtpChallenge::create([
            'agent_id' => $user->agent_id ?? $user->id, 'subject_user_id' => $user->id, 'created_by' => $user->id,
            'purpose' => 'whatsapp_number', 'code_hash' => str_repeat('0', 64),
            'sent_to_email' => 'wa:'.\App\Support\WhatsAppNumber::msisdn($user->phone),
            'attempts' => 1, 'max_attempts' => 5, 'expires_at' => now()->addMinutes(5), 'verified_at' => now(), 'consumed_at' => now(),
        ]);
    }

    private function branchWithPhone(): array
    {
        $b = $this->branch();
        $b['konsumen']->forceFill(['phone' => '081377700001', 'name' => 'Budi Santoso'])->save();
        $this->proveNumber($b['konsumen']);

        return $b;
    }

    private function accountOrder(array $b): Order
    {
        $product = $this->stockedProduct($b['agen']);
        $id = $this->actingAs($b['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod', 'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. X',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ])->assertCreated()->json('data.id');

        return Order::withoutGlobalScopes()->findOrFail($id);
    }

    private function moveTo(User $by, Order $order, string $status): void
    {
        $this->actingAs($by)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => $status])->assertOk();
    }

    private function messages(): \Illuminate\Database\Eloquent\Collection
    {
        return WhatsAppMessage::query()->orderBy('id')->get();
    }

    /* ----------------------------------------------------------------- created / after-commit / idempotency */

    public function test_order_created_enqueues_one_message_to_the_buyer_and_sends_it(): void
    {
        Queue::fake();
        $b = $this->branchWithPhone();
        $this->enable([WhatsAppEvents::ORDER_CREATED]);

        $order = $this->accountOrder($b);

        $m = $this->messages();
        $this->assertCount(1, $m);
        $this->assertSame("txn:order_created:order:{$order->id}", $m[0]->idempotency_key);
        $this->assertSame(['transaction', 'queued', '6281377700001', $b['konsumen']->id], [$m[0]->purpose, $m[0]->status, $m[0]->recipient_phone, $m[0]->recipient_user_id]);
        $this->assertStringContainsString($order->order_no, $m[0]->body);
        $this->assertStringContainsString('Halo Budi Santoso', $m[0]->body);
        $this->assertStringContainsString('Rp ', $m[0]->body);
        Queue::assertPushed(SendWhatsAppMessageJob::class, 1);

        $this->fake($this->ok('WA-9'));
        $sent = app(WhatsAppDelivery::class)->deliver($m[0]->id);
        $this->assertSame(['sent', 'WA-9', 1], [$sent->status, $sent->provider_message_id, $sent->attempts]);
        Http::assertSent(fn ($r) => $r['phone'] === '6281377700001@s.whatsapp.net' && str_contains($r['message'], $order->order_no));
    }

    public function test_nothing_is_recorded_while_the_gateway_or_the_event_is_off(): void
    {
        Queue::fake();
        $b = $this->branchWithPhone();

        $this->accountOrder($b); // gateway not configured at all
        $this->enable([WhatsAppEvents::ORDER_SHIPPED], ['enabled' => true]); // configured, but order_created is OFF
        $this->accountOrder($b);
        app(WhatsAppSettings::class)->update(['enabled' => false], User::factory()->superAdmin()->create());
        $this->accountOrder($b);

        $this->assertSame(0, WhatsAppMessage::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_switching_an_event_off_takes_effect_immediately_no_stale_cache(): void
    {
        Queue::fake();
        $b = $this->branchWithPhone();
        $this->enable([WhatsAppEvents::ORDER_CREATED]);
        $this->accountOrder($b);
        $this->assertSame(1, WhatsAppMessage::query()->count());

        app(WhatsAppSettings::class)->update(['events' => [WhatsAppEvents::ORDER_CREATED => false]], User::factory()->superAdmin()->create());
        $this->accountOrder($b);
        $this->assertSame(1, WhatsAppMessage::query()->count(), 'the long-lived observer must re-read settings');
    }

    public function test_a_duplicate_trigger_for_the_same_business_event_never_creates_a_second_message(): void
    {
        Queue::fake();
        $b = $this->branchWithPhone();
        $this->enable([WhatsAppEvents::ORDER_CREATED, WhatsAppEvents::ORDER_SHIPPED]);
        $order = $this->accountOrder($b);

        $notifier = app(OrderNotifier::class);
        $notifier->orderEvent($order, WhatsAppEvents::ORDER_CREATED);
        $notifier->orderEvent($order, WhatsAppEvents::ORDER_CREATED);
        $order->touch(); // a page refresh / unrelated save is not a business event
        $this->actingAs($b['admin']);
        $this->moveTo($b['admin'], $order, 'dikirim');
        $notifier->orderEvent($order->refresh(), WhatsAppEvents::ORDER_SHIPPED);

        $keys = $this->messages()->pluck('idempotency_key')->all();
        $this->assertSame(array_unique($keys), $keys);
        $this->assertSame(2, count($keys), 'one order_created + one order_shipped, whatever the duplicates');
        Queue::assertPushed(SendWhatsAppMessageJob::class, 2);
    }

    /* ----------------------------------------------------------------- status transitions + data honesty */

    public function test_shipped_message_carries_only_real_courier_and_tracking_data(): void
    {
        Queue::fake();
        $b = $this->branchWithPhone();
        $this->enable([WhatsAppEvents::ORDER_SHIPPED, WhatsAppEvents::ORDER_DELIVERED]);
        $order = $this->accountOrder($b);

        // No courier / tracking number yet: those lines are DROPPED, never invented.
        $this->moveTo($b['admin'], $order, 'dikirim');
        $shipped = $this->messages()->firstWhere('event', 'order_shipped');
        $this->assertNotNull($shipped);
        $this->assertStringContainsString($order->order_no, $shipped->body);
        $this->assertStringNotContainsString('Kurir:', $shipped->body);
        $this->assertStringNotContainsString('Resi', $shipped->body);

        // A second order, this time with a real courier + tracking number.
        $order2 = $this->accountOrder($b);
        $courier = Courier::create(['type' => 'internal', 'agent_id' => $b['agen']->id, 'name' => 'Kurir Andi', 'is_active' => true]);
        Shipment::query()->where('order_id', $order2->id)->update(['courier_id' => $courier->id, 'tracking_number' => 'RESI-778899']);
        $this->moveTo($b['admin'], $order2, 'dikirim');

        $body = $this->messages()->firstWhere('idempotency_key', "txn:order_shipped:order:{$order2->id}")->body;
        $this->assertStringContainsString("Kurir: Kurir Andi\nNomor Resi: RESI-778899", $body);

        $this->moveTo($b['admin'], $order2, 'terkirim');
        $this->assertNotNull($this->messages()->firstWhere('idempotency_key', "txn:order_delivered:order:{$order2->id}"));
    }

    public function test_cancel_and_payment_paid_events(): void
    {
        Queue::fake();
        $b = $this->branchWithPhone();
        $this->enable([WhatsAppEvents::ORDER_CANCELLED, WhatsAppEvents::PAYMENT_PAID]);
        $order = $this->accountOrder($b);

        $order->update(['payment_status' => 'partially_paid']);
        $this->assertSame(0, WhatsAppMessage::query()->count(), 'a partial payment is not "pembayaran berhasil"');
        $order->update(['payment_status' => 'paid']);
        $this->assertNotNull($this->messages()->firstWhere('event', 'payment_paid'));

        $this->actingAs($b['konsumen'])->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'Salah pesan'])->assertOk();
        $this->assertNotNull($this->messages()->firstWhere('event', 'order_cancelled'));
    }

    public function test_return_and_refund_events(): void
    {
        Queue::fake();
        $b = $this->branchWithPhone();
        $this->enable([WhatsAppEvents::RETURN_REQUESTED, WhatsAppEvents::RETURN_APPROVED, WhatsAppEvents::REFUND_COMPLETED]);
        $order = $this->accountOrder($b);

        $return = ReturnRequest::create(['order_id' => $order->id, 'requested_by' => $b['konsumen']->id, 'reason' => 'Rusak', 'status' => 'requested']);
        $this->assertStringContainsString('RTN-'.str_pad((string) $return->id, 6, '0', STR_PAD_LEFT), $this->messages()->firstWhere('event', 'return_requested')->body);

        $return->update(['status' => 'under_review']);
        $this->assertNull($this->messages()->firstWhere('event', 'return_approved'));
        $return->update(['status' => 'approved']);
        $this->assertNotNull($this->messages()->firstWhere('event', 'return_approved'));

        // Refund: only money PROVEN to have left counts. (Unsaved model: the notifier reads attributes only.)
        $proven = (new RefundPayout(['order_id' => $order->id, 'amount' => '55000.00', 'verification_status' => 'proven']))->forceFill(['id' => 501]);
        $unproven = (new RefundPayout(['order_id' => $order->id, 'amount' => '55000.00', 'verification_status' => 'needs_verification']))->forceFill(['id' => 502]);
        $observer = app(\App\Observers\WhatsAppLifecycleObserver::class);
        $observer->created($unproven);
        $this->assertNull($this->messages()->firstWhere('idempotency_key', 'txn:refund_completed:refund_payout:502'));
        $observer->created($proven);
        $this->assertStringContainsString('Rp 55.000', $this->messages()->firstWhere('idempotency_key', 'txn:refund_completed:refund_payout:501')->body);
    }

    public function test_customer_supplied_text_cannot_inject_template_tokens(): void
    {
        Queue::fake();
        $b = $this->branchWithPhone();
        $b['konsumen']->forceFill(['name' => '{resi} {{x}} Evil'])->save();
        $this->enable([WhatsAppEvents::ORDER_CREATED]);
        $this->accountOrder($b);

        $body = $this->messages()[0]->body;
        $this->assertStringContainsString('(resi) ((x)) Evil', $body);
        $this->assertStringNotContainsString('{', $body);
    }

    /* ----------------------------------------------------------------- recipients */

    public function test_an_unverified_number_never_receives_order_information_and_a_phone_change_voids_the_proof(): void
    {
        Queue::fake();
        $b = $this->branch();
        $b['konsumen']->forceFill(['phone' => '081377700001'])->save(); // phone on file, NEVER proven
        $this->enable([WhatsAppEvents::ORDER_CREATED, WhatsAppEvents::ORDER_SHIPPED]);

        $order = $this->accountOrder($b);
        $row = $this->messages()->firstWhere('order_id', $order->id);
        $this->assertSame(['skipped', 'number_not_verified', null], [$row->status, $row->skip_reason, $row->body]);
        Queue::assertNothingPushed();

        // Proven -> delivered; the proof is bound to THAT number: change the phone and the next event is skipped again.
        $this->proveNumber($b['konsumen']);
        $this->moveTo($b['admin'], $order, 'dikirim');
        $this->assertSame('queued', $this->messages()->firstWhere('idempotency_key', "txn:order_shipped:order:{$order->id}")->status);

        $b['konsumen']->forceFill(['phone' => '081377700002'])->save();
        $order2 = $this->accountOrder($b);
        $this->assertSame('number_not_verified', $this->messages()->firstWhere('order_id', $order2->id)->skip_reason);

        // Switching BACK to the old number does not resurrect the old proof.
        $b['konsumen']->forceFill(['phone' => '081377700001'])->save();
        $order3 = $this->accountOrder($b);
        $this->assertSame('number_not_verified', $this->messages()->firstWhere('order_id', $order3->id)->skip_reason);
    }

    public function test_crm_origin_orders_and_phoneless_buyers_are_skipped_with_a_reason(): void
    {
        Queue::fake();
        $b = $this->branchWithPhone();
        $this->enable([WhatsAppEvents::ORDER_CREATED]);

        // CRM-origin: the Sales-typed CRM number is NOT a verified recipient.
        $customer = $this->crmCustomer($b['sales'], ['phone' => '081377700099']);
        $crm = $this->assistedOrder($b['sales'], $customer, $this->stockedProduct($b['agen']));
        $row = $this->messages()->firstWhere('order_id', $crm->id);
        $this->assertSame(['skipped', 'recipient_not_verified', null], [$row->status, $row->skip_reason, $row->recipient_phone]);

        // Account buyer without a phone.
        $b['konsumen']->forceFill(['phone' => null])->save();
        $order = $this->accountOrder($b);
        $row = $this->messages()->firstWhere('order_id', $order->id);
        $this->assertSame(['skipped', 'no_phone'], [$row->status, $row->skip_reason]);

        Queue::assertNothingPushed();
        $this->fake();
        Http::assertNothingSent();
    }

    /* ----------------------------------------------------------------- rollback + outage */

    public function test_a_rolled_back_transaction_sends_and_records_nothing(): void
    {
        Queue::fake();
        $b = $this->branchWithPhone();
        $this->enable([WhatsAppEvents::ORDER_SHIPPED]);
        $order = $this->accountOrder($b);

        try {
            DB::transaction(function () use ($order) {
                $order->update(['status' => 'dikirim']);
                throw new \RuntimeException('boom — something later in the business operation failed');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame('diproses', $order->fresh()->status, 'the business change really rolled back');
        $this->assertSame(0, WhatsAppMessage::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_gateway_outage_never_fails_the_order_status_change(): void
    {
        // Real sync queue on purpose: the whole send+retry cycle runs inline AFTER the commit.
        $b = $this->branchWithPhone();
        $this->enable([WhatsAppEvents::ORDER_SHIPPED]);
        $order = $this->accountOrder($b);
        $this->fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to gowa.test'));

        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'dikirim'])->assertOk();

        $this->assertSame('dikirim', $order->fresh()->status);
        $m = $this->messages()->firstWhere('event', 'order_shipped');
        $this->assertSame(['failed', 3, 'retries_exhausted:unreachable'], [$m->status, $m->attempts, $m->error_class]);
        $this->assertStringNotContainsString('Authorization', (string) $m->error_message);
    }

    public function test_an_unexpected_exception_inside_notification_code_is_swallowed(): void
    {
        $b = $this->branchWithPhone();
        $this->enable([WhatsAppEvents::ORDER_SHIPPED]);
        $order = $this->accountOrder($b);
        $this->app->bind(WhatsAppDelivery::class, fn () => throw new \RuntimeException('delivery layer exploded'));
        $this->app->forgetInstance(OrderNotifier::class);

        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'dikirim'])->assertOk();
        $this->assertSame('dikirim', $order->fresh()->status);
    }

    /* ----------------------------------------------------------------- retry policy (duplicate safety) */

    private function queuedShipped(array $b): WhatsAppMessage
    {
        $order = $this->accountOrder($b);
        $this->moveTo($b['admin'], $order, 'dikirim');

        return $this->messages()->firstWhere('event', 'order_shipped');
    }

    public function test_device_disconnected_is_retried_with_backoff_then_exhausts(): void
    {
        Queue::fake();
        $b = $this->branchWithPhone();
        $this->enable([WhatsAppEvents::ORDER_SHIPPED]);
        $m = $this->queuedShipped($b);
        $d = app(WhatsAppDelivery::class);
        $this->fake([self::BASE.'/send/message' => Http::response(['message' => 'whatsapp client is not connected'], 500)]);

        $m = $d->deliver($m->id);
        $this->assertSame(['queued', 1, 'device_disconnected'], [$m->status, $m->attempts, $m->error_class]);
        $this->assertNotNull($m->next_attempt_at);
        Queue::assertPushed(SendWhatsAppMessageJob::class, fn ($j) => $j->messageId === $m->id && $j->delay !== null);

        $d->deliver($m->id);
        $m = $d->deliver($m->id);
        $this->assertSame(['failed', 3, 'retries_exhausted:device_disconnected'], [$m->status, $m->attempts, $m->error_class]);
        $this->assertNull($d->deliver($m->id)?->secret_body);
        $this->assertSame(3, $m->fresh()->attempts, 'a replayed job on a finished message is a no-op');
    }

    public function test_an_uncertain_result_is_never_auto_retried_and_needs_explicit_confirmation(): void
    {
        Queue::fake();
        $b = $this->branchWithPhone();
        $this->enable([WhatsAppEvents::ORDER_SHIPPED]);
        $m = $this->queuedShipped($b);
        $d = app(WhatsAppDelivery::class);
        Queue::fake(); // forget the creation job: from here only RETRY pushes matter

        $this->fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds'));
        $m = $d->deliver($m->id);
        $this->assertSame(['unknown', 1], [$m->status, $m->attempts]);
        Queue::assertNothingPushed();

        $sa = User::factory()->superAdmin()->create();
        $url = "/api/v1/admin/integrations/whatsapp/messages/{$m->id}/retry";
        $this->actingAs($sa)->postJson($url)->assertStatus(409)->assertJsonPath('errors.code', 'duplicate_risk_confirmation_required');
        $this->assertSame('unknown', $m->fresh()->status);

        $this->actingAs($sa)->postJson($url, ['accept_duplicate_risk' => true])->assertOk()->assertJsonPath('data.status', 'queued');
        Queue::assertPushed(SendWhatsAppMessageJob::class, 1);
        $this->assertSame(1, \App\Models\ActivityLog::query()->where('event', 'whatsapp.message.retried')->count());

        // Only failed/unknown can be retried; a sent message cannot.
        $this->fake($this->ok());
        $sent = $d->deliver($m->id);
        $this->assertSame('sent', $sent->status);
        $this->actingAs($sa)->postJson($url, ['accept_duplicate_risk' => true])->assertStatus(422);
    }

    public function test_a_definite_provider_rejection_fails_once_and_can_be_retried_by_the_operator(): void
    {
        Queue::fake();
        $b = $this->branchWithPhone();
        $this->enable([WhatsAppEvents::ORDER_SHIPPED]);
        $m = $this->queuedShipped($b);
        $d = app(WhatsAppDelivery::class);

        $this->fake([self::BASE.'/send/message' => Http::response(['message' => 'invalid phone number'], 400)]);
        $m = $d->deliver($m->id);
        $this->assertSame(['failed', 1, 'rejected'], [$m->status, $m->attempts, $m->error_class]);

        $sa = User::factory()->superAdmin()->create();
        $this->actingAs($sa)->postJson("/api/v1/admin/integrations/whatsapp/messages/{$m->id}/retry")->assertOk()->assertJsonPath('data.status', 'queued');
    }

    public function test_a_message_queued_before_the_gateway_was_switched_off_is_not_sent(): void
    {
        Queue::fake();
        $b = $this->branchWithPhone();
        $this->enable([WhatsAppEvents::ORDER_SHIPPED]);
        $m = $this->queuedShipped($b);
        app(WhatsAppSettings::class)->update(['enabled' => false], User::factory()->superAdmin()->create());
        $this->fake();

        $m = app(WhatsAppDelivery::class)->deliver($m->id);
        $this->assertSame(['skipped', 'gateway_disabled'], [$m->status, $m->skip_reason]);
        Http::assertNothingSent();
    }
}
