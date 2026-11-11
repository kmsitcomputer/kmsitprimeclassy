<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ConcurrencyHarness;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

class TrueConcurrencyHarnessTest extends TestCase
{
    use RestoresIsolatedTestDatabase;

    public function test_true_mysql_row_lock_harness_proves_overlap_and_cleanup(): void
    {
        $this->assertTrue(class_exists(ConcurrencyHarness::class));

        Schema::create('concurrency_probe', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('value')->default(0);
        });

        DB::table('concurrency_probe')->insert(['id' => 1, 'value' => 0]);

        $harness = new ConcurrencyHarness;
        $report = $harness->run();

        $this->assertTrue($report['different_connections']);
        $this->assertTrue($report['a_locked']);
        $this->assertTrue($report['b_attempted']);
        $this->assertTrue($report['b_blocked_while_a_owned_lock']);
        $this->assertTrue($report['a_released']);
        $this->assertTrue($report['b_completed_after_release']);
        $this->assertSame(3, DB::table('concurrency_probe')->value('value'));
        $this->assertSame([], $harness->staleBarrierPaths());
        $this->assertSame([], $harness->orphanProcesses());
    }

    public function test_barrier_teardown_removes_actor_marker_files_but_only_in_its_own_run_directory(): void
    {
        $harness = new class extends ConcurrencyHarness
        {
            public function ownDirectory(): string
            {
                $this->ensureRuntimeDir();

                return $this->runtimeDir;
            }

            public function teardown(): void
            {
                $this->cleanupBarrierDir();
            }
        };

        $own = $harness->ownDirectory();
        $other = dirname($own).'/concurrency-regression-sibling-'.bin2hex(random_bytes(4));

        try {
            mkdir($other, 0775, true);
            file_put_contents($other.'/keep', 'another run');
            foreach (['sr-a.ready', 'sr-a.ready.capacity-attempt', 'sr-b.ready.first-capacity-lock'] as $file) {
                file_put_contents($own.'/'.$file, 'x');
            }

            $harness->teardown();

            $this->assertDirectoryDoesNotExist($own, 'actor marker files must not leave the run directory behind');
            $this->assertFileExists($other.'/keep', 'another run\'s directory must never be touched');
        } finally {
            @unlink($other.'/keep');
            @rmdir($other);
            foreach (glob($own.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($own);
        }
    }
}
