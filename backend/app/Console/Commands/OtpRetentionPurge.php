<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\RetentionPlanner;
use Illuminate\Console\Command;

/**
 * Owner policy 2026-10-29: expired OTP challenges older than 30 days and
 * never referenced as consent/link evidence or a live WhatsApp-number proof
 * are purged. DRY-RUN BY DEFAULT, twin flags like the WA minimiser, refused
 * under a legal hold, and NEVER scheduled — the logging scheduler does not
 * run retention jobs (WhatsAppRetentionPlanTest asserts that).
 */
class OtpRetentionPurge extends Command
{
    protected $signature = 'primeclassy:otp-retention-purge {--execute : really purge (default is a dry-run)} {--backup-confirmed : I have a verified backup of the database}';

    protected $description = 'Purge identity_otp_challenges expired >30 days that are not evidence or a live proof; DRY-RUN unless --execute --backup-confirmed';

    public function handle(RetentionPlanner $planner): int
    {
        try {
            $r = $planner->purgeExpiredChallenges((bool) $this->option('execute'), (bool) $this->option('backup-confirmed'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($r, JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
