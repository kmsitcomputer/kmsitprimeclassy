<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\RetentionPlanner;
use Illuminate\Console\Command;

/** Read-only evidence for the WhatsApp / OTP retention policy. There is intentionally no --execute option. */
class WhatsAppRetentionReport extends Command
{
    protected $signature = 'primeclassy:whatsapp-retention-report';

    protected $description = 'DRY-RUN: count WhatsApp messages / expired OTP challenges the retention policy would affect (changes nothing)';

    public function handle(RetentionPlanner $planner): int
    {
        $this->line(json_encode($planner->report(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
