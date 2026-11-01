<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\AgentDeviceService;
use App\Services\WhatsApp\GowaDeviceClient;
use App\Services\WhatsApp\MessageTemplates;
use App\Services\WhatsApp\OrderNotifier;
use App\Services\WhatsApp\WhatsAppDelivery;
use App\Services\WhatsApp\WhatsAppEvents;
use App\Services\WhatsApp\WhatsAppSettings;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCrmFixtures;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * R-01 / R-03 scoped tests — exercise the REAL delivery layer (WhatsAppDelivery::deliver) with a faked gateway, not just the
 * enqueue side: an accountless checkout recipient reaches the transport only through the closed restricted policy; everything
 * else still needs a number proof. Nothing is sent for real.
 */
class WhatsAppRestrictedDeliveryTest extends TestCase
{
    use BuildsCrmFixtures;
    use HasTestRegion;
    use RefreshDatabase;

    private const BASE = 'http://gowa.test';

    /** @var array<int,bool> agent ids whose sender slot is paired */
    public static array $paired = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$paired = [];
        $this->app->bind(AgentDeviceService::class, fn ($app) => new class($app->make(GowaDeviceClient::class), $app->make(WhatsAppSettings::class)) extends AgentDeviceService
        {
            public function sendingDeviceId(?int $agentId): ?string
            {
                return $agentId && (WhatsAppRestrictedDeliveryTest::$paired[$agentId] ?? false) ? 'dev-'.$agentId : null;
            }
        });
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([self::BASE.'/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'WA-1']], 200)]);
        app(WhatsAppSettings::class)->update([
            'enabled' => true, 'base_url' => self::BASE, 'username' => 'u', 'password' => 'p', 'device_id' => 'dev-1',
            'max_attempts' => 3, 'backoff_seconds' => 10,
            'events' => array_fill_keys([WhatsAppEvents::ORDER_CREATED, WhatsAppEvents::PAYMENT_PAID], true),
        ], User::factory()->superAdmin()->create());
    }

    /** Order with an UNVERIFIED checkout recipient number; returns [order, restricted recipient row]. */
    private function orderWithRecipient(string $recipientPhone = '081377700002'): array
    {
        Queue::fake();
        $b = $this->branch();
        self::$paired[$b['agen']->id] = true;
        // Nobody on the branch holds a number proof, so the ONLY row is the checkout recipient's.
        $b['konsumen']->forceFill(['phone' => '081377700001'])->save();
        $product = $this->stockedProduct($b['agen']);
        $id = $this->actingAs($b['agen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'konsumen_id' => $b['konsumen']->id,
            'payment_method_code' => 'cod', 'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'Budi', 'recipient_phone' => $recipientPhone, 'address_line' => 'Jl. X',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ])->assertCreated()->json('data.id');
        $order = Order::withoutGlobalScopes()->findOrFail($id);
        $row = WhatsAppMessage::query()->where('order_id', $order->id)->where('recipient_role', 'recipient')->first();

        return [$order, $row, $b];
    }

    private function deliver(WhatsAppMessage $m): WhatsAppMessage
    {
        return app(WhatsAppDelivery::class)->deliver($m->id);
    }

    public function test_unverified_accountless_checkout_recipient_reaches_the_transport(): void
    {
        [$order, $row, $b] = $this->orderWithRecipient();

        $this->assertNotNull($row, 'restricted row created for the checkout recipient');
        $this->assertNull($row->recipient_user_id);
        $this->assertSame('restricted', $row->template_class);

        $done = $this->deliver($row);

        $this->assertSame(WhatsAppMessage::SENT, $done->status);
        $this->assertNull($done->skip_reason);
        $this->assertSame('dev-'.$b['agen']->id, $done->sender_device_id, 'sent from the order Agent\'s own paired slot');
        Http::assertSentCount(1);
        $this->assertStringNotContainsString('Rp', (string) $done->body);
    }

    public function test_duplicate_delivery_never_sends_twice(): void
    {
        [, $row] = $this->orderWithRecipient();

        $this->deliver($row);
        $this->deliver($row->fresh());
        $this->deliver($row->fresh());

        Http::assertSentCount(1);
        $this->assertSame(WhatsAppMessage::SENT, $row->fresh()->status);
    }

    public function test_privileged_template_without_a_proof_is_still_rejected(): void
    {
        [$order, $row] = $this->orderWithRecipient();
        $delivery = app(WhatsAppDelivery::class);

        // A "full" (financial) row for the same unproven number: no restricted leniency applies.
        $full = $delivery->enqueue([
            'idempotency_key' => 'x:full:1', 'purpose' => 'transaction', 'event' => 'payment_paid',
            'template_class' => 'full', 'recipient_role' => 'recipient', 'agent_id' => $order->agent_id, 'order_id' => $order->id,
            'recipient_phone' => $row->recipient_phone, 'body' => 'Total Rp 100.000',
        ], dispatch: false);
        $this->assertSame('number_not_verified', $this->deliver($full)->skip_reason);

        // Mislabeled as restricted but a financial event: not on the allow-list.
        $fin = $delivery->enqueue([
            'idempotency_key' => 'x:fin:1', 'purpose' => 'transaction', 'event' => 'payment_paid',
            'template_class' => 'restricted', 'recipient_role' => 'recipient', 'agent_id' => $order->agent_id, 'order_id' => $order->id,
            'recipient_phone' => $row->recipient_phone, 'body' => 'x',
        ], dispatch: false);
        $this->assertSame('restricted_not_allowed', $this->deliver($fin)->skip_reason);

        // A restricted row can never carry a secret (OTP-style) body.
        $otpLike = $delivery->enqueue([
            'idempotency_key' => 'x:otp:1', 'purpose' => 'transaction', 'event' => 'order_created',
            'template_class' => 'restricted', 'recipient_role' => 'recipient', 'agent_id' => $order->agent_id, 'order_id' => $order->id,
            'recipient_phone' => $row->recipient_phone,
        ], '123456', dispatch: false);
        $this->assertSame('restricted_not_allowed', $this->deliver($otpLike)->skip_reason);

        Http::assertSentCount(0);
    }

    public function test_account_bound_flag_cannot_be_forged_onto_a_restricted_checkout_row(): void
    {
        [$order, $row, $b] = $this->orderWithRecipient();
        $forged = app(WhatsAppDelivery::class)->enqueue([
            'idempotency_key' => 'x:forged:1', 'purpose' => 'transaction', 'event' => 'order_created',
            'template_class' => 'restricted', 'recipient_role' => 'recipient', 'agent_id' => $order->agent_id, 'order_id' => $order->id,
            'recipient_user_id' => $b['konsumen']->id, 'recipient_phone' => $row->recipient_phone, 'body' => 'x',
        ], dispatch: false);

        $this->assertSame('restricted_not_allowed', $this->deliver($forged)->skip_reason);
        Http::assertSentCount(0);
    }

    public function test_wrong_order_or_agent_association_is_rejected(): void
    {
        [$order, $row] = $this->orderWithRecipient();
        $otherAgent = User::factory()->agen()->create();
        self::$paired[$otherAgent->id] = true; // a perfectly healthy foreign sender must still not be used

        $mismatch = app(WhatsAppDelivery::class)->enqueue([
            'idempotency_key' => 'x:xagent:1', 'purpose' => 'transaction', 'event' => 'order_created',
            'template_class' => 'restricted', 'recipient_role' => 'recipient', 'agent_id' => $otherAgent->id, 'order_id' => $order->id,
            'recipient_phone' => $row->recipient_phone, 'body' => 'x',
        ], dispatch: false);
        $this->assertSame('recipient_order_mismatch', $this->deliver($mismatch)->skip_reason);

        $noOrder = app(WhatsAppDelivery::class)->enqueue([
            'idempotency_key' => 'x:noorder:1', 'purpose' => 'transaction', 'event' => 'order_created',
            'template_class' => 'restricted', 'recipient_role' => 'recipient', 'agent_id' => $order->agent_id,
            'recipient_phone' => $row->recipient_phone, 'body' => 'x',
        ], dispatch: false);
        $this->assertSame('restricted_not_allowed', $this->deliver($noOrder)->skip_reason);

        Http::assertSentCount(0);
    }

    public function test_changed_recipient_phone_blocks_the_stale_delivery_and_keeps_the_audit_row(): void
    {
        [$order, $row] = $this->orderWithRecipient('081377700002');
        $order->forceFill(['recipient_phone_snapshot' => '081377700003'])->save();

        $done = $this->deliver($row);

        $this->assertSame(WhatsAppMessage::SKIPPED, $done->status);
        $this->assertSame('recipient_changed', $done->skip_reason);
        $this->assertSame('6281377700002', $done->recipient_phone, 'historical row preserved');
        Http::assertSentCount(0);

        // The current destination is notified by the NEXT event through the normal resolver — never the superseded number.
        $targets = app(\App\Services\WhatsApp\OrderRecipientResolver::class)->resolve($order->refresh());
        $this->assertContains('6281377700003', array_column($targets, 'msisdn'));
        $this->assertNotContains('6281377700002', array_column($targets, 'msisdn'));
    }

    public function test_missing_or_disconnected_configured_sender_fails_safely_without_substitution(): void
    {
        [$order, $row, $b] = $this->orderWithRecipient();
        self::$paired[$b['agen']->id] = false;
        $other = User::factory()->agen()->create();
        self::$paired[$other->id] = true; // another Agent's healthy device must NOT be borrowed

        $done = $this->deliver($row);

        $this->assertSame(WhatsAppMessage::SKIPPED, $done->status);
        $this->assertSame('agent_device_not_ready', $done->skip_reason);
        $this->assertNull($done->sender_device_id);
        Http::assertSentCount(0);
    }

    public function test_financial_events_never_create_a_restricted_row_for_the_checkout_recipient(): void
    {
        [$order, $row] = $this->orderWithRecipient();
        $order->update(['payment_status' => 'paid']);

        $this->assertSame(0, WhatsAppMessage::query()->where('order_id', $order->id)->where('event', 'payment_paid')
            ->where('recipient_role', 'recipient')->where('status', 'queued')->count(), 'a financial fact is never queued for an unproven number');
    }

    public function test_tracking_link_is_never_fabricated(): void
    {
        [$order] = $this->orderWithRecipient();
        $order->load(['konsumen', 'sales', 'placedBy', 'shipments.courier', 'shipments.selfDeliveredBy', 'items', 'paymentTransactions.paymentMethod', 'paymentMethod']);
        $order->setRelation('shipments', collect([(new Shipment)->forceFill(['tracking_number' => 'RESI123456', 'status' => 'shipped'])]));

        $m = new \ReflectionMethod(OrderNotifier::class, 'data');
        $m->setAccessible(true);
        $data = $m->invoke(app(OrderNotifier::class), $order);

        $this->assertNull($data['link_pelacakan'], 'no verified tracking-URL source exists => null, never a guessed domain');
        $this->assertSame('RESI123456', $data['nomor_resi'], 'the real tracking number is still available');

        $body = MessageTemplates::render('order_shipped', "Resi: {nomor_resi}\nLacak: {link_pelacakan}\nKurir: {kurir}", $data);
        $this->assertStringContainsString('RESI123456', (string) $body);
        $this->assertStringNotContainsString('Lacak', (string) $body, 'the tracking-link line is dropped, not faked');
        $this->assertStringNotContainsString('http', (string) $body);
        $this->assertStringNotContainsString('lacak.primeclassy', (string) $body);

        $src = file_get_contents(app_path('Services/WhatsApp/OrderNotifier.php'));
        $this->assertStringNotContainsString('lacak.primeclassy', $src);
    }
}
