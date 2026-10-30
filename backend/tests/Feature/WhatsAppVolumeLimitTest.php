<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppDelivery;
use App\Services\WhatsApp\WhatsAppSettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Volume protection for the one shared GOWA device. HTTP is faked, never real. */
class WhatsAppVolumeLimitTest extends TestCase
{
    use \Tests\Concerns\AssumesPairedAgentDevices;
    use RefreshDatabase;

    private const BASE = 'http://gowa.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assumeEveryAgentHasAPairedDevice();
        $this->seed(RoleSeeder::class);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([self::BASE.'/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'WA-1']], 200)]);
    }

    private function configure(array $extra = []): void
    {
        app(WhatsAppSettings::class)->update($extra + [
            'enabled' => true, 'base_url' => self::BASE, 'username' => 'u', 'password' => 'p', 'max_attempts' => 3, 'backoff_seconds' => 10,
        ], User::factory()->superAdmin()->create());
    }

    /** @var array<string,User> */
    private array $holders = [];

    private ?User $agent = null;

    /** Every message belongs to an Agent (its device is what sends it). */
    private function owningAgent(): User
    {
        return $this->agent ??= User::factory()->agen()->create();
    }

    /** An account that PROVED this number (what a completed number-verification leaves behind). */
    private function holder(string $msisdn, bool $proven = true): User
    {
        if (isset($this->holders[$msisdn])) {
            return $this->holders[$msisdn];
        }

        $u = User::factory()->konsumen()->create(['phone' => '0'.substr($msisdn, 2)]);
        if ($proven) {
            \App\Models\IdentityOtpChallenge::create([
                'agent_id' => $u->agent_id ?? $u->id, 'subject_user_id' => $u->id, 'created_by' => $u->id, 'purpose' => 'whatsapp_number',
                'code_hash' => str_repeat('0', 64), 'sent_to_email' => 'wa:'.$msisdn, 'attempts' => 1, 'max_attempts' => 5,
                'expires_at' => now()->addMinutes(5), 'verified_at' => now(), 'consumed_at' => now(),
            ]);
        }

        return $this->holders[$msisdn] = $u;
    }

    private function message(string $phone, string $purpose = WhatsAppMessage::PURPOSE_TRANSACTION, ?User $holder = null): WhatsAppMessage
    {
        $m = app(WhatsAppDelivery::class)->enqueue([
            'idempotency_key' => $purpose.':'.bin2hex(random_bytes(6)), 'purpose' => $purpose, 'event' => $purpose === 'otp' ? 'otp' : 'order_shipped',
            'recipient_phone' => $phone, 'body' => 'Halo', 'agent_id' => $this->owningAgent()->id,
            ...($purpose === WhatsAppMessage::PURPOSE_TRANSACTION ? ['recipient_user_id' => ($holder ?? $this->holder($phone))->id] : []),
        ], dispatch: false);

        return app(WhatsAppDelivery::class)->deliver($m->id);
    }

    private function sentCount(): int
    {
        return count(Http::recorded());
    }

    public function test_defaults_are_conservative_and_bounded(): void
    {
        $this->assertSame(['per_recipient_per_hour' => 6, 'global_per_minute' => 20], app(WhatsAppSettings::class)->rateLimits());

        $this->configure(['limits' => ['per_recipient_per_hour' => 3, 'global_per_minute' => 5]]);
        $this->assertSame(['per_recipient_per_hour' => 3, 'global_per_minute' => 5], app(WhatsAppSettings::class)->reload()->snapshot()['limits']);

        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin)->putJson('/api/v1/admin/integrations/whatsapp', ['limits' => ['per_recipient_per_hour' => 0]])->assertStatus(422);
        $this->actingAs($admin)->putJson('/api/v1/admin/integrations/whatsapp', ['limits' => ['global_per_minute' => 500]])->assertStatus(422);
        $this->actingAs(User::factory()->konsumen()->create())->putJson('/api/v1/admin/integrations/whatsapp', ['limits' => ['global_per_minute' => 5]])->assertForbidden();
    }

    public function test_a_recipient_over_the_hourly_cap_is_deferred_without_calling_the_gateway_and_others_are_unaffected(): void
    {
        $this->configure(['limits' => ['per_recipient_per_hour' => 2, 'global_per_minute' => 100]]);

        $this->assertSame('sent', $this->message('6281300000001')->status);
        $this->assertSame('sent', $this->message('6281300000001')->status);
        $this->assertSame(2, $this->sentCount());

        Queue::fake();
        $third = $this->message('6281300000001');
        $this->assertSame(WhatsAppMessage::QUEUED, $third->status);
        $this->assertSame('rate_limited:recipient', $third->error_class);
        $this->assertSame(0, $third->attempts, 'a throttled message did not consume an attempt');
        $this->assertNotNull($third->next_attempt_at);
        $this->assertSame(2, $this->sentCount(), 'the gateway was not called');
        Queue::assertPushed(\App\Jobs\SendWhatsAppMessageJob::class);

        // Another number is not affected.
        $this->assertSame('sent', $this->message('6281300000002')->status);
    }

