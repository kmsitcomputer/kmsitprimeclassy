<?php

// Minimal child used by TestRunLockTest. It deliberately does NOT boot Laravel or a TestCase,
// exactly like a concurrency actor. Credentials arrive via environment, never via argv/output.
//   php lock-probe.php <lock-name> <mode>   mode: try | hold  (a held probe is killed by the test to simulate a crash)
require dirname(__DIR__, 3).'/vendor/autoload.php';

use Tests\Support\TestRunLock;

[, $name, $mode] = $argv + [null, 'x', 'try'];

// Testing-only endpoint validation before any PDO connection; never a fallback to DEV.
if (! getenv('PROBE_DB_HOST') || ! getenv('PROBE_DB_USER')) {
    throw new \RuntimeException('REFUSED: probe host and username are required.');
}
\Tests\Support\TestDatabaseGuard::assertRunHistorySafe(dirname(__DIR__, 3));
\Tests\Support\TestDatabaseGuard::assertSafe(getenv('APP_ENV') ?: null, 'mysql', 'primeclassy_testing', getenv('PROBE_DB_HOST') ?: null, \Tests\Support\TestDatabaseGuard::workerFromEnvironment());
if ((string) getenv('PROBE_DB_PORT') !== '3306') {
    throw new \RuntimeException('REFUSED: probe requires testing MariaDB port 3306.');
}

$lock = new TestRunLock('primeclassy_testing', [
    'host' => getenv('PROBE_DB_HOST') ?: '127.0.0.1',
    'port' => getenv('PROBE_DB_PORT') ?: 3306,
    'username' => getenv('PROBE_DB_USER') ?: '',
    'password' => getenv('PROBE_DB_PASS') ?: '',
], null, null, $name);

try {
    $lock->acquire();
} catch (RuntimeException $e) {
    fwrite(STDOUT, 'REFUSED');
    exit(3);
}

fwrite(STDOUT, 'ACQUIRED');
fflush(STDOUT);

if ($mode === 'hold') {
    sleep(60);
    $lock->release();
}

exit(0);
