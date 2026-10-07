<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * L-001 / L-002 — test-order database-state contamination.
 *
 * The concurrency tests deliberately commit their fixtures: the actor
 * processes run in separate PHP processes and must SEE the branch/product/order
 * rows through their own MySQL connections, which is impossible while the test
 * process keeps them inside an uncommitted RefreshDatabase transaction.
 *
 * Committed fixtures therefore survive the normal per-test transaction
 * rollback, and any manual cleanup inside those tests can only undo
 * "the rows the test remembered to delete". Leftovers (users, products,
 * catalog_skus, stock_movements, activity_logs, orders, ...) then make the NEXT
 * tests fail on unrelated exact-count assertions — the classic "passes alone,
 * fails after another test" signature, and one of the reasons the previous full
 * regression could not be trusted.
 *
 * These helpers therefore rebuild the isolated schema between such tests.
 * The environment and database name are re-validated here (defense in depth,
 * same TestDatabaseGuard as Tests\TestCase, exact equality) so a misconfigured connection can never be dropped.
 */
trait RestoresIsolatedTestDatabase
{
    /**
     * Auto-invoked by Laravel's setUpTraits() before each test: clear any
     * leftover state from a PREVIOUS test file's committed subprocess
     * fixtures so this test starts from a known-clean schema.
     */
    protected function setUpRestoresIsolatedTestDatabase(): void
    {
        $this->stopLingeringTestProcesses();
        $this->restoreIsolatedTestDatabase();
    }

    /**
     * Auto-registered by Laravel's setUpTraits() as a pre-destroy callback:
     * stop lingering child processes and rebuild the schema so committed
     * subprocess fixtures cannot contaminate SUBSEQUENT test files.
     */
    protected function tearDownRestoresIsolatedTestDatabase(): void
    {
        $this->stopLingeringTestProcesses();
        $this->restoreIsolatedTestDatabase();
    }

    /** Rebuild the isolated test schema from zero (migrations + migration-seeded reference data). */
    protected function restoreIsolatedTestDatabase(): void
    {
        // Exact-match guard: only APP_ENV=testing + primeclassy_testing may be rebuilt.
        TestDatabaseGuard::assertApplicationSafe(app());

        // A previous test file may have cached RefreshDatabaseState::$migrated
        // (Laravel then skips migrate:fresh entirely). The isolated rebuild
        // must ALWAYS produce the complete schema — including the newest
        // migrations that may still be pending — so clear the cache flag
        // before running.
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    /** Stops anything the concurrency harness may still own before the schema is rebuilt. */
    protected function stopLingeringTestProcesses(): void
    {
        foreach (glob(storage_path('framework/testing/concurrency/*'), GLOB_ONLYDIR) ?: [] as $directory) {
            foreach (glob($directory.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }

    /** @param array<int, Process> $processes */
    protected function stopProcesses(array $processes): void
    {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(3, SIGTERM);
            }
        }
    }
}
