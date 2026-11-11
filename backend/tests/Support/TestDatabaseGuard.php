<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Fail-closed guard for every automated path that may write to a database.
 *
 * Automated tests and concurrency actors may touch only {@see self::DATABASE} under the
 * {@see self::ENVIRONMENT} environment — or, for an Owner-run parallel worker N, exactly
 * its own {@see self::WORKER_DATABASES}[N]. Exact equality only — no pattern matching, so
 * `foo_testing`, `primeclassy_dev`, `primeclassy_db`, `sql_prime` and another worker's database are all refused.
 * The DB host must be an allowlisted local endpoint ({@see self::ALLOWED_HOSTS}) whenever it is known.
 *
 * The check is pure (values in, exception out) so it can be unit-tested
 * without connecting to any forbidden database.
 */
final class TestDatabaseGuard
{
    public const ENVIRONMENT = 'testing';

    public const DATABASE = 'primeclassy_testing';

    /**
     * Owner-run Parallel Full Test: worker N (1..5) may use exactly its own database and nothing else.
     * Unset / '' / '0' means "not a parallel worker" and keeps the canonical database (serial lane, ad-hoc runs).
     * Explicit list on purpose — no prefix/pattern matching.
     */
    public const WORKER_ENV = 'PRIMECLASSY_PARALLEL_WORKER';

    public const WORKER_DATABASES = [
        '1' => 'primeclassy_testing_w1',
        '2' => 'primeclassy_testing_w2',
        '3' => 'primeclassy_testing_w3',
        '4' => 'primeclassy_testing_w4',
        '5' => 'primeclassy_testing_w5',
    ];

    /** Verified local testing endpoints: loopback, and the DEV compose service name of the test MariaDB container. */
    public const ALLOWED_HOSTS = ['127.0.0.1', 'localhost', '::1', 'primeclassy-mariadb'];

    /** The worker id from the process environment (null when not a parallel worker). */
    public static function workerFromEnvironment(): ?string
    {
        $value = getenv(self::WORKER_ENV);
        if ($value === false) {
            $value = $_ENV[self::WORKER_ENV] ?? null;
        }

        return is_string($value) ? $value : null;
    }

    /**
     * Database the given worker id is allowed to use. Fail-closed: any value other than null/''/'0' or '1'..'5' throws.
     */
    public static function expectedDatabase(?string $worker): string
    {
        if ($worker === null || $worker === '' || $worker === '0') {
            return self::DATABASE;
        }

        return self::WORKER_DATABASES[$worker]
            ?? throw new RuntimeException(sprintf('REFUSED: invalid %s %s (allowed: 1-5, or unset for the canonical database).', self::WORKER_ENV, var_export($worker, true)));
    }

    /**
     * @param  ?string  $host  resolved DB host. Mandatory (and allowlisted) in parallel-worker mode; in canonical mode it is
     *                         validated whenever it is known, so existing callers that cannot supply it keep working.
     * @param  ?string  $worker  parallel worker id (see WORKER_ENV)
     */
    public static function assertSafe(?string $environment, mixed $connection, mixed $database, ?string $host = null, ?string $worker = null): void
    {
        $workerMode = $worker !== null && $worker !== '' && $worker !== '0';
        $hostBad = ($workerMode && ($host === null || $host === '')) || (($host !== null && $host !== '') && ! in_array($host, self::ALLOWED_HOSTS, true));

        $expected = self::expectedDatabase($worker);

        if ($environment !== self::ENVIRONMENT
            || ! in_array($connection, ['mysql', 'mariadb'], true)
            || $database !== $expected
            || $hostBad) {
            throw new RuntimeException(sprintf(
                'REFUSED: automated tests may only use APP_ENV=%s with database %s on an allowed local host (got environment: %s, connection: %s, database: %s, host: %s, worker: %s).',
                self::ENVIRONMENT,
                $expected,
                var_export($environment, true),
                var_export($connection, true),
                var_export($database, true),
                var_export($host, true),
                var_export($worker, true),
            ));
        }
    }

    /** The only DB port the verified testing MariaDB listens on. */
    public const ALLOWED_PORTS = ['3306'];

    /** Environment variables that make Laravel build a connection from a URL, bypassing host/database/port. */
    public const URL_ENV_VARIABLES = ['DB_URL', 'DATABASE_URL'];

    /**
     * Resolves the EFFECTIVE configuration of the default connection exactly like
     * Illuminate\Database\DatabaseManager::configuration() does (ConfigurationUrlParser), and refuses every
     * override that could make the real target differ from what is validated. Pure: no DB access.
     *
     * Refused (fail closed): any non-empty connection `url`/DB_URL/DATABASE_URL (a URL silently replaces driver, host, port,
     * database and credentials), read/write host splitting, unix sockets (they replace the host, so the endpoint cannot be
     * verified), a port other than ALLOWED_PORTS, a non-mysql/mariadb driver, and any unsafe sibling mysql/mariadb connection
     * that a test could switch to.
     *
     * @return array<string, mixed> the validated effective connection configuration (host, port, database, username, password, ...)
     */
    public static function assertApplicationSafe(object $app): array
    {
        foreach (self::URL_ENV_VARIABLES as $variable) {
            $value = getenv($variable);
            if (is_string($value) && $value !== '') {
                throw new RuntimeException(sprintf('REFUSED: %s is set; a connection URL overrides host/database and cannot be verified.', $variable));
            }
        }

        $default = $app['config']->get('database.default');
        if (! is_string($default) || $default === '') {
            throw new RuntimeException('REFUSED: no default database connection is configured.');
        }

        $connections = $app['config']->get('database.connections');
        $connections = is_array($connections) ? $connections : [];
        foreach ($connections as $name => $raw) {
            if (is_array($raw) && ! empty($raw['url'])) {
                throw new RuntimeException(sprintf('REFUSED: database connection [%s] carries a URL override (DB_URL); it replaces host/database and is not allowed in tests.', $name));
            }
        }

        $effective = self::effectiveConfig($connections[$default] ?? null, $default);
        self::assertEffectiveConfig($app->environment(), $default, $effective);

        // Connection switching: every other mysql/mariadb connection a test could select must be equally safe.
        foreach ($connections as $name => $raw) {
            if ($name === $default || ! is_array($raw) || ! in_array($raw['driver'] ?? null, ['mysql', 'mariadb'], true)) {
                continue;
            }
            self::assertEffectiveConfig($app->environment(), (string) $name, self::effectiveConfig($raw, (string) $name));
        }

        return $effective;
    }

