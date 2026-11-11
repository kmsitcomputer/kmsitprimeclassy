<?php

namespace Tests\Unit;

use ArrayAccess;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseGuard;

/**
 * Proves the destructive-test guards fail closed. Deliberately extends the raw
 * PHPUnit TestCase: nothing here boots Laravel or connects to any database, so
 * the forbidden database names are only ever compared as strings.
 */
class TestDatabaseGuardTest extends TestCase
{
    public function test_accepts_only_testing_environment_with_exact_test_database(): void
    {
        TestDatabaseGuard::assertSafe('testing', 'mysql', 'primeclassy_testing');
        TestDatabaseGuard::assertSafe('testing', 'mariadb', 'primeclassy_testing');

        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{0: ?string, 1: mixed, 2: mixed}> */
    public static function forbiddenCombinations(): array
    {
        return [
            'dev database' => ['testing', 'mysql', 'primeclassy_dev'],
            'production database' => ['testing', 'mysql', 'sql_prime'],
            'arbitrary *_testing database' => ['testing', 'mysql', 'foo_testing'],
            'arbitrary *_test database' => ['testing', 'mysql', 'primeclassy_test'],
            'prefixed lookalike' => ['testing', 'mysql', 'primeclassy_testing_2'],
            'case variant' => ['testing', 'mysql', 'PRIMECLASSY_TESTING'],
            'local env with the right database' => ['local', 'mysql', 'primeclassy_testing'],
            'production env with the right database' => ['production', 'mysql', 'primeclassy_testing'],
            'null environment' => [null, 'mysql', 'primeclassy_testing'],
            'sqlite connection' => ['testing', 'sqlite', 'primeclassy_testing'],
            'missing database' => ['testing', 'mysql', null],
            'missing connection' => ['testing', null, 'primeclassy_testing'],
        ];
    }

    #[DataProvider('forbiddenCombinations')]
    public function test_refuses_forbidden_combinations(?string $environment, mixed $connection, mixed $database): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REFUSED');

        TestDatabaseGuard::assertSafe($environment, $connection, $database);
    }

