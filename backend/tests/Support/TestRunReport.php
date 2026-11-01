<?php

namespace Tests\Support;

/**
 * Per-process test-run statistics, written once at shutdown to
 * storage/framework/testing/runs/<run-id>.stats.json (git-ignored directory).
 *
 * It records only counts, timings and non-secret environment facts. It never
 * influences a test: every method is a cheap in-memory update and the writer
 * swallows its own failures.
 */
final class TestRunReport
{
    /** @var array{count:int,seconds:float} */
    private static array $rebuilds = ['count' => 0, 'seconds' => 0.0];

    /** @var array{count:int,seconds:float} */
    private static array $fingerprints = ['count' => 0, 'seconds' => 0.0];

    private static int $rebuildsSkipped = 0;

    /** @var array<string, int> why the fingerprint did not allow a skip (counts only, no data) */
    private static array $dirtyReasons = [];

    /** @var array<string, array{tests:int,seconds:float,rebuilds:int,skipped:int}> */
    private static array $classes = [];

    /** @var array<int, array{phase:string,class:string,seconds:float,error:?string}> */
    private static array $rebuildLog = [];

    private static float $startedAt = 0.0;

    private static bool $registered = false;

    /** Times a schema rebuild and attributes it to the class + phase (setup/teardown). */
    public static function rebuild(string $class, string $phase, callable $rebuild): void
    {
        self::boot();
        $start = microtime(true);
        $error = null;

        try {
            $rebuild();
        } catch (\Throwable $e) {
            $error = substr($e->getMessage(), 0, 300);

            throw $e;
        } finally {
            $seconds = microtime(true) - $start;
            self::$rebuilds['count']++;
            self::$rebuilds['seconds'] += $seconds;
            self::cls($class)['rebuilds']++;
            self::$rebuildLog[] = ['phase' => $phase, 'class' => $class, 'seconds' => round($seconds, 3), 'error' => $error];
        }
    }

    public static function fingerprinted(string $class, float $seconds, bool $skippedRebuild, ?string $dirtyReason = null): void
    {
        self::boot();
        if ($dirtyReason !== null) {
            self::$dirtyReasons[$dirtyReason] = (self::$dirtyReasons[$dirtyReason] ?? 0) + 1;
        }
        self::$fingerprints['count']++;
        self::$fingerprints['seconds'] += $seconds;
        if ($skippedRebuild) {
            self::$rebuildsSkipped++;
            self::cls($class)['skipped']++;
        }
    }

    public static function testFinished(string $class, float $seconds): void
    {
        self::boot();
        $entry = &self::cls($class);
        $entry['tests']++;
        $entry['seconds'] += $seconds;
    }

    /** @return array<string, mixed> */
    public static function snapshot(): array
    {
        return [
            'rebuilds' => ['count' => self::$rebuilds['count'], 'seconds' => round(self::$rebuilds['seconds'], 3)],
            'rebuilds_skipped_clean' => self::$rebuildsSkipped,
            'fingerprint_checks' => ['count' => self::$fingerprints['count'], 'seconds' => round(self::$fingerprints['seconds'], 3)],
            'classes' => array_map(fn ($c) => $c + [], self::$classes),
            'rebuild_log' => self::$rebuildLog,
        ];
    }

    /** @return array{tests:int,seconds:float,rebuilds:int,skipped:int} */
    private static function &cls(string $class): array
    {
        self::$classes[$class] ??= ['tests' => 0, 'seconds' => 0.0, 'rebuilds' => 0, 'skipped' => 0];

        return self::$classes[$class];
    }

    private static function boot(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        self::$startedAt = microtime(true);
        register_shutdown_function([self::class, 'write']);
    }

    public static function write(): void
    {
        try {
            $lock = TestRunLock::current();
            $runId = $lock?->runId() ?? getenv('PRIMECLASSY_TEST_RUN_ID') ?: TestRunLock::newRunId();
            $root = dirname(__DIR__, 2);
            $dir = $root.'/storage/framework/testing/runs';
            if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
                return;
            }

            $classes = self::$classes;
            foreach ($classes as &$c) {
                $c['seconds'] = round($c['seconds'], 3);
            }
            unset($c);

            $data = [
                'run_id' => $runId,
                'pid' => getmypid(),
                'git_head' => TestRunLock::gitHead(dirname($root)),
                'php' => PHP_VERSION,
                'phpunit' => class_exists(\PHPUnit\Runner\Version::class) ? \PHPUnit\Runner\Version::id() : null,
                'laravel' => class_exists(\Illuminate\Foundation\Application::class) ? \Illuminate\Foundation\Application::VERSION : null,
                'database' => TestDatabaseGuard::DATABASE,
                'runner' => TestRunLock::commandFingerprint(),
                'started_at' => gmdate('c', (int) self::$startedAt),
                'ended_at' => gmdate('c'),
                'duration_seconds' => round(microtime(true) - self::$startedAt, 3),
                'rebuilds' => ['count' => self::$rebuilds['count'], 'seconds' => round(self::$rebuilds['seconds'], 3)],
                'rebuilds_skipped_clean' => self::$rebuildsSkipped,
                'fingerprint_dirty_reasons' => self::$dirtyReasons,
                'fingerprint_checks' => ['count' => self::$fingerprints['count'], 'seconds' => round(self::$fingerprints['seconds'], 3)],
                'classes' => $classes,
                'rebuild_log' => self::$rebuildLog,
            ];

            // Distinct file per process so a runner wrapper's own summary is never overwritten.
            @file_put_contents($dir.'/'.$runId.'.stats-'.getmypid().'.json', json_encode($data, JSON_PRETTY_PRINT));
        } catch (\Throwable) {
            // diagnostics must never fail a run
        }
    }
}
