<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\RetentionPlanner;
use Illuminate\Console\Command;

/**
 * Owner Option A for `whatsapp_messages` (90 days, then minimise). DRY-RUN BY DEFAULT. Real execution needs BOTH flags and is
 * refused under a legal hold. Never scheduled: nothing in routes/console.php or the kernel runs it.
 */
class WhatsAppRetentionMinimize extends Command
{
    protected $signature = 'primeclassy:whatsapp-retention-minimize {--execute : really minimise (default is a dry-run)} {--backup-confirmed : I have a verified backup of the database}';

    protected $description = 'Minimise WhatsApp messages older than 90 days (keeps idempotency keys); DRY-RUN unless --execute --backup-confirmed';

    public function handle(RetentionPlanner $planner): int
    {
        try {
            $r = $planner->minimizeMessages((bool) $this->option('execute'), (bool) $this->option('backup-confirmed'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($r, JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}