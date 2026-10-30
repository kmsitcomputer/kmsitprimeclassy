<?php

namespace App\Services\WhatsApp;

use App\Models\IdentityOtpChallenge;
use App\Models\Setting;
use App\Models\WhatsAppMessage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * READ-ONLY retention plan (Owner policy 2026-10-29 + G-21 final consent policy
 * 2026-10-30): whatsapp_messages 90 days operational; expired OTP challenges 30
 * days; consent evidence is kept while the customer relationship is active plus
 * CONSENT_AFTER_RELATIONSHIP_YEARS after the relationship ends (account-bound
 * rows have no modelled end date — data dependency recorded, nothing invented);
 * a legal hold suspends everything and a longer statutory obligation wins.
 *
 * Reporting never writes. The ONLY write path is minimizeMessages(execute: true) — Owner-approved Option A (2026-10-29): after 90 days a
 * final message is MINIMISED (body, recipient number/user, provider error text blanked), never deleted. It refuses without a confirmed
 * backup, does nothing under a legal hold, is idempotent, and is NOT scheduled anywhere (activation needs a separate authorisation). Integrity findings behind the rules:
 *   - identity_otp_challenges is referenced (RESTRICT) by consent_events and crm_customer_link_events: a challenge that backs
 *     evidence is NEVER eligible. A consumed `whatsapp_number` challenge that is not invalidated IS the live ownership proof
 *     (WhatsAppNumberProof derives from it) and is never eligible either.
 *   - whatsapp_messages has no inbound FK, but its UNIQUE idempotency_key is what stops a replayed lifecycle event from sending
 *     twice, and activity_logs point at it. Deleting the row would re-arm the key, so the recommended action is to MINIMISE
 *     (blank body / recipient / provider error text) and keep the row; a hard delete is reported separately and is not recommended.
 */
final class RetentionPlanner
{
    public const MESSAGE_DAYS = 90;

    public const CHALLENGE_DAYS = 30;

    /**
     * G-21 (Owner decision 2026-10-30, CI-06 final policy): consent evidence is
     * retained while the customer relationship is ACTIVE, then for this many
     * years after the relationship ends. A legal hold suspends expiry; a
     * LONGER statutory/legal obligation always wins (this planner's number is
     * the floor, never a delete trigger). There is intentionally NO deletion
     * method for consent evidence here and NO scheduler — removal needs a
     * separate Owner-approved mechanism.
     */
    public const CONSENT_AFTER_RELATIONSHIP_YEARS = 5;

    public const CONSENT_POLICY = 'consent evidence is retained while the customer relationship is active, then for '
        .self::CONSENT_AFTER_RELATIONSHIP_YEARS.' years after the relationship ends; a longer statutory/legal '
        .'obligation always wins (this window is a floor, never a delete trigger); a legal hold suspends expiry';

    public const CONSENT_DATA_DEPENDENCY = 'account-origin consent rows cannot be period-computed: users.status '
        ."(active/inactive/suspended) has no timestamp modelling when the account relationship ends — no date is "
        .'invented, those rows are never eligible through this plan until the end-date column is Owner-approved';

    public const FINAL_STATUSES = [WhatsAppMessage::SENT, WhatsAppMessage::FAILED, WhatsAppMessage::SKIPPED];

