<?php

namespace App\Console\Commands;

use App\Support\CategoryDuplicateScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** READ-ONLY. Run on DEV/production BEFORE the category unique-key migration is approved. Writes nothing. */
class CategoryUniquePreflight extends Command
{
    protected $signature = 'categories:unique-preflight {--json : machine readable output}';

    protected $description = 'Read-only: list categories that collide under the application normalisation (name key + parent). Exit 1 when conflicts exist.';

    public function handle(): int
    {
        // Belt and braces: the whole scan runs in a READ ONLY transaction, so even a bug cannot write.
        DB::statement('SET SESSION TRANSACTION READ ONLY');
        DB::beginTransaction();
        try {
            $report = CategoryDuplicateScanner::scan();
        } finally {
            DB::rollBack();
            DB::statement('SET SESSION TRANSACTION READ WRITE');
        }

        $conflicts = count($report['groups']) + count($report['empty_keys']);
        if ($this->option('json')) {
            $this->line(json_encode($report + ['conflict_groups' => $conflicts], JSON_PRETTY_PRINT));
        } else {
            $this->info("Categories scanned: {$report['rows']}");
            $this->line('Conflict groups (blocking): '.count($report['groups']));
            foreach ($report['groups'] as $g) {
                $this->line(sprintf('  [%s parent=%s] ', $g['scope'], $g['parent_id'] ?? 'root').collect($g['members'])->map(fn ($m) => "#{$m['category_id']}({$m['reason']}, products={$m['products']}, cms={$m['cms_blocks']}, children={$m['children']})")->implode('  ~  '));
            }
            $this->line('Empty normalised names (blocking): '.count($report['empty_keys']).($report['empty_keys'] ? ' ids='.implode(',', $report['empty_keys']) : ''));
            $this->line('Accent-only look-alikes (informational, NOT blocking): '.count($report['accent_only']));
        }

        return $conflicts === 0 ? self::SUCCESS : self::FAILURE;
    }
}