    #[DataProvider('forbiddenCombinations')]
    public function test_application_guard_refuses_forbidden_resolved_configuration(?string $environment, mixed $connection, mixed $database): void
    {
        $app = new class($environment, $connection, $database) implements ArrayAccess
        {
            public Repository $config;

            public function __construct(private ?string $environment, mixed $connection, mixed $database)
            {
                $this->config = new Repository([
                    'database' => [
                        'default' => $connection,
                        'connections' => is_string($connection) ? [$connection => ['database' => $database]] : [],
                    ],
                ]);
            }

            public function environment(): ?string
            {
                return $this->environment;
            }

            public function offsetExists(mixed $offset): bool
            {
                return $offset === 'config';
            }

            public function offsetGet(mixed $offset): mixed
            {
                return $this->config;
            }

            public function offsetSet(mixed $offset, mixed $value): void {}

            public function offsetUnset(mixed $offset): void {}
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REFUSED');

        TestDatabaseGuard::assertApplicationSafe($app);
    }

    public function test_each_parallel_worker_may_use_only_its_own_database_on_an_allowed_host(): void
    {
        foreach (TestDatabaseGuard::WORKER_DATABASES as $worker => $database) {
            foreach (['primeclassy-mariadb', '127.0.0.1', 'localhost'] as $host) {
                TestDatabaseGuard::assertSafe('testing', 'mysql', $database, $host, (string) $worker);
            }
        }
        // Not a worker (unset / '' / '0'): canonical database, with or without a known host (existing behaviour).
        foreach ([null, '', '0'] as $worker) {
            TestDatabaseGuard::assertSafe('testing', 'mysql', 'primeclassy_testing', null, $worker);
            TestDatabaseGuard::assertSafe('testing', 'mariadb', 'primeclassy_testing', 'primeclassy-mariadb', $worker);
        }

        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{0: ?string, 1: mixed, 2: mixed, 3: ?string, 4: ?string}> */
    public static function forbiddenParallelCombinations(): array
    {
        return [
            'worker 1 on worker 2 database' => ['testing', 'mysql', 'primeclassy_testing_w2', 'primeclassy-mariadb', '1'],
            'worker 1 on canonical database' => ['testing', 'mysql', 'primeclassy_testing', 'primeclassy-mariadb', '1'],
            'canonical mode on a worker database' => ['testing', 'mysql', 'primeclassy_testing_w1', 'primeclassy-mariadb', null],
            'zero mode on a worker database' => ['testing', 'mysql', 'primeclassy_testing_w1', 'primeclassy-mariadb', '0'],
            'worker 6 (outside allowlist)' => ['testing', 'mysql', 'primeclassy_testing_w6', 'primeclassy-mariadb', '6'],
            'worker 10' => ['testing', 'mysql', 'primeclassy_testing_w10', 'primeclassy-mariadb', '10'],
            'zero padded worker id' => ['testing', 'mysql', 'primeclassy_testing_w1', 'primeclassy-mariadb', '01'],
            'non numeric worker id' => ['testing', 'mysql', 'primeclassy_testing_w1', 'primeclassy-mariadb', 'w1'],
            'dev database in worker mode' => ['testing', 'mysql', 'primeclassy_db', 'primeclassy-mariadb', '1'],
            'dev database in canonical mode' => ['testing', 'mysql', 'primeclassy_db', 'primeclassy-mariadb', null],
            'production style database in worker mode' => ['testing', 'mysql', 'sql_prime', 'primeclassy-mariadb', '1'],
            'wrong environment in worker mode' => ['local', 'mysql', 'primeclassy_testing_w1', 'primeclassy-mariadb', '1'],
            'worker mode without a host' => ['testing', 'mysql', 'primeclassy_testing_w1', null, '1'],
            'worker mode with empty host' => ['testing', 'mysql', 'primeclassy_testing_w1', '', '1'],
            'remote host in worker mode' => ['testing', 'mysql', 'primeclassy_testing_w1', 'db.example.com', '1'],
            'remote host in canonical mode' => ['testing', 'mysql', 'primeclassy_testing', 'db.example.com', null],
            'production-like host' => ['testing', 'mysql', 'primeclassy_testing', 'prod-mariadb', null],
            'case variant of an allowed host' => ['testing', 'mysql', 'primeclassy_testing_w1', 'PRIMECLASSY-MARIADB', '1'],
        ];
    }

    #[DataProvider('forbiddenParallelCombinations')]
    public function test_refuses_forbidden_parallel_combinations(?string $environment, mixed $connection, mixed $database, ?string $host, ?string $worker): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REFUSED');

        TestDatabaseGuard::assertSafe($environment, $connection, $database, $host, $worker);
    }

    public function test_worker_database_allowlist_is_explicit_and_well_formed(): void
    {
        // PHP normalises decimal-numeric string keys to int, so the keys are compared as ints (strictly, exactly five).
        $this->assertSame([1, 2, 3, 4, 5], array_keys(TestDatabaseGuard::WORKER_DATABASES));
        $this->assertCount(5, TestDatabaseGuard::WORKER_DATABASES);
        foreach (TestDatabaseGuard::WORKER_DATABASES as $worker => $database) {
            $this->assertSame('primeclassy_testing_w'.$worker, $database);
        }
        $this->assertNotContains('primeclassy_db', TestDatabaseGuard::WORKER_DATABASES);
        $this->assertNotContains(TestDatabaseGuard::DATABASE, TestDatabaseGuard::WORKER_DATABASES);
    }

    /** @param array<string, array<string, mixed>> $connections */
    private function fakeApp(array $connections, string $default = 'mysql', ?string $environment = 'testing'): object
    {
        return new class($environment, $default, $connections) implements ArrayAccess
        {
            public Repository $config;

            public \Illuminate\Events\Dispatcher $events;

            public function __construct(private ?string $environment, string $default, array $connections)
            {
                $this->events = new \Illuminate\Events\Dispatcher;
                $this->config = new Repository(['database' => ['default' => $default, 'connections' => $connections]]);
            }

            public function environment(): ?string
            {
                return $this->environment;
            }

            public function offsetExists(mixed $offset): bool
            {
                return $offset === 'config';
            }

            public function offsetGet(mixed $offset): mixed
            {
                return $offset === 'events' ? $this->events : $this->config;
            }

            public function offsetSet(mixed $offset, mixed $value): void {}

            public function offsetUnset(mixed $offset): void {}
        };
    }

    /** @return array<string, mixed> a safe canonical mysql connection (the shape config/database.php produces) */
    private static function safeConnection(array $override = []): array
    {
        return array_merge([
            'driver' => 'mysql', 'url' => null, 'host' => 'primeclassy-mariadb', 'port' => '3306',
            'database' => 'primeclassy_testing', 'username' => 'u', 'password' => '', 'unix_socket' => '',
        ], $override);
    }

    public function test_application_guard_returns_the_validated_effective_configuration(): void
    {
        $effective = TestDatabaseGuard::assertApplicationSafe($this->fakeApp(['mysql' => self::safeConnection()]));

        $this->assertSame('primeclassy_testing', $effective['database']);
        $this->assertSame('primeclassy-mariadb', $effective['host']);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function unsafeEffectiveConnections(): array
    {
        return [
            // F01: raw host/database look safe, the URL replaces them at connection time.
            'url redirects to dev database' => [self::safeConnection(['url' => 'mysql://u:p@primeclassy-mariadb:3306/primeclassy_db'])],
            'url redirects to remote host' => [self::safeConnection(['url' => 'mysql://u:p@db.example.com:3306/primeclassy_testing'])],
            'url with the same safe target is still refused' => [self::safeConnection(['url' => 'mysql://u:p@primeclassy-mariadb:3306/primeclassy_testing'])],
            'host-only url' => [self::safeConnection(['url' => 'mysql://prod-mysql'])],
            'unix socket override' => [self::safeConnection(['unix_socket' => '/run/mysqld/mysqld.sock'])],
            'read/write split' => [self::safeConnection(['read' => ['host' => ['db.example.com']], 'write' => ['host' => ['primeclassy-mariadb']]])],
            'unexpected port' => [self::safeConnection(['port' => '3307'])],
            'no host' => [self::safeConnection(['host' => null])],
            'dev database' => [self::safeConnection(['database' => 'primeclassy_db'])],
            'driver mismatch' => [self::safeConnection(['driver' => 'pgsql'])],
        ];
    }

    #[DataProvider('unsafeEffectiveConnections')]
    public function test_application_guard_refuses_unsafe_effective_connections(array $connection): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REFUSED');

        TestDatabaseGuard::assertApplicationSafe($this->fakeApp(['mysql' => $connection]));
    }

    public function test_application_guard_refuses_a_url_on_any_sibling_connection(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('URL override');

        TestDatabaseGuard::assertApplicationSafe($this->fakeApp([
            'mysql' => self::safeConnection(),
            'mariadb' => self::safeConnection(['driver' => 'mariadb', 'url' => 'mysql://u@db.example.com/primeclassy_db']),
        ]));
    }

    public function test_application_guard_refuses_an_unsafe_switchable_sibling_connection(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REFUSED');

        TestDatabaseGuard::assertApplicationSafe($this->fakeApp([
            'mysql' => self::safeConnection(),
            'mariadb' => self::safeConnection(['driver' => 'mariadb', 'database' => 'primeclassy_db']),
        ]));
    }

    public function test_application_guard_refuses_a_url_environment_variable(): void
    {
        putenv('DB_URL=mysql://u@db.example.com/primeclassy_db');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('DB_URL');
            TestDatabaseGuard::assertApplicationSafe($this->fakeApp(['mysql' => self::safeConnection()]));
        } finally {
            putenv('DB_URL');
        }
    }