    public function legalHold(): bool
    {
        $v = Setting::query()->where('group', 'retention')->where('key', 'retention.legal_hold')->value('value');

        return $v !== null && filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    /** Final messages past the retention window (minimised or not). @return Builder<WhatsAppMessage> */
    public function messageCandidates(): Builder
    {
        return WhatsAppMessage::query()
            ->whereIn('status', self::FINAL_STATUSES)
            ->where('created_at', '<', now()->subDays(self::MESSAGE_DAYS));
    }

    /** Past the window AND still holding personal content: what minimisation would change. @return Builder<WhatsAppMessage> */
    public function messagesPendingMinimization(): Builder
    {
        return $this->messageCandidates()->where(fn (Builder $q) => $q
            ->whereNotNull('body')->orWhereNotNull('recipient_phone')->orWhereNotNull('error_message')
            ->orWhereNotNull('recipient_user_id')->orWhereNotNull('secret_body'));
    }

    /**
     * Option A. Keeps: idempotency_key, purpose, event, agent/order/subject references, status, attempts, error_class, skip_reason, provider id
     * and every timestamp — i.e. exactly what stops a replayed event from sending twice and what the audit trail points at.
     * Blanks: message text, recipient number, recipient user, provider error text, any leftover secret text.
     *
     * @return array{mode:string, legal_hold:bool, eligible:int, minimised:int}
     *
     * @throws \RuntimeException when execution is requested without a confirmed backup
     */
    public function minimizeMessages(bool $execute = false, bool $backupConfirmed = false): array
    {
        if (! WhatsAppMessage::tableReady()) {
            return ['mode' => $execute ? 'EXECUTE' : 'DRY-RUN', 'legal_hold' => false, 'eligible' => 0, 'minimised' => 0];
        }

        $hold = $this->legalHold();
        $eligible = $hold ? 0 : $this->messagesPendingMinimization()->count();
        $result = ['mode' => $execute ? 'EXECUTE' : 'DRY-RUN', 'legal_hold' => $hold, 'eligible' => $eligible, 'minimised' => 0];

        if (! $execute || $hold || $eligible === 0) {
            return $result;
        }
        if (! $backupConfirmed) {
            throw new \RuntimeException('Refusing to minimise without a confirmed backup (--backup-confirmed).');
        }

        $done = 0;
        $this->messagesPendingMinimization()->select('id')->chunkById(500, function ($chunk) use (&$done) {
            $done += DB::table('whatsapp_messages')->whereIn('id', $chunk->pluck('id'))->update([
                'body' => null, 'secret_body' => null, 'recipient_phone' => null, 'recipient_user_id' => null, 'error_message' => null, 'updated_at' => now(),
            ]);
        });

        \App\Services\Logging\ActivityLogger::log(null, \App\Models\Setting::query()->firstOrCreate(['key' => 'wa.enabled'], ['value' => '0', 'group' => 'integration.whatsapp', 'is_public' => false]), 'whatsapp.retention.minimized', null, ['rows' => $done, 'policy_days' => self::MESSAGE_DAYS]);

        return [...$result, 'minimised' => $done];
    }

    /** @return Builder<IdentityOtpChallenge> */
    public function challengeCandidates(): Builder
    {
        $cutoff = now()->subDays(self::CHALLENGE_DAYS);

        return IdentityOtpChallenge::query()
            ->where('expires_at', '<', $cutoff)
            // Never became evidence: unused/expired, or explicitly voided. A consumed + still-valid row is evidence or proof.
            ->where(fn (Builder $q) => $q->whereNull('consumed_at')->orWhereNotNull('invalidated_at'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('consent_events')->whereColumn('consent_events.otp_challenge_id', 'identity_otp_challenges.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('crm_customer_link_events')->whereColumn('crm_customer_link_events.otp_challenge_id', 'identity_otp_challenges.id'));
    }

    /**
     * Option B for `identity_otp_challenges` (the SAME twin-flag safety as WA minimisation): delete
     * rows that expired >30 days ago and never became evidence or a live ownership proof (see
     * challengeCandidates). DRY-RUN unless both flags; refused under a legal hold; idempotent;
     * handled row by row so a restive DB raised by a RESTRICT FK can never abort the whole batch.
     *
     * @return array{mode:string, legal_hold:bool, eligible:int, purged:int, skipped:int}
     *
     * @throws \RuntimeException when execution is requested without a confirmed backup
     */
    public function purgeExpiredChallenges(bool $execute = false, bool $backupConfirmed = false): array
    {
        if (! Schema::hasTable('identity_otp_challenges')) {
            return ['mode' => $execute ? 'EXECUTE' : 'DRY-RUN', 'legal_hold' => false, 'eligible' => 0, 'purged' => 0, 'skipped' => 0];
        }

        $hold = $this->legalHold();
        $candidates = $this->challengeCandidates();
        $eligible = $hold ? 0 : (clone $candidates)->count();
        $result = ['mode' => $execute ? 'EXECUTE' : 'DRY-RUN', 'legal_hold' => $hold, 'eligible' => $eligible, 'purged' => 0, 'skipped' => 0];

        if (! $execute || $hold || $eligible === 0) {
            return $result;
        }
        if (! $backupConfirmed) {
            throw new \RuntimeException('Refusing to purge without a confirmed backup (--backup-confirmed).');
        }

        $purged = 0;
        $skipped = 0;
        $candidates->select('id')->chunkById(500, function ($chunk) use (&$purged, &$skipped) {
            foreach ($chunk as $row) {
                try {
                    $purged += IdentityOtpChallenge::query()->whereKey($row->id)->delete();
                } catch (\Illuminate\Database\QueryException $e) {
                    // RESTRICT referenced by a fresh consent/link event that appeared since the count —
                    // an audit-capable skip, never a silent data loss and never an aborted run.
                    \Log::channel('single')->warning('retention.skip.challenge', ['id' => $row->id, 'reason' => $e->getMessage()]);
                    $skipped++;
                }
            }
        });

        \App\Services\Logging\ActivityLogger::log(null, Setting::query()->firstOrCreate(['key' => 'wa.enabled'], ['value' => '0', 'group' => 'integration.whatsapp', 'is_public' => false]), 'identity.otp.challenges.purged', null, ['rows' => $purged, 'skipped' => $skipped, 'policy_days' => self::CHALLENGE_DAYS]);

        return [...$result, 'purged' => $purged];
    }

    /**
     * The modelled end of the customer relationship behind a consent row.
     * CRM-origin evidence: `crm_customers.archived_at` (archiving IS the
     * relationship end — the only modelled end date). Account-bound evidence:
     * NOT modelled (users.status has no end timestamp) → null, never an
     * invented date. Read-only, scope-free by design (console context).
     */
    public function relationshipEndAt(int $crmCustomerId): ?CarbonImmutable
    {
        $archived = DB::table('crm_customers')->where('id', $crmCustomerId)->value('archived_at');

        return $archived === null ? null : CarbonImmutable::parse($archived);
    }

    /**
     * Pure period computation (no DB): retention window = relationship end
     * + CONSENT_AFTER_RELATIONSHIP_YEARS. With no modelled end it stays open —
     * the evidence can never expire off a guessed date.
     *
     * @param CarbonInterface|\DateTimeInterface|string|null $relationshipEnd
     */
    public static function consentRetentionEnd($relationshipEnd): ?CarbonImmutable
    {
        if ($relationshipEnd === null) {
            return null;
        }

        return CarbonImmutable::parse($relationshipEnd)->addYears(self::CONSENT_AFTER_RELATIONSHIP_YEARS);
    }

    /** Pure expiry predicate: STRICTLY past the window (at the boundary it is still kept). */
    public static function consentRetentionExpired($retentionEnd, $at): bool
    {
        if ($retentionEnd === null) {
            return false;
        }

        return CarbonImmutable::parse($at)->gt(CarbonImmutable::parse($retentionEnd));
    }

    /**
     * READ-ONLY consent-evidence block for report(). Reports the final CI-06
     * policy and the one data dependency; it counts only, deletes nothing,
     * schedules nothing, and ConsentEvent itself refuses UPDATE/DELETE at the
     * model boundary — evidence protection does not depend on this planner.
     *
     * @return array{mode:string, policy:string, total_events:int, crm_bound:int,
     *     crm_bound_relationship_ended:int, retention_expired:int, active_relationships:int,
     *     account_bound_without_modelled_end_date:int, data_dependency:string, action:string}
     */
    public function consentEvidenceReport(): array
    {
        $report = [
            'mode' => 'REPORT ONLY (read-only; consent evidence is never deleted by this plan)',
            'policy' => self::CONSENT_POLICY,
            'total_events' => 0, 'crm_bound' => 0, 'crm_bound_relationship_ended' => 0,
            'retention_expired' => 0, 'active_relationships' => 0,
            'account_bound_without_modelled_end_date' => 0,
            'data_dependency' => self::CONSENT_DATA_DEPENDENCY,
            'action' => 'REPORT ONLY — removal needs a separate Owner-approved mechanism (none exists)',
        ];

        if (! Schema::hasTable('consent_events') || ! Schema::hasTable('crm_customers')) {
            return $report;
        }

        $hold = $this->legalHold();
        $base = DB::table('consent_events');
        $crmBound = (clone $base)->whereNotNull('crm_customer_id');

        $report['total_events'] = (clone $base)->count();
        $report['crm_bound'] = (clone $crmBound)->count();
        $report['crm_bound_relationship_ended'] = (clone $crmBound)->whereExists(fn ($s) => $s->select(DB::raw(1))
            ->from('crm_customers')->whereColumn('crm_customers.id', 'consent_events.crm_customer_id')
            ->whereNotNull('crm_customers.archived_at'))->count();
        // G-21 policy period: archived_at < (now - 5y). A row archived exactly
        // AT the boundary is still inside the window (strict comparison below).
        // Legal hold overrides: nothing is expired while it is on.
        $report['retention_expired'] = $hold ? 0 : (clone $crmBound)->whereExists(fn ($v) => $v->select(DB::raw(1))
            ->from('crm_customers')->whereColumn('crm_customers.id', 'consent_events.crm_customer_id')
            ->whereNotNull('crm_customers.archived_at')
            ->where('crm_customers.archived_at', '<', now()->subYears(self::CONSENT_AFTER_RELATIONSHIP_YEARS)))->count();
        $report['active_relationships'] = (clone $crmBound)->whereNotExists(fn ($s) => $s->select(DB::raw(1))
            ->from('crm_customers')->whereColumn('crm_customers.id', 'consent_events.crm_customer_id')
            ->whereNotNull('crm_customers.archived_at'))->count();
        $report['account_bound_without_modelled_end_date'] = (clone $base)->whereNull('crm_customer_id')->count();

        return $report;
    }

    /** @return array<string,mixed> */
    public function report(): array
    {        $hold = $this->legalHold();
        $messagesReady = WhatsAppMessage::tableReady();

        $messages = $messagesReady ? $this->messageCandidates() : null;
        $challenges = Schema::hasTable('identity_otp_challenges') ? $this->challengeCandidates() : null;

        return [
            'mode' => 'DRY-RUN (read-only; nothing is modified, nothing is scheduled)',
            'legal_hold' => $hold,
            'policy' => ['whatsapp_messages_days' => self::MESSAGE_DAYS, 'expired_challenge_days' => self::CHALLENGE_DAYS],
            'whatsapp_messages' => $messages === null ? null : [
                'total' => WhatsAppMessage::query()->count(),
                'eligible_for_minimisation' => $hold ? 0 : $this->messagesPendingMinimization()->count(),
                'already_minimised' => $this->messageCandidates()->whereNull('body')->whereNull('recipient_phone')->whereNull('error_message')->count(),
                'eligible_by_purpose' => $hold ? [] : $this->messagesPendingMinimization()->select('purpose', DB::raw('count(*) as n'))->groupBy('purpose')->pluck('n', 'purpose')->all(),
                'kept_open_states' => WhatsAppMessage::query()->whereIn('status', [WhatsAppMessage::QUEUED, WhatsAppMessage::SENDING, WhatsAppMessage::UNKNOWN])->count(),
                'recommended_action' => 'Owner Option A: minimise (blank body, recipient, provider error text) and KEEP the row so idempotency_key and audit links survive',
            ],
            'identity_otp_challenges' => $challenges === null ? null : [
                'total' => IdentityOtpChallenge::query()->count(),
                'eligible_for_deletion' => $hold ? 0 : (clone $challenges)->count(),
                'protected_as_evidence_or_live_proof' => IdentityOtpChallenge::query()->whereNotNull('consumed_at')->whereNull('invalidated_at')->count(),
                'referenced_by_evidence_rows' => IdentityOtpChallenge::query()
                    ->where(fn (Builder $q) => $q
                        ->whereExists(fn ($s) => $s->select(DB::raw(1))->from('consent_events')->whereColumn('consent_events.otp_challenge_id', 'identity_otp_challenges.id'))
                        ->orWhereExists(fn ($s) => $s->select(DB::raw(1))->from('crm_customer_link_events')->whereColumn('crm_customer_link_events.otp_challenge_id', 'identity_otp_challenges.id')))
                    ->count(),
            ],
            'consent_evidence' => $this->consentEvidenceReport(),
            'preconditions_before_any_execution' => [
                'Owner approves the minimise-vs-delete choice for whatsapp_messages',
                'a verified backup exists',
                'legal hold is OFF',
                'the numbers above are re-read on the day of execution',
            ],
        ];
    }
}
