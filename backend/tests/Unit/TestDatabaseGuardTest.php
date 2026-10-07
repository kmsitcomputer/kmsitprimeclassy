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