    /** @return array<string, mixed> */
    private static function effectiveConfig(mixed $raw, string $name): array
    {
        if (! is_array($raw)) {
            throw new RuntimeException(sprintf('REFUSED: database connection [%s] is not configured.', $name));
        }

        return (new \Illuminate\Support\ConfigurationUrlParser)->parseConfiguration($raw);
    }

    /** @param array<string, mixed> $effective */
    private static function assertEffectiveConfig(?string $environment, string $connection, array $effective): void
    {
        if (isset($effective['read']) || isset($effective['write']) || isset($effective['sticky'])) {
            throw new RuntimeException(sprintf('REFUSED: connection [%s] uses read/write splitting; the effective target cannot be verified.', $connection));
        }
        if (! empty($effective['unix_socket'])) {
            throw new RuntimeException(sprintf('REFUSED: connection [%s] uses a unix socket (DB_SOCKET), which replaces the verified host.', $connection));
        }
        $driver = $effective['driver'] ?? null;
        if (! in_array($driver, ['mysql', 'mariadb'], true) || $driver !== $connection) {
            throw new RuntimeException(sprintf('REFUSED: connection [%s] resolves to driver %s.', $connection, var_export($driver, true)));
        }
        if (! is_string($effective['username'] ?? null) || $effective['username'] === '') {
            throw new RuntimeException('REFUSED: testing database username is required.');
        }
        $host = $effective['host'] ?? null;
        if (! is_string($host) || $host === '') {
            throw new RuntimeException(sprintf('REFUSED: connection [%s] has no resolvable host.', $connection));
        }
        if (! in_array((string) ($effective['port'] ?? ''), self::ALLOWED_PORTS, true)) {
            throw new RuntimeException(sprintf('REFUSED: connection [%s] port %s is not allowed.', $connection, var_export($effective['port'] ?? null, true)));
        }

        self::assertSafe($environment, $connection, $effective['database'] ?? null, $host, self::workerFromEnvironment());
    }

    /** Do not let an ad-hoc runner reset a database while an older parallel run may have orphan actors. */
    public static function assertRunHistorySafe(string $backend): void
    {
        $root = $backend.'/storage/framework/testing/parallel';
        if (is_link($root)) {
            throw new RuntimeException('REFUSED: parallel run history must not be a symlink.');
        }
        if (! file_exists($root)) {
            return;
        }
        $entries = is_dir($root) && is_readable($root) ? @scandir($root) : false;
        if ($entries === false) {
            throw new RuntimeException('REFUSED: parallel run history cannot be inspected.');
        }
        $current = getenv('PC_RUN_ID') ?: '';
        foreach ($entries as $entry) {
            $directory = $root.'/'.$entry;
            if (! str_starts_with($entry, 'run-') || ! is_dir($directory)) {
                continue;
            }
            if ($current !== '' && basename($directory) === 'run-'.$current) {
                continue; // this Owner-authorized run holds its lease; its workers share the run id
            }
            $record = is_file($directory.'/STOPPED.json') ? json_decode((string) file_get_contents($directory.'/STOPPED.json'), true) : null;
            if (! is_array($record) || ($record['verified_stopped'] ?? null) !== true || 'run-'.($record['run_id'] ?? '') !== basename($directory)) {
                throw new RuntimeException('REFUSED: unresolved parallel run; orphan-process recovery is required before testing.');
            }
        }
    }

    /** Validate newly selected/dynamic connections before their lazy PDO is opened, and again before SQL. */
    public static function installConnectionGuards(object $app, ?array $actorTarget = null): void
    {
        // The environment was validated by assertApplicationSafe() at boot. A test may legitimately flip
        // the in-memory app environment later (e.g. to prove a command's own production guard); the
        // connection TARGET (driver/host/port/database/user/lock) is still verified on every query below.
        $bootEnvironment = (string) $app->environment();
        $app['events']->listen(\Illuminate\Database\Events\ConnectionEstablished::class, function ($event) use ($app, $actorTarget, $bootEnvironment): void {
            $check = static function () use ($app, $event, $actorTarget, $bootEnvironment): void {
                $config = $event->connection->getConfig();
                if (! empty($config['url'])) {
                    throw new RuntimeException('REFUSED: connection URL override.');
                }
                self::assertEffectiveConfig($bootEnvironment, (string) ($config['driver'] ?? ''), $config);
                if ($actorTarget !== null) {
                    // Actors share the parent's database but MUST NOT acquire its runner lock.
                    foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
                        if ((string) ($config[$key] ?? '') !== (string) ($actorTarget[$key] ?? '')) {
                            throw new RuntimeException('REFUSED: actor connection differs from its validated bootstrap target.');
                        }
                    }
                    return;
                }
                $lock = TestRunLock::current();
                if ($lock === null) {
                    throw new RuntimeException('REFUSED: selected connection has no test run lock.');
                }
                $lock->assertConnectionTarget($config);
            };
            $check();
            $event->connection->beforeExecuting($check);
        });
    }
}
