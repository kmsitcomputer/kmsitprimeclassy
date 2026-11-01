<?php

namespace Tests\Feature;

use App\Mail\OtpChallengeMail;
use App\Models\IdentityOtpChallenge;
use App\Models\User;
use App\Services\Auth\AccountRecoveryService;
use App\Services\Auth\EmailVerificationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * WS-04 scoped tests (AUD contact-verification + recovery): email verification (request/confirm, invalidation on
 * change) and enumeration-resistant password recovery over email + WhatsApp (mocked transports, never real sends).
 * Scoped only — no full suite.
 */
class AuthRecoveryTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\AssumesPairedAgentDevices;

    private const BASE = 'http://gowa.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assumeEveryAgentHasAPairedDevice();
        config(['auth.recovery_floor_ms' => 0]);
        $this->seed(RoleSeeder::class);
        Mail::fake();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([self::BASE.'/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'WA-1']], 200)]);
        app(\App\Services\WhatsApp\WhatsAppSettings::class)->update([
            'enabled' => true, 'base_url' => self::BASE, 'username' => 'u', 'password' => 'p', 'max_attempts' => 3, 'backoff_seconds' => 10,
            'otp' => ['enabled' => true, 'ttl_minutes' => 5, 'max_attempts' => 3, 'cooldown_seconds' => 0, 'limit' => 3, 'limit_window_minutes' => 10],
        ], User::factory()->superAdmin()->create());
    }

    private function agent(): User
    {
        return User::factory()->agen()->create();
    }

    private function konsumen(User $agent, array $overrides = []): User
    {
        return User::factory()->konsumen()->create(['agent_id' => $agent->id] + $overrides);
    }

    private function issueCode(User $user): string
    {
        // Read the HMAC input back through a notification fake would not expose; instead re-derive by issuing
        // through the service with a known code path is not possible — capture the mailed code via Mail::fake.
        // We trigger the endpoint and pull the 6-digit code from the fake's captured mailable.
        Mail::assertNothingSent();
        $this->actingAs($user)->postJson('/api/v1/profile/email/verification')->assertCreated();
        $code = null;
        Mail::assertSent(OtpChallengeMail::class, function (OtpChallengeMail $m) use (&$code) {
            $code = $m->code;

            return true;
        });
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $code);

        return (string) $code;
    }

    public function test_email_verification_marks_the_current_address_verified(): void
    {
        $agent = $this->agent();
        $u = $this->konsumen($agent, ['email' => 'verify.me@example.com', 'email_verified_at' => null]);

        $this->actingAs($u)->getJson('/api/v1/auth/me')->assertOk();

        $code = $this->issueCode($u);
        $challenge = IdentityOtpChallenge::query()
            ->where('subject_user_id', $u->id)->where('purpose', EmailVerificationService::PURPOSE)
            ->orderByDesc('id')->firstOrFail();

        $this->actingAs($u)
            ->postJson("/api/v1/profile/email/verification/{$challenge->id}/confirm", ['code' => '000000'])
            ->assertStatus(422);
        $this->assertNull($u->fresh()->email_verified_at);

        $this->actingAs($u)
            ->postJson("/api/v1/profile/email/verification/{$challenge->id}/confirm", ['code' => $code])
            ->assertOk();
        $this->assertNotNull($u->fresh()->email_verified_at);

        // Already verified: requesting again sends nothing.
        Mail::fake();
        $this->actingAs($u->fresh())->postJson('/api/v1/profile/email/verification')->assertOk();
        Mail::assertNothingSent();
    }

    public function test_email_change_invalidates_verification_and_stale_codes_are_refused(): void
    {
        $agent = $this->agent();
        $u = $this->konsumen($agent, ['email' => 'old@example.com', 'email_verified_at' => now()]);

        // Request a code for the OLD address (verified, so request() returns null — force one via a fresh unverified user instead).
        $u2 = $this->konsumen($agent, ['email' => 'stale@example.com', 'email_verified_at' => null]);
        $code = $this->issueCode($u2);
        $challenge = IdentityOtpChallenge::query()
            ->where('subject_user_id', $u2->id)->where('purpose', EmailVerificationService::PURPOSE)
            ->orderByDesc('id')->firstOrFail();

        // Change the address before confirming: the code proved the OLD address and must be refused.
        $this->actingAs($u2)->patchJson('/api/v1/profile', ['email' => 'brand.new@example.com'])->assertOk();
        $this->assertNull($u2->fresh()->email_verified_at);
        $this->actingAs($u2->fresh())
            ->postJson("/api/v1/profile/email/verification/{$challenge->id}/confirm", ['code' => $code])
            ->assertStatus(422);
    }

    public function test_password_recovery_over_email_resets_and_revokes_tokens(): void
    {
        $agent = $this->agent();
        $u = $this->konsumen($agent, ['email' => 'recover.me@example.com', 'email_verified_at' => now()]);
        $u->createToken('before');
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->postJson('/api/v1/auth/forgot-password', ['identifier' => 'recover.me@example.com'])
            ->assertOk()->assertJsonPath('success', true);

        $challenge = IdentityOtpChallenge::query()
            ->where('subject_user_id', $u->id)->where('purpose', AccountRecoveryService::PURPOSE)
            ->orderByDesc('id')->firstOrFail();

        $code = null;
        Mail::assertSent(OtpChallengeMail::class, function (OtpChallengeMail $m) use (&$code) {
            $code = $m->code;

            return true;
        });

        $this->postJson('/api/v1/auth/reset-password', [
            'identifier' => 'recover.me@example.com', 'code' => '000000',
            'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
        ])->assertStatus(422);

        $this->postJson('/api/v1/auth/reset-password', [
            'identifier' => 'recover.me@example.com', 'code' => $code,
            'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password-123', $u->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // The same code cannot be replayed.
        $this->postJson('/api/v1/auth/reset-password', [
            'identifier' => 'recover.me@example.com', 'code' => $code,
            'password' => 'another-pass-123', 'password_confirmation' => 'another-pass-123',
        ])->assertStatus(422);
    }

    public function test_recovery_is_enumeration_resistant_for_unknown_and_unreachable_accounts(): void
    {
        $agent = $this->agent();
        // Verified email but we check the unreachable branch via an unverified one:
        $unverified = $this->konsumen($agent, ['email' => 'no.channel@example.com', 'email_verified_at' => null]);

        foreach (['nobody@nobody.invalid', 'no.channel@example.com'] as $identifier) {
            $this->postJson('/api/v1/auth/forgot-password', ['identifier' => $identifier])
                ->assertOk()->assertJsonPath('success', true)
                ->assertJsonPath('message', __('messages.auth.account_recovery_sent'));
        }

        $this->assertSame(0, IdentityOtpChallenge::query()->where('purpose', AccountRecoveryService::PURPOSE)->count());

        // Unknown identifier on reset: same error shape as a wrong code.
        $this->postJson('/api/v1/auth/reset-password', [
            'identifier' => 'nobody@nobody.invalid', 'code' => '123456',
            'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
        ])->assertStatus(422)->assertJsonPath('message', __('messages.auth.recovery_invalid'));

        // Phone identifier also resolves (login accepts phone): unverified-email account with no proven number
        // still gets the generic answer.
        $this->postJson('/api/v1/auth/forgot-password', ['identifier' => $unverified->phone])
            ->assertOk()->assertJsonPath('success', true);
    }

    public function test_recovery_over_whatsapp_uses_the_proven_number(): void
    {
        $agent = $this->agent();
        $u = $this->konsumen($agent, ['email_verified_at' => null, 'phone' => '081377700001']);
        \App\Models\IdentityOtpChallenge::create([
            'agent_id' => $agent->id, 'subject_user_id' => $u->id, 'created_by' => $u->id,
            'purpose' => 'whatsapp_number', 'code_hash' => str_repeat('0', 64),
            'sent_to_email' => 'wa:6281377700001',
            'attempts' => 1, 'max_attempts' => 5, 'expires_at' => now()->addMinutes(5),
            'verified_at' => now(), 'consumed_at' => now(),
        ]);
        $this->assertSame('6281377700001', \App\Services\WhatsApp\WhatsAppNumberProof::msisdnFor($u->fresh()));

        $this->postJson('/api/v1/auth/forgot-password', ['identifier' => $u->phone, 'channel' => 'whatsapp'])
            ->assertOk();

        $challenge = IdentityOtpChallenge::query()
            ->where('subject_user_id', $u->id)->where('purpose', AccountRecoveryService::PURPOSE)
            ->orderByDesc('id')->firstOrFail();
        $this->assertStringStartsWith('whatsapp:', (string) $challenge->sent_to_email);

        // The WA code actually resets the password: extract it from the faked gateway payload.
        $code = null;
        Http::assertSent(function ($r) use (&$code) {
            if (preg_match('/\b(\d{6})\b/', (string) $r['message'], $m)) {
                $code = $m[1];
            }

            return true;
        });
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $code);

        $this->postJson('/api/v1/auth/reset-password', [
            'identifier' => $u->phone, 'code' => $code,
            'password' => 'wa-new-pass-123', 'password_confirmation' => 'wa-new-pass-123',
        ])->assertOk();
        $this->assertTrue(Hash::check('wa-new-pass-123', $u->fresh()->password));
    }

    public function test_known_and_unknown_identifiers_get_byte_identical_answers(): void
    {
        $agent = $this->agent();
        $this->konsumen($agent, ['email' => 'known@example.com', 'email_verified_at' => now()]);

        $known = $this->postJson('/api/v1/auth/forgot-password', ['identifier' => 'known@example.com']);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['identifier' => 'unknown@example.com']);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->getContent(), $unknown->getContent());
        // A challenge exists only for the real account; nothing recoverable is created for the unknown one.
        $this->assertSame(1, IdentityOtpChallenge::query()->where('purpose', AccountRecoveryService::PURPOSE)->count());
    }

    public function test_transport_failure_keeps_the_generic_answer_and_kills_the_challenge(): void
    {
        $agent = $this->agent();
        $u = $this->konsumen($agent, ['email' => 'down@example.com', 'email_verified_at' => now()]);
        \Illuminate\Support\Facades\Log::spy();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp://user:secret@mail.internal exploded'));

        $res = $this->postJson('/api/v1/auth/forgot-password', ['identifier' => 'down@example.com']);
        $res->assertOk()->assertJsonPath('message', __('messages.auth.account_recovery_sent'));
        $this->assertStringNotContainsString('secret', $res->getContent());

        // Fail-closed: the undeliverable code is dead, so it can never be entered.
        $challenge = IdentityOtpChallenge::query()->where('subject_user_id', $u->id)->where('purpose', AccountRecoveryService::PURPOSE)->firstOrFail();
        $this->assertNotNull($challenge->invalidated_at);

        // Logged by class/category only — never the exception text (no DSN/credentials) and never the identifier.
        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning', fn ($m, $ctx = []) => str_contains(json_encode($ctx), 'secret') || str_contains(json_encode($ctx), 'down@example.com'));
    }

    public function test_unexpected_exception_is_contained_as_the_same_generic_answer(): void
    {
        $agent = $this->agent();
        $this->konsumen($agent, ['email' => 'boom@example.com', 'email_verified_at' => now()]);
        $this->mock(\App\Services\WhatsApp\WhatsAppOtpChannel::class, fn ($m) => $m->shouldReceive('prepare')->andThrow(new \LogicException('db detail leak')));

        $res = $this->postJson('/api/v1/auth/forgot-password', ['identifier' => 'boom@example.com', 'channel' => 'whatsapp']);
        $res->assertOk()->assertJsonPath('message', __('messages.auth.account_recovery_sent'));
        $this->assertStringNotContainsString('leak', $res->getContent());
    }

    public function test_recovery_request_is_throttled_per_identifier_without_revealing_existence(): void
    {
        foreach (['ghost@example.com', 'ghost@example.com', 'ghost@example.com'] as $_) {
            $this->postJson('/api/v1/auth/forgot-password', ['identifier' => 'ghost@example.com'])->assertOk();
        }
        // 4th attempt on the SAME identifier is limited — identically for an account that does not exist.
        $this->postJson('/api/v1/auth/forgot-password', ['identifier' => 'GHOST@example.com '])->assertStatus(429);
        // A different identifier from the same IP is unaffected.
        $this->postJson('/api/v1/auth/forgot-password', ['identifier' => 'other@example.com'])->assertOk();
    }

    public function test_unknown_identifier_is_padded_to_the_response_floor(): void
    {
        config(['auth.recovery_floor_ms' => 200]);
        $t = hrtime(true);
        $this->postJson('/api/v1/auth/forgot-password', ['identifier' => 'nobody-here@example.com'])->assertOk();
        $this->assertGreaterThanOrEqual(190, (hrtime(true) - $t) / 1e6);
    }

    public function test_recovery_codes_are_single_use_and_a_new_request_revokes_the_previous_code(): void
    {
        $agent = $this->agent();
        $u = $this->konsumen($agent, ['email' => 'twice@example.com', 'email_verified_at' => now()]);

        $codes = [];
        foreach ([1, 2] as $_) {
            $this->postJson('/api/v1/auth/forgot-password', ['identifier' => 'twice@example.com'])->assertOk();
        }
        Mail::assertSent(OtpChallengeMail::class, function (OtpChallengeMail $m) use (&$codes) {
            $codes[] = $m->code;

            return true;
        });
        $this->assertCount(2, $codes);

        // The first code was superseded by the second request.
        $this->postJson('/api/v1/auth/reset-password', ['identifier' => 'twice@example.com', 'code' => $codes[0],
            'password' => 'brand-new-pass-1', 'password_confirmation' => 'brand-new-pass-1'])->assertStatus(422);
        $this->postJson('/api/v1/auth/reset-password', ['identifier' => 'twice@example.com', 'code' => $codes[1],
            'password' => 'brand-new-pass-1', 'password_confirmation' => 'brand-new-pass-1'])->assertOk();
        $this->postJson('/api/v1/auth/reset-password', ['identifier' => 'twice@example.com', 'code' => $codes[1],
            'password' => 'brand-new-pass-2', 'password_confirmation' => 'brand-new-pass-2'])->assertStatus(422);
        $this->assertTrue(Hash::check('brand-new-pass-1', $u->fresh()->password));
    }

    public function test_email_verification_state_endpoint_reports_masked_status_for_the_holder_only(): void
    {
        $agent = $this->agent();
        $u = $this->konsumen($agent, ['email' => 'holder@example.com', 'email_verified_at' => null]);

        $this->getJson('/api/v1/profile/email/verification')->assertUnauthorized();

        $res = $this->actingAs($u)->getJson('/api/v1/profile/email/verification')->assertOk();
        $res->assertJsonPath('data.verified', false)->assertJsonPath('data.pending', false);
        $this->assertStringNotContainsString('holder@example.com', $res->getContent());
    }
}
