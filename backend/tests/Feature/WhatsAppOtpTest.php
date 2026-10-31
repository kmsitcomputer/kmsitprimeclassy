<?php

namespace Tests\Feature;

use App\Mail\OtpChallengeMail;
use App\Models\ConsentEvent;
use App\Models\IdentityOtpChallenge;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\VerifiedWhatsAppNumberResolver;
use App\Services\WhatsApp\WhatsAppSettings;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCrmFixtures;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * WhatsApp as a delivery channel for the EXISTING OTP challenge. Owner rule: the verified account email stays the only
 * accepted channel until a number-ownership proof is ratified, so by default WhatsApp OTP is refused for everyone.
 * HTTP is faked; nothing here reaches a real WhatsApp device.
 */
class WhatsAppOtpTest extends TestCase
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
        Storage::fake('local');
        Mail::fake();
    }

    private function fake(array|\Closure $stubs = []): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake($stubs);
    }

    private function configure(array $otp = [], bool $gatewayOn = true): void
    {
        app(WhatsAppSettings::class)->update([
            'enabled' => $gatewayOn, 'base_url' => self::BASE, 'username' => 'u', 'password' => 'p',
            'otp' => $otp + ['enabled' => true, 'ttl_minutes' => 5, 'max_attempts' => 3, 'cooldown_seconds' => 0, 'limit' => 3, 'limit_window_minutes' => 10],
        ], User::factory()->superAdmin()->create());
    }

    /** Pretends a number-ownership proof exists (it does not in production code). */
    private function proveNumber(string $msisdn = '6281399900011'): void
    {
        $this->app->bind(VerifiedWhatsAppNumberResolver::class, fn () => new class($msisdn) implements VerifiedWhatsAppNumberResolver
        {
            public function __construct(private readonly string $msisdn) {}

            public function msisdnFor(User $subject): ?string
            {
                return $this->msisdn;
            }
        });
    }

    private function issue(User $actor, User $subject, string $channel = 'whatsapp')
    {
        return $this->actingAs($actor)->postJson('/api/v1/consent/otp', [
            'subject_user_id' => $subject->id, 'purpose' => 'assisted_return', 'channel' => $channel,
        ]);
    }

    private function otpFromRequests(): string
    {
        $code = null;
        Http::assertSent(function ($r) use (&$code) {
            if (preg_match('/\b(\d{6})\b/', (string) $r['message'], $m)) {
                $code = $m[1];
            }

            return true;
        });

        return (string) $code;
    }

    /* ----------------------------------------------------------------- blocked by default */

    public function test_whatsapp_otp_is_refused_while_the_gateway_or_otp_option_is_off(): void
    {
        $b = $this->branch();
        $this->fake();

        // Gateway never configured.
        $this->issue($b['sales'], $b['konsumen'])->assertStatus(409)->assertJsonPath('errors.code', 'whatsapp_otp_unavailable');
        // Configured but the OTP option is switched off.
        $this->configure(['enabled' => false]);
        $this->issue($b['sales'], $b['konsumen'])->assertStatus(409)->assertJsonPath('errors.code', 'whatsapp_otp_unavailable');

        Http::assertNothingSent();
        $this->assertSame(0, IdentityOtpChallenge::query()->count());
    }

    public function test_an_unproven_number_is_never_treated_as_verified_so_the_consent_action_stays_blocked(): void
    {
        $b = $this->branch();
        $this->configure();
        $this->fake();
        // The konsumen's phone is on file and a Sales typed a CRM phone — neither is a PROOF of ownership.
        $b['konsumen']->forceFill(['phone' => '081399900011'])->save();
        $customer = $this->crmCustomer($b['sales'], ['phone' => '081399900022']);

        $this->issue($b['sales'], $b['konsumen'])->assertStatus(409)->assertJsonPath('errors.code', 'whatsapp_number_not_verified');
        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$customer}/link-otp", ['konsumen_id' => $b['konsumen']->id, 'channel' => 'whatsapp'])
            ->assertStatus(409)->assertJsonPath('errors.code', 'whatsapp_number_not_verified');

        $this->linkCustomer($b['sales'], $customer, $b['konsumen']); // email channel still works for linking
        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$customer}/consent-otp", ['purpose' => 'assisted_return', 'channel' => 'whatsapp'])
            ->assertStatus(409)->assertJsonPath('errors.code', 'whatsapp_number_not_verified');

        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppMessage::query()->count());
        $this->assertSame(0, ConsentEvent::withoutGlobalScopes()->count());
    }

    public function test_the_email_channel_is_unchanged_and_still_the_default(): void
    {
        $b = $this->branch();
        $this->configure();
        $this->fake();

        $this->issue($b['sales'], $b['konsumen'], 'email')->assertCreated();
        $this->actingAs($b['sales'])->postJson('/api/v1/consent/otp', ['subject_user_id' => $b['konsumen']->id, 'purpose' => 'assisted_return'])
            ->assertCreated(); // no channel => email

        Mail::assertSent(OtpChallengeMail::class, 2);
        Http::assertNothingSent();
    }

    public function test_an_invalid_channel_is_rejected(): void
    {
        $b = $this->branch();
        $this->issue($b['sales'], $b['konsumen'], 'sms')->assertStatus(422);
    }

    /* ----------------------------------------------------------------- with a proven number */

    public function test_a_proven_number_receives_the_code_and_it_is_never_stored_or_logged_in_clear(): void
    {
        $b = $this->branch();
        $this->configure();
        $this->proveNumber();
        $this->fake([self::BASE.'/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'OTP-1']], 200)]);

        $id = $this->issue($b['sales'], $b['konsumen'])->assertCreated()->assertJsonMissingPath('data.code')->json('data.challenge_id');
        $code = $this->otpFromRequests();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $row = WhatsAppMessage::query()->firstOrFail();
        $this->assertSame(['otp', 'sent', '6281399900011', 'OTP-1'], [$row->purpose, $row->status, $row->recipient_phone, $row->provider_message_id]);
        $this->assertStringNotContainsString($code, (string) $row->body);
        $this->assertStringContainsString('••••••', (string) $row->body);
        $this->assertNull($row->secret_body, 'the OTP text is wiped once the message is final');

        $challenge = IdentityOtpChallenge::findOrFail($id);
        $this->assertSame('whatsapp:62••••011', $challenge->sent_to_email);
        $this->assertNotSame($code, $challenge->code_hash);

        // The code appears in NO stored column of either table.
        foreach (['whatsapp_messages', 'identity_otp_challenges'] as $table) {
            $this->assertStringNotContainsString($code, json_encode(DB::table($table)->get()), "$table must not hold the OTP");
        }
        Mail::assertNothingSent();
    }

    public function test_the_code_verifies_once_and_a_sent_message_is_not_a_verification(): void
    {
        $b = $this->branch();
        $this->configure();
        $this->proveNumber();
        $this->fake([self::BASE.'/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'OTP-1']], 200)]);
        $order = $this->deliveredAccountOrder($b);

        $id = $this->issue($b['sales'], $b['konsumen'])->assertCreated()->json('data.challenge_id');
        $code = $this->otpFromRequests();

        // Delivery success alone verifies nothing: consent capture without entering the code is refused.
        $this->capture($b['sales'], $b['konsumen'], $order, $id)->assertStatus(422);

        $wrong = $code === '000000' ? '111111' : '000000';
        $this->actingAs($b['sales'])->postJson("/api/v1/consent/otp/{$id}/verify", ['code' => $wrong])->assertStatus(422);
        $this->actingAs($b['sales'])->postJson("/api/v1/consent/otp/{$id}/verify", ['code' => $code])->assertOk();
        $this->actingAs($b['sales'])->postJson("/api/v1/consent/otp/{$id}/verify", ['code' => $code])->assertStatus(422); // replay

        $this->capture($b['sales'], $b['konsumen'], $order, $id)->assertCreated();
        $this->capture($b['sales'], $b['konsumen'], $order, $id, 'again')->assertStatus(422); // single use
    }

    public function test_a_challenge_cannot_be_used_across_actions_actors_or_after_expiry(): void
    {
        $b = $this->branch();
        $this->configure();
        $this->proveNumber();
        $this->fake([self::BASE.'/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'OTP-1']], 200)]);
        $customer = $this->crmCustomer($b['sales']);

        $id = $this->issue($b['sales'], $b['konsumen'])->assertCreated()->json('data.challenge_id');
        $code = $this->otpFromRequests();

        // Another actor cannot verify it.
        $this->actingAs($b['agen'])->postJson("/api/v1/consent/otp/{$id}/verify", ['code' => $code])->assertForbidden();
        $this->actingAs($b['sales'])->postJson("/api/v1/consent/otp/{$id}/verify", ['code' => $code])->assertOk();

        // A consent (assisted_return) challenge can never link an account: the action is bound to the challenge.
        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$customer}/link", [
            'konsumen_id' => $b['konsumen']->id, 'otp_challenge_id' => $id,
        ])->assertStatus(422);

        // Expiry.
        $id2 = $this->issue($b['sales'], $b['konsumen'])->assertCreated()->json('data.challenge_id');
        $code2 = $this->otpFromRequests();
        IdentityOtpChallenge::whereKey($id2)->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($b['sales'])->postJson("/api/v1/consent/otp/{$id2}/verify", ['code' => $code2])->assertStatus(422);
    }

    public function test_attempts_are_limited_by_the_configured_otp_policy(): void
    {
        $b = $this->branch();
        $this->configure(['max_attempts' => 2]);
        $this->proveNumber();
        $this->fake([self::BASE.'/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'OTP-1']], 200)]);

        $id = $this->issue($b['sales'], $b['konsumen'])->assertCreated()->json('data.challenge_id');
        $code = $this->otpFromRequests();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->assertSame(2, IdentityOtpChallenge::findOrFail($id)->max_attempts);
        $this->actingAs($b['sales'])->postJson("/api/v1/consent/otp/{$id}/verify", ['code' => $wrong])->assertStatus(422);
        $this->actingAs($b['sales'])->postJson("/api/v1/consent/otp/{$id}/verify", ['code' => $wrong])->assertStatus(422);
        $this->actingAs($b['sales'])->postJson("/api/v1/consent/otp/{$id}/verify", ['code' => $code])->assertStatus(429); // locked even for the right code
    }

    public function test_resend_cooldown_and_per_subject_limit(): void
    {
        $b = $this->branch();
        $this->configure(['cooldown_seconds' => 60, 'limit' => 2]);
        $this->proveNumber();
        $this->fake([self::BASE.'/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'OTP-1']], 200)]);

        $this->issue($b['sales'], $b['konsumen'])->assertCreated();
        $this->issue($b['sales'], $b['konsumen'])->assertStatus(429); // inside the cooldown
        Http::assertSentCount(1);

        // After the cooldown the per-subject LIMIT still applies.
        IdentityOtpChallenge::query()->update(['created_at' => now()->subMinutes(2)]);
        $this->issue($b['sales'], $b['konsumen'])->assertCreated();
        IdentityOtpChallenge::query()->update(['created_at' => now()->subMinutes(2)]);
        $this->issue($b['sales'], $b['konsumen'])->assertStatus(429);
        Http::assertSentCount(2);
    }

    public function test_an_undeliverable_code_invalidates_the_challenge_and_keeps_the_action_blocked(): void
    {
        $b = $this->branch();
        $this->configure();
        $this->proveNumber();
        $order = $this->deliveredAccountOrder($b);
        $this->fake([self::BASE.'/send/message' => Http::response(['message' => 'internal error'], 500)]);

        $this->issue($b['sales'], $b['konsumen'])->assertStatus(502)->assertJsonPath('errors.code', 'whatsapp_otp_not_delivered');

        $challenge = IdentityOtpChallenge::query()->firstOrFail();
        $this->assertNotNull($challenge->invalidated_at);
        $row = WhatsAppMessage::query()->firstOrFail();
        $this->assertSame('unknown', $row->status);
        $this->assertNull($row->secret_body, 'a failed OTP leaves no clear text behind');

        $this->actingAs($b['sales'])->postJson("/api/v1/consent/otp/{$challenge->id}/verify", ['code' => '123456'])->assertStatus(422);
        $this->capture($b['sales'], $b['konsumen'], $order, $challenge->id)->assertStatus(422);
        $this->assertSame(0, ConsentEvent::withoutGlobalScopes()->count());

        // OTP messages are never re-sent by an operator.
        $this->actingAs(User::factory()->superAdmin()->create())->postJson("/api/v1/admin/integrations/whatsapp/messages/{$row->id}/retry", ['accept_duplicate_risk' => true])->assertStatus(422);
    }

    public function test_the_whatsapp_otp_channel_does_not_touch_the_default_resolver_contract(): void
    {
        // The container binding resolves only an account-PROVEN number: a phone on file with no proof resolves nothing.
        $this->assertInstanceOf(\App\Services\WhatsApp\VerifiedAccountNumberResolver::class, app(VerifiedWhatsAppNumberResolver::class));
        $this->assertNull(app(VerifiedWhatsAppNumberResolver::class)->msisdnFor(User::factory()->konsumen()->create(['phone' => '081399900011'])));
    }

    /* ----------------------------------------------------------------- helpers */

    private function deliveredAccountOrder(array $b): \App\Models\Order
    {
        $product = $this->stockedProduct($b['agen']);
        $id = $this->actingAs($b['agen'])->withHeaders(['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()])->postJson('/api/v1/orders', [
            'konsumen_id' => $b['konsumen']->id,
            'payment_method_code' => 'cod', 'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. X',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ])->assertCreated()->json('data.id');

        return \App\Models\Order::withoutGlobalScopes()->findOrFail($id);
    }

    private function capture(User $actor, User $subject, \App\Models\Order $order, int $challenge, ?string $key = null)
    {
        return $this->actingAs($actor)->post('/api/v1/consent-events', [
            'subject_user_id' => $subject->id, 'order_id' => $order->id, 'purpose' => 'assisted_return', 'otp_challenge_id' => $challenge,
            'evidence' => UploadedFile::fake()->image('c.jpg'), 'note' => 'Pelanggan setuju.',
            'scope' => [['order_item_id' => $order->items()->first()->id, 'quantity' => 1]],
            ...($key ? ['consent_key' => $key] : []),
        ], ['Accept' => 'application/json']);
    }
}
