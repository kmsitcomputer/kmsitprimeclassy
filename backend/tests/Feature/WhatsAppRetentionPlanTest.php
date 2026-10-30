<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\IdentityOtpChallenge;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\RetentionPlanner;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The retention plan only REPORTS. These tests pin what it may ever count and that it changes nothing. */
class WhatsAppRetentionPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function challenge(User $u, array $over = []): IdentityOtpChallenge
    {
        return IdentityOtpChallenge::create($over + [
            'agent_id' => $u->id, 'subject_user_id' => $u->id, 'created_by' => $u->id, 'purpose' => 'assisted_return',
            'code_hash' => str_repeat('0', 64), 'sent_to_email' => 'x@example.test', 'attempts' => 0, 'max_attempts' => 5,
            'expires_at' => now()->subDays(45),
        ]);
    }

    private function message(string $status, int $ageDays): WhatsAppMessage
    {
        $m = WhatsAppMessage::create([
            'idempotency_key' => 'k:'.bin2hex(random_bytes(5)), 'purpose' => 'transaction', 'event' => 'order_shipped',
            'recipient_phone' => '6281300000001', 'body' => 'Halo', 'status' => $status, 'max_attempts' => 3, 'queued_at' => now(),
        ]);
        DB::table('whatsapp_messages')->where('id', $m->id)->update(['created_at' => now()->subDays($ageDays)]);

        return $m;
    }

    public function test_only_old_final_messages_are_eligible_and_open_states_are_never_counted(): void
    {
        $this->message('sent', 120);
        $this->message('failed', 100);
        $this->message('skipped', 91);
        $this->message('sent', 30);        // too young
        $this->message('unknown', 200);    // needs an operator decision
        $this->message('queued', 200);     // still pending
        $this->message('sending', 200);

        $r = app(RetentionPlanner::class)->report();
        $this->assertSame(3, $r['whatsapp_messages']['eligible_for_minimisation']);
        $this->assertSame(3, $r['whatsapp_messages']['kept_open_states']);
    }

    public function test_challenges_that_back_evidence_or_a_live_proof_are_never_eligible(): void
    {
        $u = User::factory()->konsumen()->create();

        $unused = $this->challenge($u);                                                   // eligible
        $voided = $this->challenge($u, ['consumed_at' => now()->subDays(50), 'invalidated_at' => now()->subDays(40)]); // eligible
        $liveProof = $this->challenge($u, ['purpose' => 'whatsapp_number', 'sent_to_email' => 'wa:6281300000009', 'consumed_at' => now()->subDays(60), 'verified_at' => now()->subDays(60)]);
        $young = $this->challenge($u, ['expires_at' => now()->subDays(5)]);
        $evidence = $this->challenge($u, ['consumed_at' => now()->subDays(60), 'verified_at' => now()->subDays(60), 'invalidated_at' => now()->subDays(40)]);
        // Evidence row references the (even voided) challenge: RESTRICT FK, must stay.
        // Test database only: the unrelated order/agent FKs of an evidence row are irrelevant to what is asserted here.
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        DB::table('consent_events')->insert($this->consentRow($u, $evidence->id));
        \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();

        $ids = app(RetentionPlanner::class)->challengeCandidates()->pluck('id')->all();
        sort($ids);

        $this->assertSame([$unused->id, $voided->id], $ids);
        $this->assertNotContains($liveProof->id, $ids);
        $this->assertNotContains($young->id, $ids);
        $this->assertNotContains($evidence->id, $ids);
    }

    public function test_a_legal_hold_suspends_everything_and_the_report_modifies_nothing(): void
    {
        $u = User::factory()->konsumen()->create();
        $this->challenge($u);
        $this->message('sent', 200);
        $before = [IdentityOtpChallenge::query()->count(), WhatsAppMessage::query()->count(), json_encode(DB::table('whatsapp_messages')->get())];

        $this->assertSame(1, app(RetentionPlanner::class)->report()['identity_otp_challenges']['eligible_for_deletion']);

        Setting::create(['group' => 'retention', 'key' => 'retention.legal_hold', 'value' => '1', 'is_public' => false]);
        $held = app(RetentionPlanner::class)->report();
        $this->assertTrue($held['legal_hold']);
        $this->assertSame(0, $held['identity_otp_challenges']['eligible_for_deletion']);
        $this->assertSame(0, $held['whatsapp_messages']['eligible_for_minimisation']);

        $this->assertSame($before, [IdentityOtpChallenge::query()->count(), WhatsAppMessage::query()->count(), json_encode(DB::table('whatsapp_messages')->get())]);
    }

    public function test_the_command_is_read_only_and_nothing_is_scheduled(): void
    {
        $this->message('sent', 200);
        $this->artisan('primeclassy:whatsapp-retention-report')->assertSuccessful();
        $this->assertSame(1, WhatsAppMessage::query()->count());

        $this->assertArrayNotHasKey('execute', \Illuminate\Support\Facades\Artisan::all()['primeclassy:whatsapp-retention-report']->getDefinition()->getOptions());
        $scheduled = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map->command->filter(fn ($c) => str_contains((string) $c, 'retention'));
        $this->assertCount(0, $scheduled, 'no retention job is scheduled');
    }

    /* ----------------------------------------------------------------- Option A: minimisation */

    public function test_minimisation_blanks_personal_content_after_90_days_and_keeps_what_prevents_a_duplicate_send(): void
    {
        $old = $this->message('sent', 91);
        $edge = $this->message('failed', 89);        // inside the window: untouched
        $open = $this->message('queued', 200);       // not final: untouched
        $unknown = $this->message('unknown', 200);   // operator decision pending: untouched
        $old->forceFill(['error_message' => 'HTTP 500: provider said something', 'provider_message_id' => 'WA-OLD', 'attempts' => 2, 'agent_id' => null])->save();
        $before = DB::table('whatsapp_messages')->where('id', $old->id)->first();

        $r = app(RetentionPlanner::class)->minimizeMessages(execute: true, backupConfirmed: true);
        $this->assertSame(['mode' => 'EXECUTE', 'legal_hold' => false, 'eligible' => 1, 'minimised' => 1], $r);

        $after = DB::table('whatsapp_messages')->where('id', $old->id)->first();
        foreach (['body', 'secret_body', 'recipient_phone', 'recipient_user_id', 'error_message'] as $blank) {
            $this->assertNull($after->$blank, "$blank must be blanked");
        }
        // idempotency identity, event identity, status, counters and timestamps survive
        foreach (['idempotency_key', 'purpose', 'event', 'status', 'attempts', 'provider_message_id', 'created_at', 'queued_at', 'sent_at', 'order_id', 'subject_type', 'subject_id'] as $kept) {
            $this->assertEquals($before->$kept, $after->$kept, "$kept must be kept");
        }
        // untouched rows
        foreach ([$edge, $open, $unknown] as $m) {
            $this->assertNotNull(DB::table('whatsapp_messages')->where('id', $m->id)->value('body'));
        }
    }

    public function test_the_idempotency_key_still_blocks_a_duplicate_after_minimisation(): void
    {
        $old = $this->message('sent', 120);
        app(RetentionPlanner::class)->minimizeMessages(execute: true, backupConfirmed: true);

        // A replayed lifecycle event re-uses the key: the existing (minimised) row is returned, no second message is created.
        $again = app(\App\Services\WhatsApp\WhatsAppDelivery::class)->enqueue([
            'idempotency_key' => $old->idempotency_key, 'purpose' => 'transaction', 'event' => 'order_shipped', 'recipient_phone' => '6281300000001', 'body' => 'Halo lagi',
        ], dispatch: false);
        $this->assertSame($old->id, $again->id);
        $this->assertSame(1, WhatsAppMessage::query()->count());
        $this->assertSame('sent', $again->status);
        // ...and a minimised row can never be manually re-sent (no body left).
        $this->assertNull($again->body);
    }

    public function test_it_is_idempotent_a_dry_run_by_default_and_refuses_without_a_confirmed_backup(): void
    {
        $this->message('sent', 120);
        $planner = app(RetentionPlanner::class);

        $dry = $planner->minimizeMessages();
        $this->assertSame(['mode' => 'DRY-RUN', 'legal_hold' => false, 'eligible' => 1, 'minimised' => 0], $dry);
        $this->assertSame(1, WhatsAppMessage::query()->whereNotNull('body')->count(), 'dry-run changed nothing');

        try {
            $planner->minimizeMessages(execute: true);
            $this->fail('execution without a backup must be refused');
        } catch (\RuntimeException) {
            $this->assertSame(1, WhatsAppMessage::query()->whereNotNull('body')->count());
        }
        $this->artisan('primeclassy:whatsapp-retention-minimize', ['--execute' => true])->assertFailed();
        $this->assertSame(1, WhatsAppMessage::query()->whereNotNull('body')->count());

        $this->assertSame(1, $planner->minimizeMessages(execute: true, backupConfirmed: true)['minimised']);
        $this->assertSame(0, $planner->minimizeMessages(execute: true, backupConfirmed: true)['minimised'], 're-running changes nothing');
    }

    public function test_a_legal_hold_suspends_minimisation(): void
    {
        $this->message('sent', 300);
        Setting::create(['group' => 'retention', 'key' => 'retention.legal_hold', 'value' => '1', 'is_public' => false]);

        $r = app(RetentionPlanner::class)->minimizeMessages(execute: true, backupConfirmed: true);
        $this->assertTrue($r['legal_hold']);
        $this->assertSame(0, $r['minimised']);
        $this->assertSame(1, WhatsAppMessage::query()->whereNotNull('body')->count());
        $this->artisan('primeclassy:whatsapp-retention-minimize', ['--execute' => true, '--backup-confirmed' => true])->assertSuccessful();
        $this->assertSame(1, WhatsAppMessage::query()->whereNotNull('body')->count());
    }

    /* ----------------------------------------------------------------- Option B: expired OTP challenge purge (same safety shape) */

    public function test_challenge_purge_deletes_only_unreferenced_expired_candidates(): void
    {
        $u = User::factory()->konsumen()->create();
        $unused = $this->challenge($u);
        $voided = $this->challenge($u, ['consumed_at' => now()->subDays(50), 'invalidated_at' => now()->subDays(40)]);
        $liveProof = $this->challenge($u, ['purpose' => 'whatsapp_number', 'consumed_at' => now()->subDays(60), 'sent_to_email' => 'wa:6281300000009']);
        $young = $this->challenge($u, ['expires_at' => now()->subDays(5)]);
        $evidence = $this->challenge($u, ['consumed_at' => now()->subDays(60), 'invalidated_at' => now()->subDays(40), 'verified_at' => now()->subDays(60)]);
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        DB::table('consent_events')->insert($this->consentRow($u, $evidence->id));
        \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();

        $r = app(RetentionPlanner::class)->purgeExpiredChallenges(execute: true, backupConfirmed: true);

        $this->assertSame(['mode' => 'EXECUTE', 'legal_hold' => false, 'eligible' => 2, 'purged' => 2, 'skipped' => 0], $r);
        $this->assertDatabaseMissing('identity_otp_challenges', ['id' => $unused->id]);
        $this->assertDatabaseMissing('identity_otp_challenges', ['id' => $voided->id]);
        foreach ([$liveProof, $young, $evidence] as $kept) {
            $this->assertDatabaseHas('identity_otp_challenges', ['id' => $kept->id]);
        }

        $last = ActivityLog::where('event', 'identity.otp.challenges.purged')->latest('id')->first();
        $this->assertNotNull($last, 'the purge must leave an audit event');
        $this->assertSame(2, (int) $last->properties['rows']);
        $this->assertSame(30, (int) $last->properties['policy_days']);
    }

    public function test_challenge_purge_is_dry_run_by_default_refuses_without_backup_and_is_idempotent(): void
    {
        $u = User::factory()->konsumen()->create();
        $this->challenge($u);

        $dry = app(RetentionPlanner::class)->purgeExpiredChallenges();
        $this->assertSame(['mode' => 'DRY-RUN', 'legal_hold' => false, 'eligible' => 1, 'purged' => 0, 'skipped' => 0], $dry);
        $this->assertSame(1, IdentityOtpChallenge::query()->count(), 'dry-run changed nothing');

        try {
            app(RetentionPlanner::class)->purgeExpiredChallenges(execute: true);
            $this->fail('execution without a backup must be refused');
        } catch (\RuntimeException) {
            $this->assertSame(1, IdentityOtpChallenge::query()->count());
        }
        $this->artisan('primeclassy:otp-retention-purge', ['--execute' => true])->assertFailed();
        $this->assertSame(1, IdentityOtpChallenge::query()->count());

        $this->artisan('primeclassy:otp-retention-purge', ['--execute' => true, '--backup-confirmed' => true])->assertSuccessful();
        $this->assertSame(0, IdentityOtpChallenge::query()->count());
        $this->artisan('primeclassy:otp-retention-purge', ['--execute' => true, '--backup-confirmed' => true])->assertSuccessful();
        $this->assertSame(0, IdentityOtpChallenge::query()->count(), 're-running changes nothing');
    }

    public function test_a_legal_hold_suspends_challenge_purge_too(): void
    {
        $u = User::factory()->konsumen()->create();
        $this->challenge($u);
        Setting::create(['group' => 'retention', 'key' => 'retention.legal_hold', 'value' => '1', 'is_public' => false]);

        $r = app(RetentionPlanner::class)->purgeExpiredChallenges(execute: true, backupConfirmed: true);
        $this->assertTrue($r['legal_hold']);
        $this->assertSame(0, $r['purged']);
        $this->assertSame(1, IdentityOtpChallenge::query()->count());
    }

    /** Minimal valid consent_events row; columns are discovered so the test survives unrelated schema additions. */
    private function consentRow(User $u, int $challengeId): array
    {
        $row = ['otp_challenge_id' => $challengeId, 'created_at' => now(), 'updated_at' => now()];
        foreach (DB::select('show columns from consent_events') as $c) {
            if (isset($row[$c->Field]) || $c->Null === 'YES' || $c->Default !== null || str_contains((string) $c->Extra, 'auto_increment')) {
                continue;
            }
            $row[$c->Field] = match (true) {
                in_array($c->Field, ['agent_id', 'subject_user_id', 'captured_by', 'actor_id', 'created_by'], true) => $u->id,
                str_contains($c->Type, 'int') => 1,
                str_contains($c->Type, 'json') => '{}',
                str_contains($c->Type, 'timestamp') || str_contains($c->Type, 'datetime') => now(),
                str_starts_with($c->Type, 'enum') => preg_match("/enum\\('([^']+)'/", $c->Type, $m) ? $m[1] : 'x',
                default => 'x',
            };
        }

        return $row;
    }
}
