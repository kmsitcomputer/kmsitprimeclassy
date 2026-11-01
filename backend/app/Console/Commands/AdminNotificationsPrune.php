<?php

namespace App\Console\Commands;

use App\Models\AdminNotification;
use Illuminate\Console\Command;

/**
 * Retention for the operator in-app centre: removes READ notifications older than --days (default 90).
 * Dry-run by default; `--execute` deletes. Unread rows are never removed. NOT scheduled: running it on production is an
 * Owner-authorised step like every other retention command.
 */
class AdminNotificationsPrune extends Command
{
    protected $signature = 'primeclassy:admin-notifications-prune {--days=90 : Keep read notifications younger than this} {--execute : Actually delete (default is a dry run)}';

    protected $description = 'Dry-run (default) or delete read in-app operator notifications older than N days';

    public function handle(): int
    {
        if (! AdminNotification::tableReady()) {
            $this->warn('admin_notifications table not present (migration pending); nothing to do.');

            return self::SUCCESS;
        }
        $days = max(7, (int) $this->option('days'));
        $q = AdminNotification::query()->whereNotNull('read_at')->where('read_at', '<', now()->subDays($days));
        $count = (clone $q)->count();

        if (! $this->option('execute')) {
            $this->info("DRY RUN: {$count} read notification(s) older than {$days} day(s) would be deleted.");

            return self::SUCCESS;
        }
        $deleted = $q->delete();
        $this->info("Deleted {$deleted} read notification(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