    public function test_application_guard_refuses_wrong_environment_and_unknown_default_connection(): void
    {
        foreach ([[['mysql' => self::safeConnection()], 'mysql', 'local'], [['mysql' => self::safeConnection()], 'other', 'testing']] as [$connections, $default, $env]) {
            try {
                TestDatabaseGuard::assertApplicationSafe($this->fakeApp($connections, $default, $env));
                $this->fail('expected REFUSED');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('REFUSED', $e->getMessage());
            }
        }
    }

    public function test_dynamic_non_mysql_connection_is_refused_before_lazy_pdo_opens(): void
    {
        $app = $this->fakeApp(['mysql' => self::safeConnection()]);
        TestDatabaseGuard::installConnectionGuards($app);
        $connection = new \Illuminate\Database\PostgresConnection(null, 'primeclassy_testing', '', [
            'driver' => 'pgsql', 'host' => 'db.example.com', 'port' => '5432', 'database' => 'primeclassy_db', 'username' => 'u',
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REFUSED');
        $app['events']->dispatch(new \Illuminate\Database\Events\ConnectionEstablished($connection));
    }

    public function test_selected_connection_is_revalidated_before_sql_after_late_retargeting(): void
    {
        $app = $this->fakeApp(['mysql' => self::safeConnection()]);
        TestDatabaseGuard::installConnectionGuards($app);
        $connection = new class(null, 'primeclassy_testing', '', self::safeConnection()) extends \Illuminate\Database\MySqlConnection
        {
            public function retarget(): void { $this->config['database'] = 'primeclassy_db'; }
        };
        $previous = \Tests\Support\TestRunLock::replaceCurrentForTesting(new \Tests\Support\TestRunLock('primeclassy_testing', self::safeConnection()));
        try {
            $app['events']->dispatch(new \Illuminate\Database\Events\ConnectionEstablished($connection));
            $connection->retarget();
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('REFUSED');
            $connection->select('SELECT 1'); // guard throws before PDO; this source is not executed during the audit
        } finally {
            \Tests\Support\TestRunLock::replaceCurrentForTesting($previous);
        }
    }

    public function test_guard_uses_exact_equality_not_pattern_matching(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__).'/Support/TestDatabaseGuard.php');
        $this->assertStringNotContainsString('preg_match', $source);

        foreach (['TestCase.php', 'Support/RestoresIsolatedTestDatabase.php', 'Support/ConcurrencyHarness.php'] as $file) {
            $this->assertStringNotContainsString('preg_match', (string) file_get_contents(dirname(__DIR__).'/'.$file), $file.' must not use a database-name regex');
        }
    }

