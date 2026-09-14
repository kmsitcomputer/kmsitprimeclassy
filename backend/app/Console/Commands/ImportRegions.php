<?php

namespace App\Console\Commands;

use App\Services\Region\RegionImporter;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Console\Command;

/**
 * Loads/refreshes the province/regency/district/village reference data from
 * the canonical dataset in database/data/regions/ (see SOURCE.md there for
 * provenance). Safe to run repeatedly — upserts by the real Kemendagri code,
 * never duplicates.
 */
class ImportRegions extends Command
{
    use ConfirmableTrait;

    protected $signature = 'regions:import
        {--reset : Delete all existing region rows before importing (children cascade; addresses referencing them are unlinked, not destroyed)}
        {--force : Skip the production confirmation prompt}';

    protected $description = 'Import Indonesia\'s province/regency/district/village reference data.';

    public function handle(RegionImporter $importer): int
    {
        // Mirrors Laravel's own `migrate --force` guard: a destructive
        // --reset must never fire against production unopposed.
        if ($this->option('reset') && ! $this->confirmToProceed(
            'This will delete every existing province/regency/district/village row before re-importing.'
        )) {
            return self::FAILURE;
        }

        $this->info($this->option('reset') ? 'Resetting and importing region data...' : 'Importing region data...');

        $counts = $importer->import(reset: (bool) $this->option('reset'));

        $this->table(
            ['Level', 'Rows'],
            [
                ['Provinces', $counts['provinces']],
                ['Regencies', $counts['regencies']],
                ['Districts', $counts['districts']],
                ['Villages', $counts['villages']],
            ]
        );

        if ($counts['villages_skipped'] > 0) {
            $this->warn("{$counts['villages_skipped']} village row(s) skipped — their district_code has no district row anywhere in the source dataset (a known upstream data gap, see database/data/regions/SOURCE.md). Never inserted with a fabricated parent.");

            foreach ($importer->skippedVillages() as $v) {
                $this->line("  - {$v['code']} {$v['name']} (missing district {$v['district_code']})");
            }
        }

        return self::SUCCESS;
    }
}
