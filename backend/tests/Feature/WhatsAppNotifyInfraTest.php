<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessageJob;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppDelivery;
use App\Services\WhatsApp\WhatsAppSettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * WS-02 scoped tests (AUD-09, HA-01, HA-02/CL-01, CL-13): dedicated notify queue lane, explicit-sender recording,
 * and additive ledger columns. HTTP is faked, never real. Scoped only — no full suite.
 */
class WhatsAppNotifyInfraTest extends TestCase
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
        app(WhatsAppSettings::class)->update([
            'enabled' => true, 'base_url' => self::BASE, 'username' => 'u', 'password' => 'p', 'max_attempts' => 3, 'backoff_seconds' => 10,
        ], User::factory()->superAdmin()->create());
    }

    private function agent(): User
    {
        return User::factory()->agen()->create();
    }

    private function provenHolder(User $agent, string $msisdn): User
    {
        $u = User::factory()->konsumen()->create(['agent_id' => $agent->id, 'phone' => '0'.substr($msisdn, 2)]);
        \App\Models\IdentityOtpChallenge::create([
            'agent_id' => $agent->id, 'subject_user_id' => $u->id, 'created_by' => $u->id, 'purpose' => 'whatsapp_number',
            'code_hash' => str_repeat('0', 64), 'sent_to_email' => 'wa:'.$msisdn, 'attempts' => 1, 'max_attempts' => 5,
            'expires_at' => now()->addMinutes(5), 'verified_at' => now(), 'consumed_at' => now(),
        ]);

        return $u;
    }

    public function test_transactional_jobs_go_to_the_dedicated_notify_lane(): void
    {
        Queue::fake();
        $agent = $this->agent();
        $holder = $this->provenHolder($agent, '6281300000091');

        app(WhatsAppDelivery::class)->enqueue([
            'idempotency_key' => 'ws02:'.bin2hex(random_bytes(6)), 'purpose' => 'transaction', 'event' => 'order_shipped',
            'agent_id' => $agent->id, 'recipient_user_id' => $holder->id, 'recipient_phone' => '6281300000091', 'body' => 'Halo',
        ]);

        Queue::assertPushed(SendWhatsAppMessageJob::class, fn ($job) => $job->queue === WhatsAppSettings::NOTIFY_QUEUE);
    }

    public function test_delivered_message_records_the_exact_sender_device(): void
    {
        $agent = $this->agent();
        $holder = $this->provenHolder($agent, '6281300000092');

        $m = app(WhatsAppDelivery::class)->enqueue([
            'idempotency_key' => 'ws02:'.bin2hex(random_bytes(6)), 'purpose' => 'transaction', 'event' => 'order_shipped',
            'agent_id' => $agent->id, 'recipient_user_id' => $holder->id, 'recipient_phone' => '6281300000092', 'body' => 'Halo',
        ], dispatch: false);

        $done = app(WhatsAppDelivery::class)->deliver($m->id);

        $this->assertSame('sent', $done->status);
        $this->assertSame('test-device-'.$agent->id, $done->sender_device_id);
    }

    public function test_ledger_carries_template_class_and_recipient_role_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('whatsapp_messages', 'template_class'));
        $this->assertTrue(Schema::hasColumn('whatsapp_messages', 'recipient_role'));
        $this->assertTrue(Schema::hasColumn('whatsapp_messages', 'sender_device_id'));

        $agent = $this->agent();
        $holder = $this->provenHolder($agent, '6281300000093');

        $m = app(WhatsAppDelivery::class)->enqueue([
            'idempotency_key' => 'ws02:'.bin2hex(random_bytes(6)), 'purpose' => 'transaction', 'event' => 'order_shipped',
            'recipient_role' => 'creator', 'template_class' => 'full',
            'agent_id' => $agent->id, 'recipient_user_id' => $holder->id, 'recipient_phone' => '6281300000093', 'body' => 'Halo',
        ], dispatch: false);

        $this->assertSame('creator', $m->refresh()->recipient_role);
        $this->assertSame('full', $m->refresh()->template_class);
        $this->assertSame(WhatsAppMessage::QUEUED, $m->refresh()->status);
    }
}
