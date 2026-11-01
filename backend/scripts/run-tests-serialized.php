<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
chdir($projectRoot);

require __DIR__.'/test-runner-support.php';

$lockDirectory = $projectRoot.'/storage/framework/testing';
$lockPath = $lockDirectory.'/primeclassy-testing.lock';
// Test seam (TestRunnerSupportTest runs this script nested): the secondary file lock may be moved,
// but only into the system temp directory. The authoritative MySQL run lock is never affected.
$lockOverride = getenv('PRIMECLASSY_SERIALIZED_LOCK_PATH');
if (is_string($lockOverride) && $lockOverride !== '') {
    $tmp = rtrim(strtr((string) realpath(sys_get_temp_dir()), DIRECTORY_SEPARATOR, '/'), '/').'/';
    $dir = realpath(dirname($lockOverride));
    if ($dir === false || ! str_starts_with(rtrim(strtr($dir, DIRECTORY_SEPARATOR, '/'), '/').'/', $tmp)) {
        fwrite(STDERR, "REFUSED: PRIMECLASSY_SERIALIZED_LOCK_PATH must be inside the system temp directory.
");
        exit(2);
    }
    $lockPath = $lockOverride;
}

if (! is_dir($lockDirectory) && ! mkdir($lockDirectory, 0775, true) && ! is_dir($lockDirectory)) {
    fwrite(STDERR, "REFUSED: cannot create test lock directory.\n");
    exit(2);
}

$env = [];
$envPath = $projectRoot.'/.env.testing';
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim($value, " \t\n\r\0\x0B\"");
    }
}

$connection = $env['DB_CONNECTION'] ?? getenv('DB_CONNECTION') ?: null;
$database = $env['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: null;

if ($connection !== 'mysql' || $database !== 'primeclassy_testing') {
    fwrite(STDERR, "REFUSED: serialized tests require DB_CONNECTION=mysql and DB_DATABASE=primeclassy_testing.\n");
    exit(2);
}

$lock = fopen($lockPath, 'c');
if ($lock === false) {
    fwrite(STDERR, "REFUSED: cannot open test lock.\n");
    exit(2);
}

if (! flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "TEST DATABASE ALREADY IN USE: another serialized test runner owns the lock.\n");
    fclose($lock);
    exit(3);
}

$arguments = $argv;
array_shift($arguments);
$holdSeconds = 0;
$forwarded = [];
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--hold-seconds=')) {
        $holdSeconds = max(0, (int) substr($argument, strlen('--hold-seconds=')));

        continue;
    }
    $forwarded[] = $argument;
}

// --- P2: run identity, JUnit artifact and summary (no secrets: only counts, names, versions) ---------------
$runId = (static function (): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
})();
putenv('PRIMECLASSY_TEST_RUN_ID='.$runId);
$_ENV['PRIMECLASSY_TEST_RUN_ID'] = $runId;
$runsDirectory = $lockDirectory.'/runs';
if (! is_dir($runsDirectory)) {
    @mkdir($runsDirectory, 0775, true);
}
$startedAt = microtime(true);
// THR-05: one effective JUnit path, shared by PHPUnit, the summary and the classifier.
$junit = primeclassy_resolve_junit($forwarded, $runsDirectory.'/'.$runId.'.junit.xml');
$forwarded = $junit['arguments'];
$junitPath = $junit['path'];

$gitHead = (static function (string $root): ?string {
    $head = @file_get_contents($root.'/.git/HEAD');
    if ($head === false) {
        return null;
    }
    $head = trim($head);
    if (! str_starts_with($head, 'ref: ')) {
        return $head;
    }
    $sha = @file_get_contents($root.'/.git/'.trim(substr($head, 5)));

    return $sha === false ? null : trim($sha);
})(dirname($projectRoot));

try {
    if ($holdSeconds > 0) {
        fwrite(STDOUT, "LOCK ACQUIRED: holding serialized test lock for {$holdSeconds}s.\n");
        $deadline = microtime(true) + $holdSeconds;
        while (microtime(true) < $deadline) {
            usleep(100000);
        }
        $exitCode = 0;
    } else {
        // Run the PHPUnit entry script through the current PHP binary: `vendor/bin/phpunit` has no
        // extension on Windows, so is_executable() is false there and the runner used to refuse to start.
        $phpunit = $projectRoot.'/vendor/phpunit/phpunit/phpunit';
        if (! is_file($phpunit)) {
            fwrite(STDERR, "REFUSED: vendor/phpunit/phpunit/phpunit is unavailable.\n");
            $exitCode = 2;
        } else {
            $command = array_merge([PHP_BINARY, $phpunit], $forwarded);
            $escaped = implode(' ', array_map('escapeshellarg', $command));
            passthru($escaped, $exitCode);
        }
    }
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

if ($holdSeconds === 0) {
    $git = primeclassy_git_dirty(dirname($projectRoot));
    $failures = primeclassy_classify_junit($junitPath);
    $summary = [
        'run_id' => $runId,
        'runner' => 'scripts/run-tests-serialized.php',
        'arguments' => $forwarded,
        'database' => $database,
        'git_head' => $gitHead,
        'git' => $git,
        'php' => PHP_VERSION,
        'started_at' => gmdate('c', (int) $startedAt),
        'ended_at' => gmdate('c'),
        'duration_seconds' => round(microtime(true) - $startedAt, 3),
        'exit_code' => $exitCode ?? 2,
        'junit' => ['path' => $junitPath, 'custom' => $junit['custom'], 'available' => $failures['available']],
        'failures' => $failures,
        'process_stats' => array_map('basename', glob($runsDirectory.'/'.$runId.'.stats-*.json') ?: []),
    ];
    @file_put_contents($runsDirectory.'/'.$runId.'.summary.json', json_encode($summary, JSON_PRETTY_PRINT));
    $counts = $failures['available']
        ? sprintf('environment=%d migration=%d concurrency=%d test=%d', $failures['environment'], $failures['migration'], $failures['concurrency'], $failures['test'])
        : 'UNAVAILABLE ('.$failures['reason'].')';
    fwrite(STDOUT, sprintf("%sRUN %s exit=%d duration=%.1fs failures: %s (storage/framework/testing/runs/%s.summary.json)%s", PHP_EOL, $runId, $summary['exit_code'], $summary['duration_seconds'], $counts, $runId, PHP_EOL));
}

exit($exitCode ?? 2);