    public function test_the_gateway_wide_cap_defers_everyone(): void
    {
        $this->configure(['limits' => ['per_recipient_per_hour' => 10, 'global_per_minute' => 2]]);
        $this->message('6281300000011');
        $this->message('6281300000012');

        Queue::fake();
        $blocked = $this->message('6281300000013');
        $this->assertSame(WhatsAppMessage::QUEUED, $blocked->status);
        $this->assertSame('rate_limited:global', $blocked->error_class);
        $this->assertSame(2, $this->sentCount());
    }

    public function test_otp_is_exempt_from_the_recipient_cap_but_never_waits_behind_the_global_one(): void
    {
        $this->configure(['limits' => ['per_recipient_per_hour' => 1, 'global_per_minute' => 3]]);
        $this->assertSame('sent', $this->message('6281300000021')->status);
        // Recipient cap (1) is reached, yet an OTP to the same number still goes out.
        $this->assertSame('sent', $this->message('6281300000021', WhatsAppMessage::PURPOSE_OTP)->status);
        $this->assertSame('sent', $this->message('6281300000022')->status);

        // Global cap reached: an OTP cannot wait (it is time-boxed) -> clean skip, nothing transmitted.
        $otp = $this->message('6281300000023', WhatsAppMessage::PURPOSE_OTP);
        $this->assertSame(WhatsAppMessage::SKIPPED, $otp->status);
        $this->assertSame('rate_limited_global', $otp->skip_reason);
        $this->assertSame(3, $this->sentCount());
    }

    public function test_dashboard_test_messages_are_exempt(): void
    {
        $this->configure(['limits' => ['per_recipient_per_hour' => 1, 'global_per_minute' => 1]]);
        $this->message('6281300000031');
        $this->assertSame('sent', $this->message('6281300000031', WhatsAppMessage::PURPOSE_TEST)->status);
    }

    public function test_throttling_is_audited_once_per_message_without_the_number(): void
    {
        $this->configure(['limits' => ['per_recipient_per_hour' => 1, 'global_per_minute' => 100]]);
        $this->message('6281300000041');
        Queue::fake();
        $m = $this->message('6281300000041');
        // Deliver the same deferred message again (a worker tick): still throttled, not re-audited.
        app(WhatsAppDelivery::class)->deliver($m->id);

        $logs = ActivityLog::query()->where('event', 'whatsapp.message.rate_limited')->get();
        $this->assertCount(1, $logs);
        $this->assertStringNotContainsString('6281300000041', json_encode($logs->first()->toArray()));
    }

    public function test_a_notification_throttled_past_its_useful_life_fails_instead_of_arriving_late(): void
    {
        $this->configure(['limits' => ['per_recipient_per_hour' => 1, 'global_per_minute' => 100]]);
        $this->message('6281300000051');
        Queue::fake();
        $m = $this->message('6281300000051');
        WhatsAppMessage::query()->whereKey($m->id)->update(['queued_at' => now()->subHours(7)]);

        $final = app(WhatsAppDelivery::class)->deliver($m->id);
        $this->assertSame(WhatsAppMessage::FAILED, $final->status);
        $this->assertSame('rate_limited_expired', $final->error_class);
        $this->assertSame(1, $this->sentCount());
    }
    public function test_an_otp_hitting_a_disconnected_gateway_fails_at_once_and_is_never_requeued(): void
    {
        $this->configure();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([self::BASE.'/send/message' => Http::response(['code' => 'ERROR', 'message' => 'not connected'], 503)]);
        Queue::fake();

        $otp = $this->message('6281300000071', WhatsAppMessage::PURPOSE_OTP);

        $this->assertSame(WhatsAppMessage::FAILED, $otp->status);
        $this->assertStringStartsWith('otp_not_delivered', (string) $otp->error_class);
        $this->assertNull($otp->secret_body);
        Queue::assertNothingPushed();
    }

    public function test_the_proof_is_rechecked_at_send_time_so_a_stale_or_retried_message_never_reaches_an_unproven_number(): void
    {
        $this->configure();
        $queued = app(WhatsAppDelivery::class)->enqueue([
            'idempotency_key' => 'txn:late', 'purpose' => 'transaction', 'event' => 'order_shipped',
            'recipient_phone' => '6281300000061', 'recipient_user_id' => $this->holder('6281300000061')->id, 'body' => 'Halo',
        ], dispatch: false);

        // The account changes its number AFTER the message was queued (this voids the proof).
        $this->holder('6281300000061')->forceFill(['phone' => '081300000099'])->save();

        $final = app(WhatsAppDelivery::class)->deliver($queued->id);
        $this->assertSame(WhatsAppMessage::SKIPPED, $final->status);
        $this->assertSame('number_not_verified', $final->skip_reason);
        $this->assertSame(0, $this->sentCount(), 'nothing was transmitted');

        // A message that never had a holder at all is refused the same way.
        $orphan = app(WhatsAppDelivery::class)->enqueue([
            'idempotency_key' => 'txn:orphan', 'purpose' => 'transaction', 'event' => 'order_shipped', 'recipient_phone' => '6281300000062', 'body' => 'Halo',
        ], dispatch: false);
        $this->assertSame('number_not_verified', app(WhatsAppDelivery::class)->deliver($orphan->id)->skip_reason);
        $this->assertSame(0, $this->sentCount());
    }
}