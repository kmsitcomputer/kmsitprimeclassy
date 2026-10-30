<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ConsentEvent;
use App\Models\CrmCustomer;
use App\Models\IdentityOtpChallenge;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppNumberProof;
use App\Services\WhatsApp\WhatsAppSettings;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\BuildsCrmFixtures;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * Self-service proof of control of a WhatsApp number. It proves the CHANNEL only: it is never consent, never links a CRM
 * customer and never reopens the (still blocked) WhatsApp-for-consent path. HTTP is faked.
 */
class WhatsAppNumberVerificationTest extends TestCase
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

    private function ok(): array
    {
        return [self::BASE.'/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'OTP-1']], 200)];
    }

    private function configure(array $otp = []): void
    {
        app(WhatsAppSettings::class)->update([
            'enabled' => true, 'base_url' => self::BASE, 'username' => 'u', 'password' => 'p',
            'otp' => $otp + ['enabled' => true, 'ttl_minutes' => 5, 'max_attempts' => 3, 'cooldown_seconds' => 0, 'limit' => 3, 'limit_window_minutes' => 10],
        ], User::factory()->superAdmin()->create());
    }

    private function user(string $phone = '081388800001'): User
    {
        $b = $this->branch();
        $b['konsumen']->forceFill(['phone' => $phone])->save();
        RateLimiter::clear('wa-number-verify:ip:127.0.0.1');

        return $b['konsumen'];
    }

    private function request(User $u, ?string $phone = null)
    {
        return $this->actingAs($u)->postJson('/api/v1/profile/whatsapp/verification', $phone === null ? [] : ['phone' => $phone]);
    }

    private function confirm(User $u, int $id, string $code)
    {
        return $this->actingAs($u)->postJson("/api/v1/profile/whatsapp/verification/{$id}/confirm", ['code' => $code]);
    }

    /** The code the gateway was asked to send (test double of the phone). */
    private function sentCode(): string
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

    /* ----------------------------------------------------------------- happy path */

    public function test_the_correct_code_proves_the_number_and_nothing_else(): void
    {
        $this->configure();
        $this->fake($this->ok());
        $u = $this->user();

        $this->assertNull(WhatsAppNumberProof::msisdnFor($u), 'a phone on file is not a proof');
        $this->actingAs($u)->getJson('/api/v1/profile/whatsapp')->assertOk()
            ->assertJsonPath('data.verified', false)->assertJsonPath('data.channel_available', true)->assertJsonPath('data.has_phone', true);

        $id = $this->request($u)->assertCreated()->assertJsonMissingPath('data.code')->json('data.challenge_id');
        $code = $this->sentCode();

        // Sending succeeded, but that is NOT verification.
        $this->assertNull(WhatsAppNumberProof::msisdnFor($u));
        $this->assertSame('sent', WhatsAppMessage::query()->firstOrFail()->status);

        $this->confirm($u, $id, $code)->assertOk()->assertJsonPath('data.verified', true)->assertJsonPath('data.pending', false);
        $this->assertSame('6281388800001', WhatsAppNumberProof::msisdnFor($u->refresh()));

        $log = ActivityLog::query()->where('event', 'whatsapp.number.verified')->firstOrFail();
        $this->assertSame($u->id, $log->causer_id);
        $this->assertStringNotContainsString($code, json_encode($log->properties));
        $this->assertStringNotContainsString('6281388800001', json_encode($log->properties), 'only a masked number reaches the audit');

        // Asking again for the SAME proven number sends nothing.
        $this->request($u)->assertOk()->assertJsonPath('data.verified', true);
        Http::assertSentCount(1);
    }

    public function test_the_code_never_appears_in_any_stored_column_or_api_response(): void
    {
        $this->configure();
        $this->fake($this->ok());
        $u = $this->user();

        $res = $this->request($u)->assertCreated();
        $code = $this->sentCode();
        $this->assertStringNotContainsString($code, $res->getContent());
        foreach (['whatsapp_messages', 'identity_otp_challenges', 'activity_logs'] as $table) {
            $this->assertStringNotContainsString('"'.$code.'"', json_encode(DB::table($table)->get()), "$table must not hold the OTP");
        }
        $this->assertNull(WhatsAppMessage::query()->firstOrFail()->secret_body);
        $this->assertStringContainsString('••••••', (string) WhatsAppMessage::query()->firstOrFail()->body);
    }

    /* ----------------------------------------------------------------- failure paths never verify */

    /**
     * Laravel logs exceptions with their stack trace, and with zend.exception_ignore_args=Off (every dev setup) the trace records
     * the ARGUMENTS of each frame — which would write the plaintext OTP to laravel.log. The code-carrying parameters are marked
     * #[SensitiveParameter]; this proves no trace of a failing verification or delivery contains the code.
     */
    public function test_the_code_never_appears_in_an_exception_stack_trace(): void
    {
        ini_set('zend.exception_ignore_args', '0');
        $this->configure(['max_attempts' => 3]);
        $this->fake($this->ok());
        $u = $this->branch()['konsumen'];
        $u->forceFill(['phone' => '081377000123'])->save();

        RateLimiter::clear('wa-number-verify:ip:127.0.0.1');
        $id = $this->request($u)->assertCreated()->json('data.challenge_id');
        $real = $this->sentCode();

        $traces = [];
        foreach (['654321', $real] as $attempt) {
            if ($attempt === $real) {
                // Make the REAL code fail too: expire the challenge, then submit it.
                IdentityOtpChallenge::query()->whereKey($id)->update(['expires_at' => now()->subMinute()]);
            }
            try {
                app(\App\Services\WhatsApp\WhatsAppNumberVerification::class)->confirm($u->fresh(), $id, $attempt);
            } catch (\Throwable $e) {
                $traces[$attempt] = $e->getTraceAsString();
            }
        }
        $this->assertCount(2, $traces);
        foreach ($traces as $attempt => $trace) {
            $this->assertStringNotContainsString($attempt, $trace, 'the submitted code leaked into a stack trace');
        }

        // Delivery failure path: the code is an argument of WhatsAppOtpChannel::deliver().
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([self::BASE.'/send/message' => Http::response(['message' => 'boom'], 500)]);
        RateLimiter::clear('wa-number-verify:ip:127.0.0.1');
        IdentityOtpChallenge::query()->where('subject_user_id', $u->id)->update(['created_at' => now()->subHour()]);
        try {
            app(\App\Services\Consent\OtpChallengeService::class)->issueNumberVerification($u->fresh(), '6281377000123');
            $this->fail('delivery should have failed');
        } catch (\App\Exceptions\ApiException $e) {
            $this->assertSame(502, $e->status());
            // The generated code is not observable here; assert no 6-digit argument value is recorded in any frame.
            $this->assertDoesNotMatchRegularExpression("/deliver\\(Object\\(App\\\\Models\\\\IdentityOtpChallenge\\), Object\\(App\\\\Models\\\\User\\), '\\d+', '\\d{6}'/", $e->getTraceAsString());
        }
    }

    public function test_wrong_expired_replayed_and_over_attempted_codes_never_verify(): void
    {
        $this->configure(['max_attempts' => 2]);
        $this->fake($this->ok());
        $u = $this->user();

        $id = $this->request($u)->assertCreated()->json('data.challenge_id');
        $code = $this->sentCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->confirm($u, $id, $wrong)->assertStatus(422);
        $this->confirm($u, $id, $wrong)->assertStatus(422);
        $this->confirm($u, $id, $code)->assertStatus(429); // locked even for the right code
        $this->assertNull(WhatsAppNumberProof::msisdnFor($u));

        // Expired.
        $id2 = $this->request($u)->assertCreated()->json('data.challenge_id');
        $code2 = $this->sentCode();
        IdentityOtpChallenge::whereKey($id2)->update(['expires_at' => now()->subMinute()]);
        $this->confirm($u, $id2, $code2)->assertStatus(422);
        $this->assertNull(WhatsAppNumberProof::msisdnFor($u));

        // Replay after a successful confirmation.
        $id3 = $this->request($u)->assertCreated()->json('data.challenge_id');
        $code3 = $this->sentCode();
        $this->confirm($u, $id3, $code3)->assertOk();
        $this->confirm($u, $id3, $code3)->assertStatus(422);
        $this->assertNotNull(WhatsAppNumberProof::msisdnFor($u->refresh()));
    }

    public function test_an_undeliverable_code_leaves_the_number_unverified_and_the_challenge_dead(): void
    {
        $this->configure();
        $this->fake([self::BASE.'/send/message' => Http::response(['message' => 'internal error'], 500)]);
        $u = $this->user();

        $this->request($u)->assertStatus(502)->assertJsonPath('errors.code', 'whatsapp_otp_not_delivered');

        $this->assertNotNull(IdentityOtpChallenge::query()->firstOrFail()->invalidated_at);
        $this->assertNull(WhatsAppNumberProof::msisdnFor($u));
    }

    public function test_the_feature_is_unavailable_until_the_gateway_and_otp_option_are_on(): void
    {
        $this->fake($this->ok());
        $u = $this->user();

        $this->actingAs($u)->getJson('/api/v1/profile/whatsapp')->assertOk()->assertJsonPath('data.channel_available', false);
        $this->request($u)->assertStatus(409)->assertJsonPath('errors.code', 'whatsapp_otp_unavailable');
        $this->configure(['enabled' => false]);
        $this->request($u)->assertStatus(409);

        Http::assertNothingSent();
        $this->assertSame(0, IdentityOtpChallenge::query()->count());
    }

    /* ----------------------------------------------------------------- number change */

    public function test_changing_the_phone_voids_the_proof_and_reverting_does_not_revive_it(): void
    {
        $this->configure();
        $this->fake($this->ok());
        $u = $this->user('081388800001');

        $id = $this->request($u)->assertCreated()->json('data.challenge_id');
        $this->confirm($u, $id, $this->sentCode())->assertOk();
        $this->assertNotNull(WhatsAppNumberProof::msisdnFor($u->refresh()));

        // The account holder edits the phone through the normal profile endpoint.
        $this->actingAs($u)->patchJson('/api/v1/profile', ['phone' => '081388800002'])->assertOk();
        $this->assertNull(WhatsAppNumberProof::msisdnFor($u->refresh()), 'new number, no proof');
        $this->assertSame(0, IdentityOtpChallenge::query()->where('purpose', 'whatsapp_number')->whereNull('invalidated_at')->count());

        // Back to the old number: still no proof; a fresh OTP is required.
        $this->actingAs($u)->patchJson('/api/v1/profile', ['phone' => '081388800001'])->assertOk();
        $this->assertNull(WhatsAppNumberProof::msisdnFor($u->refresh()));
    }

    public function test_proposing_a_new_number_changes_the_phone_only_after_the_correct_code(): void
    {
        $this->configure();
        $this->fake($this->ok());
        $u = $this->user('081388800001');

        $id = $this->request($u, '+62 813-8880-0099')->assertCreated()->assertJsonPath('data.target', '62••••099')->json('data.challenge_id');
        $code = $this->sentCode();
        Http::assertSent(fn ($r) => $r['phone'] === '6281388800099@s.whatsapp.net');
        $this->assertSame('081388800001', $u->refresh()->phone, 'the account phone is untouched until the code is confirmed');

        $this->confirm($u, $id, $code === '000000' ? '111111' : '000000')->assertStatus(422);
        $this->assertSame('081388800001', $u->refresh()->phone);

        $this->confirm($u, $id, $code)->assertOk()->assertJsonPath('data.verified', true);
        $this->assertSame('081388800099', $u->refresh()->phone);
        $this->assertSame('6281388800099', WhatsAppNumberProof::msisdnFor($u));
    }

    public function test_a_number_used_by_another_account_fails_without_revealing_whose(): void
    {
        $this->configure();
        $this->fake($this->ok());
        $owner = $this->user('081388800001');
        $other = $this->user('081388800002');

        $id = $this->request($other, '081388800001')->assertCreated()->json('data.challenge_id'); // identical response to any other number
        $res = $this->confirm($other, $id, $this->sentCode())->assertStatus(422);

        $this->assertStringNotContainsString((string) $owner->id, $res->getContent());
        $this->assertSame('081388800002', $other->refresh()->phone);
        $this->assertNull(WhatsAppNumberProof::msisdnFor($other));
    }

    /* ----------------------------------------------------------------- limits + isolation */

    public function test_limits_per_account_per_number_and_per_source(): void
    {
        $this->configure(['limit' => 2]);
        $this->fake($this->ok());
        $a = $this->user('081388800001');
        $b = $this->user('081388800002');
        $c = $this->user('081388800003');

        // Per ACCOUNT.
        $this->request($a)->assertCreated();
        $this->request($a)->assertCreated();
        $this->request($a)->assertStatus(429);

        // Per NUMBER across accounts: two different accounts already pointed at one number; the third is refused.
        $this->request($b, '081388809999')->assertCreated();
        $this->request($c, '081388809999')->assertCreated();
        $d = $this->user('081388800004');
        $this->request($d, '081388809999')->assertStatus(429);

        // Per SOURCE (ip): after 12 requests from one address everything is refused (user() resets the limiter, so build first).
        $e = $this->user('081388800005');
        RateLimiter::clear('wa-number-verify:ip:127.0.0.1');
        for ($i = 0; $i < 12; $i++) {
            RateLimiter::hit('wa-number-verify:ip:127.0.0.1', 3600);
        }
        $this->request($e)->assertStatus(429);
        Http::assertSentCount(4);
    }

    public function test_cooldown_between_codes(): void
    {
        $this->configure(['cooldown_seconds' => 60]);
        $this->fake($this->ok());
        $u = $this->user();

        $this->request($u)->assertCreated();
        $this->request($u)->assertStatus(429);
        Http::assertSentCount(1);
    }

    public function test_a_user_can_only_confirm_their_own_challenge_and_cannot_probe_others(): void
    {
        $this->configure();
        $this->fake($this->ok());
        $a = $this->user('081388800001');
        $b = $this->user('081388800002');

        $id = $this->request($a)->assertCreated()->json('data.challenge_id');
        $code = $this->sentCode();

        // Another account presenting A's challenge id + code: same answer as "no such challenge".
        $stranger = $this->confirm($b, $id, $code)->assertStatus(422);
        $missing = $this->confirm($b, 99999999, $code)->assertStatus(422);
        $this->assertSame($stranger->json('message'), $missing->json('message'));
        $this->assertNull(WhatsAppNumberProof::msisdnFor($b));

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/profile/whatsapp')->assertUnauthorized();
        $this->postJson('/api/v1/profile/whatsapp/verification')->assertUnauthorized();
    }

    public function test_input_validation(): void
    {
        $this->configure();
        $this->fake($this->ok());
        $u = $this->user();

        $this->request($u, 'abc')->assertStatus(422);
        $this->request($u, '123')->assertStatus(422);
        $id = $this->request($u)->assertCreated()->json('data.challenge_id');
        $this->actingAs($u)->postJson("/api/v1/profile/whatsapp/verification/{$id}/confirm", ['code' => '12ab'])->assertStatus(422);
        $this->actingAs($u)->postJson("/api/v1/profile/whatsapp/verification/{$id}/confirm", [])->assertStatus(422);
        $noPhone = $this->branch()['konsumen'];
        $noPhone->forceFill(['phone' => null])->save();
        RateLimiter::clear('wa-number-verify:ip:127.0.0.1');
        $this->request($noPhone)->assertStatus(422);
    }

    /* ----------------------------------------------------------------- boundaries with consent / CRM */

    public function test_a_verified_number_does_not_link_a_crm_customer_or_change_ownership(): void
    {
        $this->configure();
        $this->fake($this->ok());
        $b = $this->branch();
        $b['konsumen']->forceFill(['phone' => '081388800001'])->save();
        // The Sales' CRM customer carries the SAME number as the konsumen's WhatsApp.
        $customer = $this->crmCustomer($b['sales'], ['phone' => '081388800001']);
        $order = $this->assistedOrder($b['sales'], $customer, $this->stockedProduct($b['agen']));
        $beforeOrder = $order->only(['konsumen_id', 'crm_customer_id', 'sales_id', 'placed_by_user_id', 'total_amount']);

        RateLimiter::clear('wa-number-verify:ip:127.0.0.1');
        $id = $this->request($b['konsumen'])->assertCreated()->json('data.challenge_id');
        $this->confirm($b['konsumen'], $id, $this->sentCode())->assertOk()->assertJsonPath('data.verified', true);

        // No automatic link, no ownership/attribution change, no consent.
        $this->assertNull(CrmCustomer::withoutGlobalScopes()->find($customer)->linked_konsumen_id);
        $this->assertSame($beforeOrder, \App\Models\Order::withoutGlobalScopes()->find($order->id)->only(array_keys($beforeOrder)));
        $this->assertSame(0, ConsentEvent::withoutGlobalScopes()->count());

        // A CRM customer that is NOT linked to an account never gets a WhatsApp consent path, whatever its own number says.
        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$customer}/consent-otp", ['purpose' => 'assisted_return', 'channel' => 'whatsapp'])
            ->assertStatus(409);
    }

    public function test_a_verified_number_can_receive_a_new_consent_challenge_that_the_verification_code_cannot_satisfy(): void
    {
        $this->configure();
        $this->fake($this->ok());
        $b = $this->branch();
        $b['konsumen']->forceFill(['phone' => '081388800002'])->save();

        // Unverified number: refused.
        $this->actingAs($b['sales'])->postJson('/api/v1/consent/otp', ['subject_user_id' => $b['konsumen']->id, 'purpose' => 'assisted_return', 'channel' => 'whatsapp'])
            ->assertStatus(409)->assertJsonPath('errors.code', 'whatsapp_number_not_verified');

        RateLimiter::clear('wa-number-verify:ip:127.0.0.1');
        $numberChallenge = $this->request($b['konsumen'])->assertCreated()->json('data.challenge_id');
        $numberCode = $this->sentCode();
        $this->confirm($b['konsumen'], $numberChallenge, $numberCode)->assertOk();

        // Verified: a NEW challenge, bound to the consent purpose and the actor, is delivered.
        $consent = $this->actingAs($b['sales'])->postJson('/api/v1/consent/otp', ['subject_user_id' => $b['konsumen']->id, 'purpose' => 'assisted_return', 'channel' => 'whatsapp'])
            ->assertCreated()->json('data.challenge_id');
        $this->assertNotSame($numberChallenge, $consent);
        $row = IdentityOtpChallenge::findOrFail($consent);
        $this->assertSame(['assisted_return', $b['konsumen']->id, $b['sales']->id], [$row->purpose, $row->subject_user_id, $row->created_by]);
        $this->assertStringStartsWith('whatsapp:', $row->sent_to_email);

        // The number-verification code never satisfies the consent challenge, and the number challenge is not usable by the Sales.
        $this->actingAs($b['sales'])->postJson("/api/v1/consent/otp/{$consent}/verify", ['code' => $numberCode])->assertStatus(422);
        $this->actingAs($b['sales'])->postJson("/api/v1/consent/otp/{$numberChallenge}/verify", ['code' => $numberCode])->assertStatus(403);
        $this->assertSame(0, ConsentEvent::withoutGlobalScopes()->count());

        // The correct, freshly delivered consent code does verify.
        $this->actingAs($b['sales'])->postJson("/api/v1/consent/otp/{$consent}/verify", ['code' => $this->sentCode()])->assertOk();
    }

    public function test_changing_the_number_cancels_whatsapp_eligibility_and_any_code_in_flight(): void
    {
        $this->configure();
        $this->fake($this->ok());
        $b = $this->branch();
        $b['konsumen']->forceFill(['phone' => '081388800003'])->save();
        RateLimiter::clear('wa-number-verify:ip:127.0.0.1');
        $id = $this->request($b['konsumen'])->assertCreated()->json('data.challenge_id');
        $this->confirm($b['konsumen'], $id, $this->sentCode())->assertOk();

        $consent = $this->actingAs($b['sales'])->postJson('/api/v1/consent/otp', ['subject_user_id' => $b['konsumen']->id, 'purpose' => 'assisted_return', 'channel' => 'whatsapp'])
            ->assertCreated()->json('data.challenge_id');
        $code = $this->sentCode();

        $b['konsumen']->forceFill(['phone' => '081388800099'])->save(); // a different number

        $this->assertNotNull(IdentityOtpChallenge::findOrFail($consent)->invalidated_at, 'the in-flight code is void');
        $this->actingAs($b['sales'])->postJson("/api/v1/consent/otp/{$consent}/verify", ['code' => $code])->assertStatus(422);
        $this->actingAs($b['sales'])->postJson('/api/v1/consent/otp', ['subject_user_id' => $b['konsumen']->id, 'purpose' => 'assisted_return', 'channel' => 'whatsapp'])
            ->assertStatus(409)->assertJsonPath('errors.code', 'whatsapp_number_not_verified');
        // Email stays available as the safe fallback.
        $this->actingAs($b['sales'])->postJson('/api/v1/consent/otp', ['subject_user_id' => $b['konsumen']->id, 'purpose' => 'assisted_return'])->assertCreated();
    }

    public function test_a_verified_account_number_can_receive_a_crm_link_challenge_bound_to_that_customer(): void
    {
        $this->configure();
        $this->fake($this->ok());
        $b = $this->branch();
        $b['konsumen']->forceFill(['phone' => '081388800004'])->save();
        $customer = $this->crmCustomer($b['sales'], ['phone' => '081300000000']);
        RateLimiter::clear('wa-number-verify:ip:127.0.0.1');
        $id = $this->request($b['konsumen'])->assertCreated()->json('data.challenge_id');
        $this->confirm($b['konsumen'], $id, $this->sentCode())->assertOk();

        $link = $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$customer}/link-otp", ['konsumen_id' => $b['konsumen']->id, 'channel' => 'whatsapp'])
            ->assertCreated()->json('data.challenge_id');
        $row = IdentityOtpChallenge::findOrFail($link);
        $this->assertSame(['crm_link', $customer, $b['konsumen']->id], [$row->purpose, $row->crm_customer_id, $row->subject_user_id]);
        // Issuing the challenge links nothing by itself.
        $this->assertNull(CrmCustomer::withoutGlobalScopes()->find($customer)->linked_konsumen_id);
    }
}
