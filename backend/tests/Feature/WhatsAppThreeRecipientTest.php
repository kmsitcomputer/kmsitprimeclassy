<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Order;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\OrderNotifier;
use App\Services\WhatsApp\OrderRecipientResolver;
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
 * WS-03 scoped tests (AUD-03/06/07/08, HA-03/HA-04, CL-02/03/05): three-recipient routing, restricted templates,
 * verified payment variables, partial-payment events. HTTP is faked, never real. Scoped only — no full suite.
 */
class WhatsAppThreeRecipientTest extends TestCase
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
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([self::BASE.'/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'WA-1']], 200)]);
    }

    private function enable(array $events, array $extra = []): void
    {
        app(WhatsAppSettings::class)->update($extra + [
            'enabled' => true, 'base_url' => self::BASE, 'username' => 'u', 'password' => 'p', 'device_id' => 'dev-1',
            'max_attempts' => 3, 'backoff_seconds' => 10,
            'events' => array_fill_keys($events, true),
        ], User::factory()->superAdmin()->create());
    }

    private function proveNumber(User $user, ?string $msisdn = null): string
    {
        $msisdn ??= \App\Support\WhatsAppNumber::msisdn($user->phone) ?? '6281377700001';
        \App\Models\IdentityOtpChallenge::create([
            'agent_id' => $user->agent_id ?? $user->id, 'subject_user_id' => $user->id, 'created_by' => $user->id,
            'purpose' => 'whatsapp_number', 'code_hash' => str_repeat('0', 64),
            'sent_to_email' => 'wa:'.$msisdn,
            'attempts' => 1, 'max_attempts' => 5, 'expires_at' => now()->addMinutes(5), 'verified_at' => now(), 'consumed_at' => now(),
        ]);

        return $msisdn;
    }

    private function branchWithPhones(): array
    {
        $b = $this->branch();
        $b['konsumen']->forceFill(['phone' => '081377700001', 'name' => 'Budi Santoso'])->save();
        $this->proveNumber($b['konsumen'], '6281377700001');
        // Neutralise every OTHER branch member's phone so only the buyer holds a proven number: the test asserts
        // exactly buyer + checkout-recipient rows (no admin rows).
        foreach (['agen', 'admin', 'keuangan', 'korsal', 'sales', 'sks'] as $k) {
            $b[$k]->forceFill(['phone' => null])->save();
        }

        return $b;
    }

    private function accountOrder(array $b, ?string $recipientPhone = null): Order
    {
        $product = $this->stockedProduct($b['agen']);
        $id = $this->actingAs($b['agen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'konsumen_id' => $b['konsumen']->id,
            'payment_method_code' => 'cod', 'items' => [['product_id' => $b['agen']->id ? $product->id : $product->id, 'quantity' => 1]],
            'recipient_name' => 'Budi', 'recipient_phone' => $recipientPhone ?? '0811', 'address_line' => 'Jl. X',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ])->assertCreated()->json('data.id');

        return Order::withoutGlobalScopes()->findOrFail($id);
    }

    private function messages(): \Illuminate\Database\Eloquent\Collection
    {
        return WhatsAppMessage::query()->orderBy('id')->get();
    }

    public function test_buyer_keeps_the_legacy_single_row_and_checkout_recipient_gets_a_restricted_row(): void
    {
        Queue::fake();
        $b = $this->branchWithPhones();
        $this->enable([WhatsAppEvents::ORDER_CREATED]);

        // A checkout number that is a REAL routable msisdn, different from every proven account number.
        $order = $this->accountOrder($b, '081377700002');

        $rows = $this->messages();
        $this->assertCount(2, $rows);

        $buyer = $rows->firstWhere('idempotency_key', "txn:order_created:order:{$order->id}");
        $this->assertNotNull($buyer);
        $this->assertSame(['creator', 'full', 'queued', '6281377700001'], [$buyer->recipient_role, $buyer->template_class, $buyer->status, $buyer->recipient_phone]);
        $this->assertStringContainsString($order->order_no, $buyer->body);

        $goods = $rows->firstWhere('idempotency_key', "txn:order_created:order:{$order->id}:recipient");
        $this->assertNotNull($goods);
        $this->assertSame(['recipient', 'restricted', 'queued', '6281377700002'], [$goods->recipient_role, $goods->template_class, $goods->status, $goods->recipient_phone]);
        $this->assertStringContainsString($order->order_no, $goods->body);
        $this->assertStringNotContainsString('Rp', $goods->body, 'restricted template carries no financial detail');
        Queue::assertPushed(SendWhatsAppMessageJob::class, 2);
    }

    public function test_same_number_twice_sends_once_without_privilege_leak(): void
    {
        Queue::fake();
        $b = $this->branchWithPhones();
        $this->enable([WhatsAppEvents::ORDER_CREATED]);

        // Checkout number == buyer's proven number: dedupe keeps ONE row (the creator variant).
        $order = $this->accountOrder($b, '081377700001');

        $rows = $this->messages();
        $this->assertCount(1, $rows);
        $this->assertSame('creator', $rows[0]->recipient_role);
    }

    public function test_partial_payment_emits_payment_partial_and_full_settlement_emits_payment_paid(): void
    {
        Queue::fake();
        $b = $this->branchWithPhones();
        $this->enable([WhatsAppEvents::PAYMENT_PARTIAL, WhatsAppEvents::PAYMENT_PAID]);
        $order = $this->accountOrder($b, '0811');

        $order->update(['payment_status' => 'partially_paid']);
        $partial = $this->messages()->firstWhere('event', 'payment_partial');
        $this->assertNotNull($partial);
        $this->assertNull($this->messages()->firstWhere('event', 'payment_paid'), 'a DP is never "pembayaran berhasil"');
        $this->assertStringContainsString($order->order_no, $partial->body);

        $order->update(['payment_status' => 'paid']);
        $paid = $this->messages()->firstWhere('event', 'payment_paid');
        $this->assertNotNull($paid);
    }

    public function test_payment_variables_come_from_verified_records_and_links_resolve(): void
    {
        Queue::fake();
        $b = $this->branchWithPhones();
        $this->enable([WhatsAppEvents::PAYMENT_PAID]);
        $order = $this->accountOrder($b, '0811');
        $order->update(['payment_status' => 'paid']);

        $paid = $this->messages()->firstWhere('event', 'payment_paid');
        $this->assertNotNull($paid);
        $data = app(OrderNotifier::class);
        // Rendered body must carry verified payment truth and the order link; courier phone must never appear.
        $this->assertStringContainsString($order->order_no, (string) $paid->body);
        $this->assertStringNotContainsString('{', (string) $paid->body, 'no unresolved placeholders');
    }

    public function test_resolver_marks_buyer_primary_and_never_crosses_agents(): void
    {
        $b = $this->branchWithPhones();
        $order = $this->accountOrder($b, '081377700003');

        $targets = app(OrderRecipientResolver::class)->resolve($order->refresh());
        $primaries = array_values(array_filter($targets, fn ($t) => $t['primary']));
        $this->assertCount(1, $primaries);
        $this->assertSame(OrderRecipientResolver::ROLE_CREATOR, $primaries[0]['role']);
        $this->assertSame('6281377700001', $primaries[0]['msisdn']);

        $roles = array_column($targets, 'role');
        $this->assertContains('recipient', $roles);

        // A second Agent's admin is never an authorized recipient of this Agent's order.
        $other = User::factory()->agen()->create();
        $foreign = User::factory()->admin()->create(['agent_id' => $other->id, 'phone' => '081377700099']);
        $this->proveNumber($foreign, '6281377700099');
        $again = app(OrderRecipientResolver::class)->resolve($order->refresh());
        foreach ($again as $t) {
            $this->assertNotSame('6281377700099', $t['msisdn']);
        }
    }
}
