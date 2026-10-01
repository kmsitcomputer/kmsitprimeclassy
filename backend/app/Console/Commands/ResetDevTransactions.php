<?php

namespace App\Console\Commands;

use App\Services\Maintenance\DevTransactionResetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * DEV MAINTENANCE TOOL ONLY — NOT a production reset procedure.
 *
 * Hard guards (no override flag exists): the app environment must not be production AND the
 * connected database must be exactly `primeclassy_dev`. Requires typing the confirmation phrase.
 */
class ResetDevTransactions extends Command
{
    public const REQUIRED_DATABASE = 'primeclassy_dev';

    public const CONFIRMATION = 'RESET DEV TRANSACTIONS';

    protected $signature = 'primeclassy:reset-dev-transactions {--confirm= : The exact confirmation phrase (otherwise asked interactively)}';

    protected $description = 'DEV ONLY: delete transactional data, keep master data and physical stock, zero reserved counters.';

    public function handle(DevTransactionResetService $service): int
    {
        if (app()->environment('production')) {
            $this->error('REFUSED: this is a DEV-only tool and the environment is production.');

            return self::FAILURE;
        }

        $database = DB::connection()->getDatabaseName();
        if ($database !== self::REQUIRED_DATABASE) {
            $this->error('REFUSED: connected database is not '.self::REQUIRED_DATABASE.'.');

            return self::FAILURE;
        }

        $phrase = $this->option('confirm') ?? $this->ask('Type "'.self::CONFIRMATION.'" to continue');
        if ($phrase !== self::CONFIRMATION) {
            $this->error('REFUSED: confirmation phrase mismatch.');

            return self::FAILURE;
        }

        $report = $service->reset();
        $this->line(json_encode($report, JSON_PRETTY_PRINT));
        $this->info('DEV transactional data reset complete.');

        return self::SUCCESS;
    }
}
