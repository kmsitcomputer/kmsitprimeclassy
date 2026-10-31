<?php

namespace Tests\Feature;

use App\Mail\OtpChallengeMail;
use App\Models\AgentProfile;
use App\Models\ConsentEvent;
use App\Models\IdentityOtpChallenge;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use App\Services\Consent\ConsentService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/** REM-005 / WS-9 (CI-01..CI-05): OTP challenge + append-only consent events. */
class ConsentOtpTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        Storage::fake('local');
        Mail::fake();
    }

    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko QA', 'address' => 'Jl. QA',
            'latitude' => -6.2, 'longitude' => 106.8166,
        ]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $sales = User::factory()->sales()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id, 'sales_id' => $sales->id]);

        return compact('agen', 'admin', 'sales', 'konsumen');
    }

    private function orderFor(User $agen, User $konsumen): Order
    {
        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Consent Cake', 'slug' => 'consent-cake-'.uniqid(),
            'has_variations' => false, 'base_price' => 100000, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);

        $id = $this->actingAs($agen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'konsumen_id' => $konsumen->id,
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ])->json('data.id');

        return Order::withoutGlobalScopes()->findOrFail($id);
    }

    /** Issues an OTP as $actor and returns [challengeId, plainCode]. */
    private function issue(User $actor, User $subject): array
    {
        $id = $this->actingAs($actor)->postJson('/api/v1/consent/otp', [
            'subject_user_id' => $subject->id, 'purpose' => 'assisted_return',
        ])->assertCreated()->assertJsonMissingPath('data.code')->json('data.challenge_id');

        $code = null;
        Mail::assertSent(OtpChallengeMail::class, function (OtpChallengeMail $m) use (&$code) {
            $code = $m->code;

            return true;
        });

        return [$id, $code];
    }

    private function verified(User $actor, User $subject): int
    {
        [$id, $code] = $this->issue($actor, $subject);
        $this->actingAs($actor)->postJson("/api/v1/consent/otp/{$id}/verify", ['code' => $code])->assertOk();

        return $id;
    }

    private function capturePayload(Order $order, User $subject, int $challengeId, array $overrides = []): array
    {
        $item = $order->items()->first();

        return array_merge([
            'subject_user_id' => $subject->id, 'order_id' => $order->id, 'purpose' => 'assisted_return',
            'otp_challenge_id' => $challengeId,
            'evidence' => UploadedFile::fake()->image('consent.jpg'),
            'note' => 'Customer agreed on the phone and confirmed by OTP.',
            'scope' => [['order_item_id' => $item->id, 'quantity' => 2]],
        ], $overrides);
    }

    public function test_otp_is_emailed_never_returned_and_stored_hashed(): void
    {
        $b = $this->branch();
        [$id, $code] = $this->issue($b['sales'], $b['konsumen']);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $row = IdentityOtpChallenge::findOrFail($id);
        $this->assertNotSame($code, $row->code_hash);
        $this->assertSame(64, strlen($row->code_hash));
    }

    public function test_otp_requires_verified_email_and_owning_sales_in_same_agent(): void
    {
        $b = $this->branch();
        $b['konsumen']->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($b['sales'])->postJson('/api/v1/consent/otp', ['subject_user_id' => $b['konsumen']->id, 'purpose' => 'assisted_return'])->assertStatus(422);
        $b['konsumen']->forceFill(['email_verified_at' => now()])->save();

        // Email/phone equality is irrelevant: a Sales who does not own the referral is refused.
        $otherSales = User::factory()->sales()->create(['agent_id' => $b['agen']->id]);
        $this->actingAs($otherSales)->postJson('/api/v1/consent/otp', ['subject_user_id' => $b['konsumen']->id, 'purpose' => 'assisted_return'])->assertForbidden();

        // Cross-Agent (CI-05).
        $other = $this->branch();
        $this->actingAs($other['sales'])->postJson('/api/v1/consent/otp', ['subject_user_id' => $b['konsumen']->id, 'purpose' => 'assisted_return'])->assertForbidden();
        $this->actingAs($other['agen'])->postJson('/api/v1/consent/otp', ['subject_user_id' => $b['konsumen']->id, 'purpose' => 'assisted_return'])->assertForbidden();

        // The konsumen cannot self-issue through the capturing endpoint.
        $this->actingAs($b['konsumen'])->postJson('/api/v1/consent/otp', ['subject_user_id' => $b['konsumen']->id, 'purpose' => 'assisted_return'])->assertForbidden();
    }

    public function test_issue_is_rate_limited_per_subject_and_purpose(): void
    {
        $b = $this->branch();
        for ($i = 0; $i < 3; $i++) {
            $this->issue($b['sales'], $b['konsumen']);
        }
        $this->actingAs($b['sales'])->postJson('/api/v1/consent/otp', ['subject_user_id' => $b['konsumen']->id, 'purpose' => 'assisted_return'])->assertStatus(429);
    }

    public function test_new_issue_invalidates_previous_challenge(): void
    {
        $b = $this->branch();
        [$first, $firstCode] = $this->issue($b['sales'], $b['konsumen']);
        $this->issue($b['sales'], $b['konsumen']);

        $this->actingAs($b['sales'])->postJson("/api/v1/consent/otp/{$first}/verify", ['code' => $firstCode])->assertStatus(422);
    }

    public function test_wrong_codes_lock_the_challenge_and_attempts_persist(): void
    {
        $b = $this->branch();
        [$id, $code] = $this->issue($b['sales'], $b['konsumen']);
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($b['sales'])->postJson("/api/v1/consent/otp/{$id}/verify", ['code' => $wrong])->assertStatus(422);
        }
        $this->assertSame(5, IdentityOtpChallenge::findOrFail($id)->attempts);

        // Even the right code is refused once locked.
        $this->actingAs($b['sales'])->postJson("/api/v1/consent/otp/{$id}/verify", ['code' => $code])->assertStatus(429);
    }

    public function test_expired_code_and_foreign_actor_and_replayed_verification_are_refused(): void
    {
        $b = $this->branch();
        [$id, $code] = $this->issue($b['sales'], $b['konsumen']);

        $this->actingAs($b['agen'])->postJson("/api/v1/consent/otp/{$id}/verify", ['code' => $code])->assertForbidden();

        $this->actingAs($b['sales'])->postJson("/api/v1/consent/otp/{$id}/verify", ['code' => $code])->assertOk();
        $this->actingAs($b['sales'])->postJson("/api/v1/consent/otp/{$id}/verify", ['code' => $code])->assertStatus(422);

        [$id2, $code2] = $this->issue($b['sales'], $b['konsumen']);
        IdentityOtpChallenge::whereKey($id2)->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($b['sales'])->postJson("/api/v1/consent/otp/{$id2}/verify", ['code' => $code2])->assertStatus(422);
    }

    public function test_capture_happy_path_is_single_use_and_idempotent_by_key(): void
    {
        $b = $this->branch();
        $order = $this->orderFor($b['agen'], $b['konsumen']);
        $challenge = $this->verified($b['sales'], $b['konsumen']);

        $payload = $this->capturePayload($order, $b['konsumen'], $challenge, ['consent_key' => 'key-1']);
        $eventId = $this->actingAs($b['sales'])->postJson('/api/v1/consent-events', $payload)
            ->assertCreated()->assertJsonPath('data.event_type', 'consent_captured')->json('data.id');

        $event = ConsentEvent::withoutGlobalScopes()->findOrFail($eventId);
        $this->assertSame($b['sales']->id, $event->actor_id);
        $this->assertSame($b['konsumen']->id, $event->subject_user_id);
        $this->assertSame(64, strlen($event->evidence_sha256));
        Storage::disk('local')->assertExists($event->evidence_path);
        $this->assertNotNull(IdentityOtpChallenge::findOrFail($challenge)->consumed_at);

        // Same key from the same actor replays the same event without a second row.
        $again = $this->capturePayload($order, $b['konsumen'], $challenge, ['consent_key' => 'key-1']);
        $this->actingAs($b['sales'])->postJson('/api/v1/consent-events', $again)->assertCreated()->assertJsonPath('data.id', $eventId);
        $this->assertSame(1, ConsentEvent::withoutGlobalScopes()->count());

        // A consumed challenge cannot back a second consent (new key).
        $this->actingAs($b['sales'])->postJson('/api/v1/consent-events', $this->capturePayload($order, $b['konsumen'], $challenge, ['consent_key' => 'key-2']))
            ->assertStatus(422);

        $this->assertTrue(app(ConsentService::class)->hasValidConsent($b['konsumen'], $order, 'assisted_return'));
    }

    public function test_capture_refuses_unverified_challenge_bad_scope_missing_evidence_and_foreign_order(): void
    {
        $b = $this->branch();
        $order = $this->orderFor($b['agen'], $b['konsumen']);

        // Issued but never verified.
        [$unverified] = $this->issue($b['sales'], $b['konsumen']);
        $this->actingAs($b['sales'])->postJson('/api/v1/consent-events', $this->capturePayload($order, $b['konsumen'], $unverified))->assertStatus(422);

        $challenge = $this->verified($b['sales'], $b['konsumen']);
        $item = $order->items()->first();

        $this->actingAs($b['sales'])->postJson('/api/v1/consent-events', $this->capturePayload($order, $b['konsumen'], $challenge, [
            'scope' => [['order_item_id' => $item->id, 'quantity' => 99]],
        ]))->assertStatus(422);

        $noEvidence = $this->capturePayload($order, $b['konsumen'], $challenge);
        unset($noEvidence['evidence']);
        $this->actingAs($b['sales'])->postJson('/api/v1/consent-events', $noEvidence)->assertStatus(422);

        $noNote = $this->capturePayload($order, $b['konsumen'], $challenge);
        unset($noNote['note']);
        $this->actingAs($b['sales'])->postJson('/api/v1/consent-events', $noNote)->assertStatus(422);

        // Order of another konsumen.
        $otherKonsumen = User::factory()->konsumen()->create(['agent_id' => $b['agen']->id, 'sales_id' => $b['sales']->id]);
        $otherOrder = $this->orderFor($b['agen'], $otherKonsumen);
        $this->actingAs($b['sales'])->postJson('/api/v1/consent-events', $this->capturePayload($otherOrder, $b['konsumen'], $challenge))->assertStatus(422);

        $this->assertSame(0, ConsentEvent::withoutGlobalScopes()->count());
        $this->assertNull(IdentityOtpChallenge::findOrFail($challenge)->consumed_at);
    }

    public function test_withdraw_dispute_reinstate_chain_and_authority(): void
    {
        $b = $this->branch();
        $order = $this->orderFor($b['agen'], $b['konsumen']);
        $challenge = $this->verified($b['sales'], $b['konsumen']);
        $root = $this->actingAs($b['sales'])->postJson('/api/v1/consent-events', $this->capturePayload($order, $b['konsumen'], $challenge))
            ->assertCreated()->json('data.id');
        $svc = app(ConsentService::class);

        // Dispute -> suspended; Sales may not reinstate; reviewer may.
        $this->actingAs($b['konsumen'])->postJson("/api/v1/consent-events/{$root}/dispute", ['reason' => 'Not me'])->assertCreated();
        $this->assertFalse($svc->hasValidConsent($b['konsumen'], $order, 'assisted_return'));
        $this->actingAs($b['sales'])->postJson("/api/v1/consent-events/{$root}/reinstate", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($b['konsumen'])->postJson("/api/v1/consent-events/{$root}/reinstate", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($b['admin'])->postJson("/api/v1/consent-events/{$root}/reinstate", ['reason' => 'Verified with customer'])->assertCreated();
        $this->assertTrue($svc->hasValidConsent($b['konsumen'], $order, 'assisted_return'));

        // Withdraw by the customer; a second withdraw is an invalid transition.
        $this->actingAs($b['konsumen'])->postJson("/api/v1/consent-events/{$root}/withdraw", ['reason' => 'Changed my mind'])->assertCreated();
        $this->assertFalse($svc->hasValidConsent($b['konsumen'], $order, 'assisted_return'));
        $this->actingAs($b['konsumen'])->postJson("/api/v1/consent-events/{$root}/withdraw", ['reason' => 'again'])->assertStatus(422);
        // Reinstate only follows a dispute.
        $this->actingAs($b['admin'])->postJson("/api/v1/consent-events/{$root}/reinstate", ['reason' => 'x'])->assertStatus(422);

        $this->assertSame(4, ConsentEvent::withoutGlobalScopes()->count());
        $this->actingAs($b['agen'])->getJson("/api/v1/orders/{$order->id}/consent-events")->assertOk()->assertJsonCount(4, 'data');
    }

    public function test_cross_agent_and_unrelated_actors_cannot_touch_events(): void
    {
        $b = $this->branch();
        $order = $this->orderFor($b['agen'], $b['konsumen']);
        $challenge = $this->verified($b['sales'], $b['konsumen']);
        $root = $this->actingAs($b['sales'])->postJson('/api/v1/consent-events', $this->capturePayload($order, $b['konsumen'], $challenge))->json('data.id');

        $other = $this->branch();
        // Agent global scope hides the row entirely -> 404, not 403.
        $this->actingAs($other['agen'])->postJson("/api/v1/consent-events/{$root}/dispute", ['reason' => 'x'])->assertNotFound();
        $this->actingAs($other['agen'])->getJson("/api/v1/orders/{$order->id}/consent-events")->assertNotFound();

        // Same Agent but another konsumen / non-owning sales has no authority.
        $stranger = User::factory()->konsumen()->create(['agent_id' => $b['agen']->id]);
        $this->actingAs($stranger)->postJson("/api/v1/consent-events/{$root}/withdraw", ['reason' => 'x'])->assertForbidden();
        $otherSales = User::factory()->sales()->create(['agent_id' => $b['agen']->id]);
        $this->actingAs($otherSales)->postJson("/api/v1/consent-events/{$root}/dispute", ['reason' => 'x'])->assertForbidden();
    }

    public function test_consent_context_reveals_subject_only_to_authorized_capturers(): void
    {
        $b = $this->branch();
        $order = $this->orderFor($b['agen'], $b['konsumen']);

        $ctx = $this->actingAs($b['sales'])->getJson("/api/v1/orders/{$order->id}/consent-context")->assertOk()->json('data');
        $this->assertTrue($ctx['can_capture']);
        $this->assertSame($b['konsumen']->id, $ctx['subject_user_id']);
        $this->assertSame(3, $ctx['items'][0]['max_quantity']);

        // Admin can view the order but may not capture: no subject id, no items leaked.
        $admin = $this->actingAs($b['admin'])->getJson("/api/v1/orders/{$order->id}/consent-context")->assertOk()->json('data');
        $this->assertFalse($admin['can_capture']);
        $this->assertArrayNotHasKey('subject_user_id', $admin);

        // The konsumen themself never receives capture context; a foreign Agent cannot see the order.
        $self = $this->actingAs($b['konsumen'])->getJson("/api/v1/orders/{$order->id}/consent-context")->assertOk()->json('data');
        $this->assertFalse($self['can_capture']);
        $other = $this->branch();
        $this->actingAs($other['sales'])->getJson("/api/v1/orders/{$order->id}/consent-context")->assertNotFound();
    }

    public function test_events_are_append_only(): void
    {
        $b = $this->branch();
        $order = $this->orderFor($b['agen'], $b['konsumen']);
        $challenge = $this->verified($b['sales'], $b['konsumen']);
        $id = $this->actingAs($b['sales'])->postJson('/api/v1/consent-events', $this->capturePayload($order, $b['konsumen'], $challenge))->json('data.id');
        $event = ConsentEvent::withoutGlobalScopes()->findOrFail($id);

        $this->expectException(\RuntimeException::class);
        $event->update(['note' => 'tampered']);
    }

    public function test_delete_of_event_is_refused(): void
    {
        $b = $this->branch();
        $order = $this->orderFor($b['agen'], $b['konsumen']);
        $challenge = $this->verified($b['sales'], $b['konsumen']);
        $id = $this->actingAs($b['sales'])->postJson('/api/v1/consent-events', $this->capturePayload($order, $b['konsumen'], $challenge))->json('data.id');

        $this->expectException(\RuntimeException::class);
        ConsentEvent::withoutGlobalScopes()->findOrFail($id)->delete();
    }
}