    public function test_concurrency_actor_refuses_non_testing_environment_before_any_query(): void
    {
        // Wrong environment, right (test) database: the actor must abort in its
        // own guard. Only the allowed test database is ever named here.
        $this->assertActorRefuses(['APP_ENV' => 'local', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'primeclassy_testing']);
    }

    public function test_concurrency_actor_refuses_arbitrary_database_before_any_query(): void
    {
        // A non-existent lookalike database: the guard must abort before any
        // connection is attempted (a connection would fail with a different message).
        $this->assertActorRefuses(['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'foo_testing']);
    }

    public function test_unresolved_history_blocks_an_ad_hoc_runner_even_when_run_json_exists(): void
    {
        $backend = sys_get_temp_dir().'/pc-history-'.bin2hex(random_bytes(5));
        $directory = $backend.'/storage/framework/testing/parallel/run-deadbeef0000';
        mkdir($directory, 0775, true);
        file_put_contents($directory.'/run.json', '{"verdict":"PASS"}');
        $previous = getenv('PC_RUN_ID');
        putenv('PC_RUN_ID=');
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('unresolved parallel run');
            TestDatabaseGuard::assertRunHistorySafe($backend);
        } finally {
            $previous === false ? putenv('PC_RUN_ID') : putenv('PC_RUN_ID='.$previous);
            unlink($directory.'/run.json');
            for ($path = $directory; $path !== dirname($backend); $path = dirname($path)) {
                rmdir($path);
            }
        }
    }

    public function test_actor_refuses_url_override_before_provider_boot(): void
    {
        $root = dirname(__DIR__, 2);
        $runtime = sys_get_temp_dir().'/primeclassy-url-guard-'.bin2hex(random_bytes(4));
        $process = new Process([PHP_BINARY, $root.'/.phpunit-concurrency-actor.php', '--role=guard-probe', '--runtime-dir='.$runtime], $root, [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'primeclassy_testing',
            'DB_HOST' => 'primeclassy-mariadb', 'DB_PORT' => '3306', 'DB_USERNAME' => 'u',
            'DB_URL' => 'mysql://u@invalid.invalid/foo_testing',
        ], null, 30);
        $process->run();
        @rmdir($runtime);
        $this->assertNotSame(0, $process->getExitCode());
        $output = $process->getErrorOutput().$process->getOutput();
        $this->assertStringContainsString('REFUSED', $output);
        $this->assertStringNotContainsString('phase=kernel-bootstrapped', $output);
        $this->assertStringNotContainsString('phase=db-connected', $output);
    }

    /** @param array<string, string> $environment */
    private function assertActorRefuses(array $environment): void
    {
        $root = dirname(__DIR__, 2);
        $runtime = sys_get_temp_dir().'/primeclassy-guard-probe-'.bin2hex(random_bytes(4));
        $process = new Process([PHP_BINARY, $root.'/.phpunit-concurrency-actor.php', '--role=guard-probe', '--runtime-dir='.$runtime], $root, $environment, null, 30);
        $process->run();
        @rmdir($runtime);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('REFUSED: concurrency actor requires APP_ENV=testing and database primeclassy_testing', $process->getErrorOutput().$process->getOutput());
    }
}
