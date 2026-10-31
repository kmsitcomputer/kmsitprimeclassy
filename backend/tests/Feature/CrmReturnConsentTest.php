<?php

namespace Tests\Feature;

use App\Models\ConsentEvent;
use App\Models\IdentityOtpChallenge;
use App\Models\Order;
use App\Models\RefundRecipientEvidence;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Services\Refund\RefundRecipientGuard;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCrmFixtures;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * OD-02 / WS-9 gate 7 / G17-D4 + Owner decision 2026-10-31: CRM consent needs an OTP on a VERIFIED customer channel
 * (the linked konsumen account's verified email). Photo + note are required evidence but NEVER enough on their own,
 * and with no verified channel the action is blocked — no bypass.
 */
class CrmReturnConsentTest extends TestCase
{
    use BuildsCrmFixtures;
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        Storage::fake('local');
        Storage::fake('public');
        Mail::fake();
    }

    /** CRM customer (optionally linked to the branch konsumen) + a delivered COD order (returns need `terkirim`). */
    private function deliveredCrmOrder(array $b, bool $link = true): array
    {
        $customer = $this->crmCustomer($b['sales']);
        if ($link) {
            $this->linkCustomer($b['sales'], $customer, $b['konsumen']);
        }

        return [$customer, $this->deliveredOrderFor($b, $customer)];
    }

    private function deliveredOrderFor(array $b, int $customer): Order
    {
        $order = $this->assistedOrder($b['sales'], $customer, $this->stockedProduct($b['agen']));
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'dikirim'])->assertOk();
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'terkirim'])->assertOk();

        return Order::withoutGlobalScopes()->findOrFail($order->id);
    }

    /** Capture request; `$challenge` is the OTP challenge to present (null = omit it entirely). */
    private function capture(User $actor, int $customer, Order $order, ?int $challenge, int $qty = 2, array $over = [])
    {
        $payload = $over + [
            'order_id' => $order->id, 'purpose' => 'assisted_return',
            'evidence' => UploadedFile::fake()->image('consent.jpg'),
            'note' => 'Pelanggan setuju mengembalikan barang.',
            'scope' => [['order_item_id' => $order->items()->first()->id, 'quantity' => $qty]],
        ];
        if ($challenge !== null) {
            $payload['otp_challenge_id'] = $challenge;
        }

        return $this->actingAs($actor)->post("/api/v1/crm/customers/{$customer}/consent-events", $payload, ['Accept' => 'application/json']);
    }

    /** Full happy path: OTP issued, verified by the owner, consent captured. Returns the consent event id. */
    private function consent(User $actor, int $customer, Order $order, int $qty = 2): int
    {
        return $this->capture($actor, $customer, $order, $this->verifiedConsentOtp($actor, $customer), $qty)
            ->assertCreated()->json('data.id');
    }

    private function initiate(User $actor, Order $order, ?int $consentId, int $qty = 1, array $over = [])
    {
        return $this->actingAs($actor)->post("/api/v1/orders/{$order->id}/assisted-returns", $over + [
            'consent_event_id' => $consentId ?? 0, 'reason' => 'Rusak saat diterima',
            'evidence' => UploadedFile::fake()->image('rusak.jpg'),
            'items' => [['order_item_id' => $order->items()->first()->id, 'quantity' => $qty]],
        ], ['Accept' => 'application/json']);
    }

    /* ------------------------------------------------------------------ consent verification (Owner 2026-10-31) */

    public function test_consent_is_otp_verified_on_the_linked_account_and_binds_identity_customer_actor_and_hash(): void
    {
        $b = $this->branch();
        [$customer, $order] = $this->deliveredCrmOrder($b);

        $id = $this->consent($b['sales'], $customer, $order);

        $event = ConsentEvent::withoutGlobalScopes()->findOrFail($id);
        $this->assertSame($b['konsumen']->id, $event->subject_user_id, 'the verified identity that answered the OTP');
        $this->assertSame($customer, $event->crm_customer_id);
        $this->assertSame($order->id, $event->order_id);
        $this->assertSame($b['sales']->id, $event->actor_id);
        $this->assertNotNull($event->otp_challenge_id);
        $this->assertNotNull(IdentityOtpChallenge::find($event->otp_challenge_id)->consumed_at);
        $this->assertSame(64, strlen($event->evidence_sha256));
        Storage::disk('local')->assertExists($event->evidence_path);
    }

    public function test_photo_and_note_alone_are_never_accepted_as_identity_verification(): void
    {
        $b = $this->branch();
        [$customer, $order] = $this->deliveredCrmOrder($b);

        // Evidence + note + scope but NO OTP challenge at all.
        $this->capture($b['sales'], $customer, $order, null)->assertStatus(422);

        // An OTP that was issued but never verified.
        $issued = $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$customer}/consent-otp", ['purpose' => 'assisted_return'])
            ->assertCreated()->json('data.challenge_id');
        $this->capture($b['sales'], $customer, $order, $issued)->assertStatus(422);

        // A made-up challenge id.
        $this->capture($b['sales'], $customer, $order, 99999999)->assertStatus(422);

        $this->assertSame(0, ConsentEvent::withoutGlobalScopes()->count(), 'nothing may be recorded without a verified OTP');
        $this->assertNull(IdentityOtpChallenge::find($issued)->consumed_at);
    }

    public function test_without_a_verified_channel_consent_is_blocked_with_no_bypass(): void
    {
        $b = $this->branch();
        [$customer, $order] = $this->deliveredCrmOrder($b, link: false);

        // Unlinked: no OTP can even be issued...
        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$customer}/consent-otp", ['purpose' => 'assisted_return'])->assertStatus(409);

        // ...and the capture endpoint refuses, whatever challenge is presented (even a real, verified link challenge).
        $konsumenOtp = $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$customer}/link-otp", ['konsumen_id' => $b['konsumen']->id])
            ->assertCreated()->json('data.challenge_id');
        $this->actingAs($b['sales'])->postJson("/api/v1/consent/otp/{$konsumenOtp}/verify", ['code' => $this->lastOtpCode()])->assertOk();
        $this->capture($b['sales'], $customer, $order, $konsumenOtp)->assertStatus(409);

        // The context tells the UI the channel is unavailable.
        $this->actingAs($b['sales'])->getJson("/api/v1/orders/{$order->id}/consent-context")->assertOk()
            ->assertJsonPath('data.mode', 'crm')->assertJsonPath('data.can_capture', true)->assertJsonPath('data.channel_available', false);

        // No consent => no assisted return, so the dependent action stays blocked end to end.
        $this->initiate($b['sales'], $order, null)->assertStatus(404);
        $this->assertSame(0, ConsentEvent::withoutGlobalScopes()->count());
        $this->assertSame(0, ReturnRequest::query()->count());
    }

    public function test_an_unverified_account_email_is_not_a_verified_channel(): void
    {
        $b = $this->branch();
        [$customer] = $this->deliveredCrmOrder($b);
        $b['konsumen']->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$customer}/consent-otp", ['purpose' => 'assisted_return'])->assertStatus(422);
    }

    public function test_unlinking_removes_the_channel_even_for_an_already_verified_challenge(): void
    {
        $b = $this->branch();
        [$customer, $order] = $this->deliveredCrmOrder($b);
        $challenge = $this->verifiedConsentOtp($b['sales'], $customer);

        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$customer}/unlink", ['reason' => 'Salah tautan'])->assertOk();

        $this->capture($b['sales'], $customer, $order, $challenge)->assertStatus(409);
        $this->assertSame(0, ConsentEvent::withoutGlobalScopes()->count());
        $this->assertNull(IdentityOtpChallenge::find($challenge)->consumed_at, 'a blocked capture must not burn the challenge');
    }

    public function test_a_challenge_is_bound_to_its_customer_actor_and_single_use(): void
    {
        $b = $this->branch();
        [$customerA, $orderA] = $this->deliveredCrmOrder($b);

        $second = User::factory()->konsumen()->create(['agent_id' => $b['agen']->id, 'sales_id' => $b['sales']->id]);
        $customerB = $this->crmCustomer($b['sales'], ['name' => 'B']);
        $this->linkCustomer($b['sales'], $customerB, $second);
        $orderB = $this->deliveredOrderFor($b, $customerB);

        // Challenge issued for customer B presented for customer A's order/customer.
        $challengeB = $this->verifiedConsentOtp($b['sales'], $customerB);
        $this->capture($b['sales'], $customerA, $orderA, $challengeB)->assertStatus(422);

        // Single use: the same challenge cannot back a second consent.
        $challengeA = $this->verifiedConsentOtp($b['sales'], $customerA);
        $this->capture($b['sales'], $customerA, $orderA, $challengeA)->assertCreated();
        $this->capture($b['sales'], $customerA, $orderA, $challengeA, 1, ['consent_key' => 'again'])->assertStatus(422);

        $this->assertSame(1, ConsentEvent::withoutGlobalScopes()->count());
        $this->assertNotNull($orderB->id);
    }

    public function test_only_the_owner_issues_or_captures_and_required_evidence_is_enforced(): void
    {
        $b = $this->branch();
        [$customer, $order] = $this->deliveredCrmOrder($b);
        $challenge = $this->verifiedConsentOtp($b['sales'], $customer);

        foreach (['sks', 'agen', 'korsal', 'admin'] as $role) {
            $this->actingAs($b[$role])->postJson("/api/v1/crm/customers/{$customer}/consent-otp", ['purpose' => 'assisted_return'])->assertForbidden();
            $this->capture($b[$role], $customer, $order, $challenge)->assertForbidden();
        }

        // The OTP never replaces the photo, note or scope.
        $this->capture($b['sales'], $customer, $order, $challenge, 2, ['evidence' => null])->assertStatus(422);
        $this->capture($b['sales'], $customer, $order, $challenge, 2, ['note' => null])->assertStatus(422);
        $this->capture($b['sales'], $customer, $order, $challenge, 99)->assertStatus(422);
        $this->assertSame(0, ConsentEvent::withoutGlobalScopes()->count());

        // Another Agent cannot see the customer at all.
        $other = $this->branch();
        $this->actingAs($other['sales'])->postJson("/api/v1/crm/customers/{$customer}/consent-otp", ['purpose' => 'assisted_return'])->assertNotFound();

        $this->capture($b['sales'], $customer, $order, $challenge)->assertCreated();
        $this->actingAs($b['sales'])->getJson("/api/v1/orders/{$order->id}/consent-context")->assertOk()
            ->assertJsonPath('data.channel_available', true);
        $this->actingAs($b['agen'])->getJson("/api/v1/orders/{$order->id}/consent-context")->assertOk()
            ->assertJsonPath('data.can_capture', false);
    }

    /* ------------------------------------------------------------------ assisted return (consent-gated) */

    public function test_assisted_return_needs_valid_consent_inside_its_scope_and_keeps_the_existing_flow(): void
    {
        $b = $this->branch();
        [$customer, $order] = $this->deliveredCrmOrder($b);

        $this->initiate($b['sales'], $order, null)->assertStatus(404);
        $this->assertSame(0, ReturnRequest::query()->count());

        $consent = $this->consent($b['sales'], $customer, $order, 2);

        $this->initiate($b['sks'], $order, $consent)->assertForbidden();
        $this->initiate($b['agen'], $order, $consent)->assertStatus(403);
        $this->initiate($b['sales'], $order, $consent, 3)->assertStatus(422); // beyond the consent quota

        $returnId = $this->initiate($b['sales'], $order, $consent, 1)->assertCreated()->json('data.id');
        $return = ReturnRequest::findOrFail($returnId);
        $this->assertSame($b['sales']->id, $return->requested_by, 'initiator recorded, not the customer');
        $this->assertSame($consent, $return->consent_event_id);
        $this->assertSame('requested', $return->status);

        // The same item cannot be returned again while its return is open (existing rule, unchanged).
        $this->initiate($b['sales'], $order, $consent, 1)->assertStatus(422);
        $this->assertSame(1, ReturnRequest::query()->count());

        // Existing authorities are unchanged.
        $this->actingAs($b['sales'])->patchJson("/api/v1/admin/returns/{$returnId}/review", ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($b['konsumen'])->post("/api/v1/orders/{$order->id}/returns", [
            'reason' => 'x', 'evidence' => UploadedFile::fake()->image('x.jpg'),
            'items' => [['order_item_id' => $order->items()->first()->id, 'quantity' => 1]],
        ], ['Accept' => 'application/json'])->assertStatus(403);
    }

    public function test_withdrawn_or_disputed_consent_blocks_new_initiation_until_reinstated(): void
    {
        $b = $this->branch();
        [$customer, $order] = $this->deliveredCrmOrder($b);
        $consent = $this->consent($b['sales'], $customer, $order, 3);

        $this->actingAs($b['sales'])->postJson("/api/v1/consent-events/{$consent}/dispute", ['reason' => 'Pelanggan keberatan'])->assertCreated();
        $this->initiate($b['sales'], $order, $consent)->assertStatus(422);

        $this->actingAs($b['sales'])->postJson("/api/v1/consent-events/{$consent}/reinstate", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($b['admin'])->postJson("/api/v1/consent-events/{$consent}/reinstate", ['reason' => 'Dikonfirmasi ulang'])->assertCreated();
        $this->initiate($b['sales'], $order, $consent)->assertCreated();

        $this->actingAs($b['sales'])->postJson("/api/v1/consent-events/{$consent}/withdraw", ['reason' => 'Batal'])->assertCreated();
        $this->initiate($b['sales'], $order, $consent)->assertStatus(422);

        $other = $this->branch();
        $this->actingAs($other['admin'])->postJson("/api/v1/consent-events/{$consent}/reinstate", ['reason' => 'x'])->assertNotFound();
    }

    /* ------------------------------------------------------------------ refund recipient evidence (G17-D4) */

    public function test_refund_is_blocked_until_recipient_evidence_exists_for_crm_orders_only(): void
    {
        $b = $this->branch();
        [, $order] = $this->deliveredCrmOrder($b, link: false);

        try {
            RefundRecipientGuard::assertReady($order);
            $this->fail('A CRM-origin refund must be blocked without recipient evidence.');
        } catch (\App\Exceptions\ApiException $e) {
            $this->assertSame(422, $e->status());
        }

        RefundRecipientGuard::assertReady(new Order(['konsumen_id' => $b['konsumen']->id])); // account origin untouched

        $base = ['recipient_name' => 'Ibu Rina', 'method' => 'bank_transfer', 'authorization_note' => 'Pelanggan minta transfer ke rekening sendiri.'];
        $this->actingAs($b['sales'])->postJson("/api/v1/orders/{$order->id}/refund-recipient", $base)->assertStatus(422);
        $this->actingAs($b['sales'])->post("/api/v1/orders/{$order->id}/refund-recipient", $base + [
            'evidence' => UploadedFile::fake()->image('ktp.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(422); // bank method without destination

        $row = $this->actingAs($b['sales'])->post("/api/v1/orders/{$order->id}/refund-recipient", $base + [
            'evidence' => UploadedFile::fake()->image('persetujuan.jpg'),
            'destination' => ['bank_or_provider' => 'BCA', 'account_number' => '1234567890', 'account_holder' => 'Rina'],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        RefundRecipientGuard::assertReady($order->fresh());
        $this->assertSame(['account_number_tail' => '7890'], $row['destination'], 'seller sees a masked tail only');

        $full = $this->actingAs($b['keuangan'])->getJson("/api/v1/orders/{$order->id}/refund-recipient")->assertOk()->json('data.0.destination');
        $this->assertSame('1234567890', $full['account_number']);
        $this->assertStringNotContainsString('1234567890', (string) DB::table('refund_recipient_evidences')->value('destination_details'));

        $this->actingAs($b['keuangan'])->post("/api/v1/orders/{$order->id}/refund-recipient", array_merge($base, [
            'method' => 'cash', 'authorization_note' => 'Diambil tunai di toko.', 'evidence' => UploadedFile::fake()->image('tunai.jpg'),
        ]), ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.supersedes_id', $row['id']);
        $this->assertSame(2, RefundRecipientEvidence::withoutGlobalScopes()->count());

        $this->expectException(\RuntimeException::class);
        RefundRecipientEvidence::withoutGlobalScopes()->first()->update(['recipient_name' => 'tamper']);
    }

    public function test_recipient_evidence_authority_isolation_and_consent_reference(): void
    {
        $b = $this->branch();
        [$customer, $order] = $this->deliveredCrmOrder($b);
        $payload = ['recipient_name' => 'Ibu Rina', 'method' => 'cash', 'authorization_note' => 'Tunai di toko.'];

        $consent = $this->consent($b['sales'], $customer, $order);
        $this->actingAs($b['sales'])->postJson("/api/v1/orders/{$order->id}/refund-recipient", $payload + ['consent_event_id' => $consent])->assertCreated();

        // A consent that belongs to another order is refused.
        $order2 = $this->deliveredOrderFor($b, $customer);
        $this->actingAs($b['sales'])->postJson("/api/v1/orders/{$order2->id}/refund-recipient", $payload + ['consent_event_id' => $consent])->assertStatus(422);

        $otherSales = User::factory()->sales()->create(['agent_id' => $b['agen']->id]);
        $this->actingAs($otherSales)->postJson("/api/v1/orders/{$order->id}/refund-recipient", $payload + ['consent_event_id' => $consent])->assertStatus(403);
        $this->actingAs($b['korsal'])->postJson("/api/v1/orders/{$order->id}/refund-recipient", $payload + ['consent_event_id' => $consent])->assertForbidden();
        $this->actingAs($b['konsumen'])->postJson("/api/v1/orders/{$order->id}/refund-recipient", $payload + ['consent_event_id' => $consent])->assertForbidden();

        $other = $this->branch();
        $this->actingAs($other['keuangan'])->getJson("/api/v1/orders/{$order->id}/refund-recipient")->assertNotFound();

        $accountOrder = $this->actingAs($b['agen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'konsumen_id' => $b['konsumen']->id,
            'payment_method_code' => 'cod', 'items' => [['product_id' => $order->items()->first()->product_id, 'quantity' => 1]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. X',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ])->assertCreated()->json('data.id');
        $this->actingAs($b['keuangan'])->postJson("/api/v1/orders/{$accountOrder}/refund-recipient", $payload + ['consent_event_id' => $consent])->assertStatus(422);
    }
}
