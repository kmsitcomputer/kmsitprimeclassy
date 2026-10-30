<?php

namespace Tests\Feature;

use App\Models\ConsentEvent;
use App\Models\Setting;
use App\Models\User;
use App\Services\WhatsApp\RetentionPlanner;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * G-21 (Owner decision 2026-10-30, CI-06 final policy): consent evidence is
 * retained while the customer relationship is active + 5 years after the
 * relationship ends; a legal hold suspends expiry; a longer statutory/legal
 * obligation always wins. Pins: the period computation (boundary included),
 * the legal-hold suspension, the data dependency for account-bound rows (no
 * invented end dates), and that the implementation can NEVER delete consent
 * evidence (no purge method exists and ConsentEvent refuses UPDATE/DELETE).
 * Runs on `primeclassy_testing` only — no evidence is ever deleted here.
 */
class ConsentEvidenceRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_period_computation_is_relationship_end_plus_five_years_with_a_strict_boundary(): void
    {
        $end = CarbonImmutable::parse('2020-01-01 00:00:00');

        // Window = relationship end + 5 years, computed purely (no DB).
        $this->assertSame('2025-01-01 00:00:00', RetentionPlanner::consentRetentionEnd($end)->format('Y-m-d H:i:s'));
        $this->assertSame('2025-01-01 00:00:00', RetentionPlanner::consentRetentionEnd('2020-01-01 00:00:00')->format('Y-m-d H:i:s'));

        // No modelled end → no invented window: evidence can never expire off a guessed date.
        $this->assertNull(RetentionPlanner::consentRetentionEnd(null));
        $this->assertFalse(RetentionPlanner::consentRetentionExpired(null, now()->addCenturies(1)));

        // Exactly AT the boundary the evidence is still inside the window…
        $window = RetentionPlanner::consentRetentionEnd($end);
        $this->assertFalse(RetentionPlanner::consentRetentionExpired($window, '2025-01-01 00:00:00'));
        // …one second past it, it is expired.
        $this->assertTrue(RetentionPlanner::consentRetentionExpired($window, '2025-01-01 00:00:01'));
        // Inside the window (active relationship): never expired.
        $this->assertFalse(RetentionPlanner::consentRetentionExpired($window, '2024-12-31 23:59:59'));
    }

    public function test_the_modelled_relationship_end_is_the_crm_archive_date_and_nothing_is_invented(): void
    {
        $agen = User::factory()->agen()->create();
        $sales = User::factory()->sales()->create();
        $archived = $this->crmCustomer($agen, $sales, '2026-01-02 03:04:05');
        $active = $this->crmCustomer($agen, $sales, null);

        $planner = app(RetentionPlanner::class);

        $this->assertSame('2026-01-02 03:04:05', $planner->relationshipEndAt($archived)->format('Y-m-d H:i:s'));
        // An ACTIVE relationship has no end date — not eligible, ever, while active.
        $this->assertNull($planner->relationshipEndAt($active));
    }

    public function test_report_counts_only_past_window_rows_and_records_the_account_data_dependency(): void
    {
        $agen = User::factory()->agen()->create();
        $sales = User::factory()->sales()->create();

        $expired = $this->crmCustomer($agen, $sales, now()->subYears(RetentionPlanner::CONSENT_AFTER_RELATIONSHIP_YEARS)->subDay());
        $insideWindow = $this->crmCustomer($agen, $sales, now()->subYears(RetentionPlanner::CONSENT_AFTER_RELATIONSHIP_YEARS)->addDay());
        $active = $this->crmCustomer($agen, $sales, null);

        $this->consentRow(User::factory()->konsumen()->create(), ['crm_customer_id' => $expired]);
        $this->consentRow(User::factory()->konsumen()->create(), ['crm_customer_id' => $insideWindow]);
        $this->consentRow(User::factory()->konsumen()->create(), ['crm_customer_id' => $active]);
        $this->consentRow(User::factory()->konsumen()->create()); // account-bound: crm_customer_id null

        $report = app(RetentionPlanner::class)->report()['consent_evidence'];

        $this->assertSame(4, $report['total_events']);
        $this->assertSame(3, $report['crm_bound']);
        $this->assertSame(2, $report['crm_bound_relationship_ended']);
        $this->assertSame(1, $report['active_relationships']);
        $this->assertSame(1, $report['retention_expired'], 'only the row archived past the 5-year window');
        $this->assertSame(1, $report['account_bound_without_modelled_end_date']);
        $this->assertStringContainsString('no timestamp modelling', $report['data_dependency']);
        $this->assertStringContainsString('REPORT ONLY', $report['mode']);
        $this->assertSame('REPORT ONLY — removal needs a separate Owner-approved mechanism (none exists)', $report['action']);
    }

    public function test_a_legal_hold_suspends_consent_expiry_but_the_policy_and_counts_stay_visible(): void
    {
        $agen = User::factory()->agen()->create();
        $sales = User::factory()->sales()->create();
        $expired = $this->crmCustomer($agen, $sales, now()->subYears(RetentionPlanner::CONSENT_AFTER_RELATIONSHIP_YEARS)->subYear());
        $this->consentRow(User::factory()->konsumen()->create(), ['crm_customer_id' => $expired]);

        Setting::create(['group' => 'retention', 'key' => 'retention.legal_hold', 'value' => '1', 'is_public' => false]);

        $report = app(RetentionPlanner::class)->report();

        $this->assertTrue($report['legal_hold']);
        $this->assertSame(0, $report['consent_evidence']['retention_expired']);
        $this->assertSame(1, $report['consent_evidence']['total_events']);
    }

    public function test_consent_evidence_can_never_be_deleted_by_the_retention_implementation(): void
    {
        $agen = User::factory()->agen()->create();
        $sales = User::factory()->sales()->create();
        $customer = $this->crmCustomer($agen, $sales, null);
        $subject = User::factory()->konsumen()->create();
        $row = $this->consentRow($subject, ['crm_customer_id' => $customer]);

        $before = DB::table('consent_events')->count();

        // There is no consent purge/minimise method on the planner at all.
        foreach (['purgeConsentEvents', 'minimiseConsentEvents', 'deleteConsentEvidence', 'purgeExpiredConsent'] as $method) {
            $this->assertFalse(method_exists(RetentionPlanner::class, $method), "RetentionPlanner must not expose {$method}");
        }

        // The report path itself writes nothing.
        app(RetentionPlanner::class)->report();
        $this->artisan('primeclassy:whatsapp-retention-report')->assertSuccessful();
        $this->assertSame($before, DB::table('consent_events')->count());

        // The model boundary refuses UPDATE and DELETE for consent evidence.
        $event = ConsentEvent::query()->whereKey($row)->first();
        $this->assertNotNull($event);

        try {
            $event->delete();
            $this->fail('DELETE must be refused by the ConsentEvent boundary');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            $event->update(['note' => 'tamper']);
            $this->fail('UPDATE must be refused by the ConsentEvent boundary');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertSame($before, DB::table('consent_events')->count());
    }

    public function test_nothing_is_scheduled_for_consent_retention(): void
    {
        $scheduled = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map->command
            ->filter(fn ($c) => str_contains((string) $c, 'retention'));
        $this->assertCount(0, $scheduled, 'no retention job (incl. consent) is scheduled');
    }

    /** Real crm_customers row (real agen + owning sales so both FKs hold). */
    private function crmCustomer(User $agen, User $owner, ?string $archivedAt): int
    {
        return DB::table('crm_customers')->insertGetId([
            'agent_id' => $agen->id,
            'owner_user_id' => $owner->id,
            'name' => 'CRM '.bin2hex(random_bytes(4)),
            'phone' => '08'.random_int(1000000000, 9999999999),
            'archived_at' => $archivedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Minimal valid consent_events row (column-discovery pattern from
     * WhatsAppRetentionPlanTest). FK enforcement is disabled only for the
     * irrelevant order/agent FKs of a synthetic row on the TEST database.
     */
    private function consentRow(User $u, array $over = []): int
    {
        $row = ['created_at' => now(), 'updated_at' => now()] + $over;
        foreach (DB::select('show columns from consent_events') as $c) {
            if (isset($row[$c->Field]) || $c->Null === 'YES' || $c->Default !== null || str_contains((string) $c->Extra, 'auto_increment')) {
                continue;
            }
            $row[$c->Field] = match (true) {
                in_array($c->Field, ['agent_id', 'subject_user_id', 'captured_by', 'actor_id', 'created_by'], true) => $u->id,
                str_contains($c->Type, 'int') => 1,
                str_contains($c->Type, 'json') => '{}',
                str_contains($c->Type, 'timestamp') || str_contains($c->Type, 'datetime') => now(),
                str_starts_with($c->Type, 'enum') => preg_match("/enum\('([^']+)'/", $c->Type, $m) ? $m[1] : 'x',
                default => 'x',
            };
        }

        Schema::disableForeignKeyConstraints();
        try {
            $id = DB::table('consent_events')->insertGetId($row);
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        return $id;
    }
}
