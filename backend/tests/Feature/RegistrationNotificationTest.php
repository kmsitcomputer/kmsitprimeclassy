<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessageJob;
use App\Mail\AccountRegisteredMail;
use App\Models\IdentityOtpChallenge;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\Auth\RegistrationNotifier;
use App\Services\WhatsApp\WhatsAppDelivery;
use App\Services\WhatsApp\WhatsAppNumberProof;
use App\Services\WhatsApp\WhatsAppSettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * R-02 scoped tests (HA-08 / CL-04 / CL-05): registration notifications for self-registration and Admin-created accounts
 * (the Google path is covered in GoogleAuthTest). Email + RESTRICTED WhatsApp to an UNVERIFIED number; no password/secret;
 * no auto-verification; duplicate suppression; independent channel failure. Transports are faked — nothing is really sent.
 */
class RegistrationNotificationTest extends TestCase
{
    use \Tests\Concerns\AssumesPairedAgentDevices;
    use RefreshDatabase;

    private const BASE = 'http://gowa.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assumeEveryAgentHasAPairedDevice();
        $this->seed(RoleSeeder::class);
        Mail::fake();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([self::BASE.'/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'WA-1']], 200)]);
        app(WhatsAppSettings::class)->update([
            'enabled' => true, 'base_url' => self::BASE, 'username' => 'u', 'password' => 'p', 'device_id' => 'dev-1',
            'max_attempts' => 3, 'backoff_seconds' => 10,
        ], User::factory()->superAdmin()->create());
    }

    private function register(array $overrides = []): User
    {
        $payload = $overrides + [
            'name' => 'Konsumen Baru', 'email' => 'baru@example.com', 'phone' => '081312345678',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ];
        $this->postJson('/api/v1/auth/register', $payload)->assertCreated();

        return User::query()->where('name', $payload['name'])->latest('id')->firstOrFail();
    }

    private function registrationRow(User $user): ?WhatsAppMessage
    {
        return WhatsAppMessage::query()->where('event', 'account_registered')->where('recipient_user_id', $user->id)->first();
    }

    public function test_self_registration_sends_email_and_a_restricted_whatsapp_to_the_unverified_number(): void
    {
        Queue::fake();
        $agen = User::factory()->agen()->create();

        $user = $this->register();

        $this->assertDatabaseHas('activity_logs', ['event' => 'auth.registered', 'causer_id' => $user->id]);
        Mail::assertQueued(AccountRegisteredMail::class, fn (AccountRegisteredMail $m) => $m->hasTo('baru@example.com'));

        $row = $this->registrationRow($user);
        $this->assertNotNull($row);
        $this->assertSame(['registrant', 'restricted', 'queued', '6281312345678', $agen->id], [
            $row->recipient_role, $row->template_class, $row->status, $row->recipient_phone, (int) $row->agent_id,
        ]);
        Queue::assertPushed(SendWhatsAppMessageJob::class, 1);

        // Not a verification: the number stays unproven, and no OTP challenge exists.
        $this->assertNull(WhatsAppNumberProof::msisdnFor($user));
        $this->assertSame(0, IdentityOtpChallenge::query()->count());
        $this->assertNull($user->email_verified_at);
    }

    public function test_restricted_registration_message_really_reaches_the_transport_without_a_number_proof(): void
    {
        Queue::fake();
        User::factory()->agen()->create();
        $user = $this->register();

        $row = app(WhatsAppDelivery::class)->deliver($this->registrationRow($user)->id);

        $this->assertSame(WhatsAppMessage::SENT, $row->status);
        $this->assertSame('test-device-'.$row->agent_id, $row->sender_device_id);
        Http::assertSentCount(1);
    }

    public function test_no_password_or_secret_is_disclosed_by_any_channel(): void
    {
        Queue::fake();
        User::factory()->agen()->create();
        $user = $this->register(['password' => 'S3cret-Pass-99', 'password_confirmation' => 'S3cret-Pass-99']);

        $row = $this->registrationRow($user);
        $this->assertStringNotContainsString('S3cret-Pass-99', (string) $row->body);
        $this->assertStringNotContainsString('S3cret', (string) $row->body);
        $this->assertNull($row->secret_body);
        $this->assertDoesNotMatchRegularExpression('/\b\d{6}\b/', (string) $row->body, 'no OTP-like code');
        $this->assertStringNotContainsString('http', (string) $row->body, 'no reset/verification link');

        $html = (new AccountRegisteredMail('Konsumen Baru'))->render();
        $this->assertStringNotContainsString('S3cret', $html);
        $this->assertStringNotContainsString('http', $html);
    }

    public function test_duplicate_registration_events_send_once_per_channel(): void
    {
        Queue::fake();
        User::factory()->agen()->create();
        $user = $this->register();

        app(RegistrationNotifier::class)->notify($user);
        app(RegistrationNotifier::class)->notify($user);

        Mail::assertQueued(AccountRegisteredMail::class, 1);
        $this->assertSame(1, WhatsAppMessage::query()->where('event', 'account_registered')->count());
    }

    public function test_failed_email_does_not_block_whatsapp_or_the_signup(): void
    {
        Queue::fake();
        User::factory()->agen()->create();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $user = $this->register();

        $this->assertNotNull($this->registrationRow($user), 'WhatsApp is independent of the Email failure');
        $this->assertAuthenticatedAs($user);
    }

    public function test_failed_whatsapp_enqueue_does_not_block_email_or_the_signup(): void
    {
        Queue::fake();
        User::factory()->agen()->create();
        $this->mock(WhatsAppDelivery::class, fn ($m) => $m->shouldReceive('enqueue')->andThrow(new \RuntimeException('db hiccup')));

        $user = $this->register();

        Mail::assertQueued(AccountRegisteredMail::class, 1);
        $this->assertAuthenticatedAs($user);
    }

    public function test_absent_contacts_are_handled_safely(): void
    {
        Queue::fake();
        User::factory()->agen()->create();

        $noEmail = $this->register(['name' => 'Tanpa Email', 'email' => null, 'phone' => '081399988877']);
        Mail::assertNothingQueued();
        $this->assertNotNull($this->registrationRow($noEmail));

        $badPhone = $this->register(['name' => 'Telepon Pendek', 'email' => 'x@example.com', 'phone' => '0812']);
        Mail::assertQueued(AccountRegisteredMail::class, 1);
        $this->assertNull($this->registrationRow($badPhone));
    }

    public function test_gateway_disabled_sends_no_whatsapp_but_still_emails(): void
    {
        Queue::fake();
        User::factory()->agen()->create();
        app(WhatsAppSettings::class)->update(['enabled' => false], User::factory()->superAdmin()->create());

        $this->register();

        $this->assertSame(0, WhatsAppMessage::query()->where('event', 'account_registered')->count());
        Mail::assertQueued(AccountRegisteredMail::class, 1);
    }

    public function test_registration_logs_the_default_agent_chain_without_referral(): void
    {
        Queue::fake();
        $agen = User::factory()->agen()->create();

        $user = $this->register(['name' => 'Tanpa Referral', 'email' => null, 'phone' => '081399988876']);

        $log = \App\Models\ActivityLog::query()
            ->where('event', 'auth.registered')->where('causer_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame($agen->id, $log->properties['agent_id'] ?? null);
    }

    public function test_admin_created_account_notifies_the_new_user_and_never_the_creator_or_the_password(): void
    {
        Queue::fake();
        $agen = User::factory()->agen()->create(['phone' => '081377700555', 'email' => 'agen.owner@example.com']);
        $agen->update(['agent_id' => $agen->id]);

        $res = $this->actingAs($agen)->postJson('/api/v1/users', [
            'name' => 'Admin Baru', 'email' => 'admin.baru@example.com', 'phone' => '081377700556',
            'password' => 'Adm1n-Chosen-Pass', 'password_confirmation' => 'Adm1n-Chosen-Pass', 'role' => 'admin',
        ]);
        $res->assertCreated();

        $created = User::query()->where('email', 'admin.baru@example.com')->firstOrFail();
        Mail::assertQueued(AccountRegisteredMail::class, fn (AccountRegisteredMail $m) => $m->hasTo('admin.baru@example.com'));
        Mail::assertNotQueued(AccountRegisteredMail::class, fn (AccountRegisteredMail $m) => $m->hasTo('agen.owner@example.com'));

        $row = $this->registrationRow($created);
        $this->assertNotNull($row);
        $this->assertSame('6281377700556', $row->recipient_phone);
        $this->assertSame($agen->id, (int) $row->agent_id);
        $this->assertStringNotContainsString('Adm1n', (string) $row->body);
        $this->assertStringNotContainsStringIgnoringCase('admin', (string) $row->body, 'no role/hierarchy disclosure');
        $this->assertSame(0, WhatsAppMessage::query()->where('recipient_phone', '6281377700555')->count());
    }

    public function test_ordinary_login_sends_no_registration_notice(): void
    {
        Queue::fake();
        $agen = User::factory()->agen()->create();
        $k = User::factory()->konsumen()->create(['agent_id' => $agen->id, 'email' => 'lama@example.com', 'phone' => '081377700777', 'password' => 'password123']);

        $this->postJson('/api/v1/auth/login', ['identifier' => 'lama@example.com', 'password' => 'password123'])->assertOk();

        Mail::assertNothingQueued();
        $this->assertSame(0, WhatsAppMessage::query()->where('recipient_user_id', $k->id)->count());
    }
}
