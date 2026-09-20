<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
chdir($projectRoot);

$lockDirectory = $projectRoot.'/storage/framework/testing';
$lockPath = $lockDirectory.'/primeclassy-testing.lock';

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

try {
    if ($holdSeconds > 0) {
        fwrite(STDOUT, "LOCK ACQUIRED: holding serialized test lock for {$holdSeconds}s.\n");
        $deadline = microtime(true) + $holdSeconds;
        while (microtime(true) < $deadline) {
            usleep(100000);
        }
        $exitCode = 0;
    } else {
        $phpunit = $projectRoot.'/vendor/bin/phpunit';
        if (! is_file($phpunit) || ! is_executable($phpunit)) {
            fwrite(STDERR, "REFUSED: vendor/bin/phpunit is unavailable.\n");
            $exitCode = 2;
        } else {
            $command = array_merge([$phpunit], $forwarded);
            $escaped = implode(' ', array_map('escapeshellarg', $command));
            passthru($escaped, $exitCode);
        }
    }
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

exit($exitCode ?? 2);
