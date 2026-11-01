<?php

declare(strict_types=1);

/*
 * Pure helpers for scripts/run-tests-serialized.php (unit-tested by
 * tests/Unit/TestRunnerSupportTest.php). No database access, no secrets.
 */

/**
 * THR-05 — resolve the ONE JUnit path used by PHPUnit, the summary and the classifier.
 * Accepts `--log-junit path` and `--log-junit=path`; adds `--log-junit <default>` when absent.
 *
 * @param  string[]  $arguments
 * @return array{arguments: string[], path: ?string, custom: bool}
 */
function primeclassy_resolve_junit(array $arguments, string $defaultPath): array
{
    $count = count($arguments);
    for ($i = 0; $i < $count; $i++) {
        $argument = $arguments[$i];
        if (str_starts_with($argument, '--log-junit=')) {
            $path = substr($argument, strlen('--log-junit='));

            return ['arguments' => $arguments, 'path' => $path === '' ? null : $path, 'custom' => true];
        }
        if ($argument === '--log-junit') {
            $path = $arguments[$i + 1] ?? null;

            return ['arguments' => $arguments, 'path' => ($path === null || $path === '' || str_starts_with($path, '--')) ? null : $path, 'custom' => true];
        }
    }

    return ['arguments' => array_merge($arguments, ['--log-junit', $defaultPath]), 'path' => $defaultPath, 'custom' => false];
}

/**
 * Classifies failures from a JUnit file. A missing/unreadable/unparsable file is reported as
 * `available: false` with NULL counts — never as "zero failures".
 *
 * @return array<string, mixed>
 */
function primeclassy_classify_junit(?string $junitPath): array
{
    $unavailable = static fn (string $reason): array => [
        'available' => false, 'reason' => $reason,
        'environment' => null, 'migration' => null, 'concurrency' => null, 'test' => null, 'items' => [],
    ];

    if ($junitPath === null || $junitPath === '') {
        return $unavailable('no JUnit path');
    }
    if (! is_file($junitPath) || ! is_readable($junitPath)) {
        return $unavailable('JUnit file missing or unreadable');
    }

    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_file($junitPath);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if ($xml === false) {
        return $unavailable('JUnit file is not valid XML');
    }

    $result = ['available' => true, 'reason' => null, 'environment' => 0, 'migration' => 0, 'concurrency' => 0, 'test' => 0, 'items' => []];

    foreach ($xml->xpath('//testcase[failure or error]') ?: [] as $case) {
        $message = (string) ($case->failure[0] ?? $case->error[0] ?? '');
        $class = (string) ($case['class'] ?? $case['classname'] ?? '');
        if (preg_match('/ALREADY IN USE|TEST RUN LOCK LOST|SQLSTATE\[42S0[12]\]|SQLSTATE\[HY000\].*1824|REFUSED/s', $message)) {
            $kind = 'environment';
        } elseif (preg_match('/migrat|Schema::|information_schema/i', $message)) {
            $kind = 'migration';
        } elseif (preg_match('/Concurrency|SQLSTATE\[40001\]|1213|deadlock|actor/i', $class.' '.$message)) {
            $kind = 'concurrency';
        } else {
            $kind = 'test';
        }
        $result[$kind]++;
        // JUnit puts the test id on the first line; keep a whitespace-collapsed excerpt of the whole text.
        $excerpt = (string) preg_replace('/\s+/', ' ', trim($message));
        $result['items'][] = ['class' => $class, 'test' => (string) $case['name'], 'kind' => $kind, 'message' => substr($excerpt, 0, 400)];
    }

    return $result;
}

/**
 * THR-06 — dirty state from `git status --porcelain=v1 -z`, stdout only.
 * Git warnings go to stderr and are never counted; a failing git is `available: false`
 * (never "clean").
 *
 * @param  string[]  $git  the git command (overridable for tests)
 * @return array{available: bool, dirty: ?bool, paths: ?int, error: ?string}
 */
function primeclassy_git_dirty(string $repository, array $git = ['git']): array
{
    $stderrFile = tempnam(sys_get_temp_dir(), 'pc-git-');
    $command = array_merge($git, ['-C', $repository, 'status', '--porcelain=v1', '-z']);
    $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['file', $stderrFile, 'w']], $pipes);

    if (! is_resource($process)) {
        @unlink($stderrFile);

        return ['available' => false, 'dirty' => null, 'paths' => null, 'error' => 'git could not be started'];
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exit = proc_close($process);
    $stderr = trim((string) @file_get_contents($stderrFile));
    @unlink($stderrFile);

    if ($exit !== 0) {
        return ['available' => false, 'dirty' => null, 'paths' => null, 'error' => 'git exited '.$exit.($stderr !== '' ? ': '.substr($stderr, 0, 160) : '')];
    }

    $records = explode("\0", $stdout);
    $paths = 0;
    $count = count($records);
    for ($i = 0; $i < $count; $i++) {
        $record = $records[$i];
        if ($record === '') {
            continue;
        }
        // "XY <path>": two status letters, one space, a non-empty path.
        if (strlen($record) < 4 || $record[2] !== ' ' || strspn(substr($record, 0, 2), ' MTADRCU?!') !== 2) {
            return ['available' => false, 'dirty' => null, 'paths' => null, 'error' => 'unexpected git porcelain output'];
        }
        $paths++;
        // Renames/copies carry the original path as the next NUL-separated field.
        if ($record[0] === 'R' || $record[0] === 'C' || $record[1] === 'R' || $record[1] === 'C') {
            $i++;
        }
    }

    return ['available' => true, 'dirty' => $paths > 0, 'paths' => $paths, 'error' => null];
}
