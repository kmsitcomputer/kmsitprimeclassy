<?php

// Child used by IsolatedSchemaFingerprintTest: mutates the schema from ANOTHER process/connection.
// It can only create or drop a table named fp_sub_<hex> in primeclassy_testing — nothing else.
//   php schema-probe.php <create|drop> <fp_sub_hex>     credentials via PROBE_DB_* environment only
[, $mode, $table] = $argv + [null, '', ''];

$database = getenv('PROBE_DB_NAME') ?: '';
if ($database !== 'primeclassy_testing') {
    fwrite(STDERR, "REFUSED: schema probe only runs against primeclassy_testing.\n");
    exit(2);
}
if (! in_array($mode, ['create', 'drop'], true) || ! str_starts_with($table, 'fp_sub_') || ! ctype_xdigit(substr($table, 7)) || strlen($table) > 40) {
    fwrite(STDERR, "REFUSED: invalid probe arguments.\n");
    exit(2);
}

require dirname(__DIR__, 3).'/vendor/autoload.php';
// Testing-only endpoint validation before any PDO connection; never a fallback to DEV.
if (! getenv('PROBE_DB_HOST') || ! getenv('PROBE_DB_USER')) {
    throw new \RuntimeException('REFUSED: probe host and username are required.');
}
\Tests\Support\TestDatabaseGuard::assertRunHistorySafe(dirname(__DIR__, 3));
\Tests\Support\TestDatabaseGuard::assertSafe(getenv('APP_ENV') ?: null, 'mysql', 'primeclassy_testing', getenv('PROBE_DB_HOST') ?: null, \Tests\Support\TestDatabaseGuard::workerFromEnvironment());
if ((string) getenv('PROBE_DB_PORT') !== '3306') {
    throw new \RuntimeException('REFUSED: probe requires testing MariaDB port 3306.');
}

$pdo = new PDO(
    'mysql:host='.(getenv('PROBE_DB_HOST') ?: '127.0.0.1').';port='.(getenv('PROBE_DB_PORT') ?: '3306').';dbname='.$database,
    getenv('PROBE_DB_USER') ?: '',
    getenv('PROBE_DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$pdo->exec($mode === 'create'
    ? "CREATE TABLE `{$table}` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY)"
    : "DROP TABLE IF EXISTS `{$table}`");

fwrite(STDOUT, 'DONE');
exit(0);
