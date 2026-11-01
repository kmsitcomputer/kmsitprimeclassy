<?php

namespace Tests\Unit;

use ArrayAccess;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\ConcurrencyHarness;
use Tests\Support\TestRunLock;
use Tests\TestCase;

/**
 * P0 — proves the one-runner-per-test-database lock. Uses randomly named locks
 * (never the real runner lock for acquisition), so it is safe to run while the
 * owning runner is this very process. Only a connection is opened; no schema is touched.
 */
class TestRunLockTest extends TestCase
{
    /** @return array<string, mixed> */
    private function connection(): array
    {
        return (array) config('database.connections.'.config('database.default'));
    }

    private function lock(string $name): TestRunLock
    {
        return new TestRunLock('primeclassy_testing', $this->connection(), null, null, $name);
    }

    private function probe(string $name, string $mode): Process
    {
        $c = $this->connection();
        $env = [
            'PROBE_DB_HOST' => (string) ($c['host'] ?? '127.0.0.1'),
            'PROBE_DB_PORT' => (string) ($c['port'] ?? 3306),
            'PROBE_DB_USER' => (string) ($c['username'] ?? ''),
            'PROBE_DB_PASS' => (string) ($c['password'] ?? ''),
        ];

        return new Process([PHP_BINARY, __DIR__.'/../Support/bin/lock-probe.php', $name, $mode], dirname(__DIR__, 2), $env, null, 40);
    }

    private function uniqueName(): string
    {
        return 'primeclassy:selftest:'.bin2hex(random_bytes(6));
    }

    public function test_this_runner_already_owns_the_real_test_database_lock(): void
    {
        $current = TestRunLock::current();

        $this->assertNotNull($current);
        $this->assertTrue($current->isHeld());
        $this->assertSame(TestRunLock::PREFIX.'primeclassy_testing', $current->name());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $current->runId());
    }

    public function test_an_independent_second_runner_is_refused_for_the_real_lock(): void
    {
        // A different OS process asking for the REAL lock name is exactly an independent runner.
        $process = $this->probe(TestRunLock::PREFIX.'primeclassy_testing', 'try');
        $process->run();

        $this->assertSame(3, $process->getExitCode());
        $this->assertSame('REFUSED', trim($process->getOutput()));
    }

    public function test_first_runner_acquires_second_is_refused_and_lock_is_released(): void
    {
        $name = $this->uniqueName();
        $first = $this->lock($name);
        $first->acquire();
        $this->assertTrue($first->isHeld());

        $second = $this->lock($name);
        try {
            $second->acquire();
            $this->fail('second runner must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('TEST DATABASE ALREADY IN USE', $e->getMessage());
            $this->assertStringContainsString('primeclassy_testing', $e->getMessage());
        }

        $first->release();
        $second->acquire();
        $this->assertTrue($second->isHeld());
        $second->release();
    }

    public function test_acquire_is_idempotent_and_keep_alive_keeps_ownership(): void
    {
        $lock = $this->lock($this->uniqueName());
        $lock->acquire();
        $lock->acquire();
        $lock->keepAlive();

        $this->assertTrue($lock->isHeld());
        $lock->release();
        $this->assertFalse($lock->isHeld());
    }

    public function test_killed_owner_never_leaves_a_permanent_lock_and_does_not_affect_other_locks(): void
    {
        $crashName = $this->uniqueName();
        $bystander = $this->lock($this->uniqueName());
        $bystander->acquire();

        $owner = $this->probe($crashName, 'hold');
        $owner->start();
        $deadline = microtime(true) + 20;
        while (! str_contains($owner->getOutput(), 'ACQUIRED') && microtime(true) < $deadline) {
            usleep(100000);
        }
        $this->assertStringContainsString('ACQUIRED', $owner->getOutput());

        $refused = $this->probe($crashName, 'try');
        $refused->run();
        $this->assertSame(3, $refused->getExitCode(), 'lock must be exclusive while the owner lives');

        $owner->stop(0); // hard kill: no release() runs, like a crash

        $recovered = false;
        for ($i = 0; $i < 50 && ! $recovered; $i++) {
            $attempt = $this->probe($crashName, 'try');
            $attempt->run();
            $recovered = $attempt->getExitCode() === 0;
            if (! $recovered) {
                usleep(200000);
            }
        }
        $this->assertTrue($recovered, 'the server must free the lock of a crashed owner by itself');
        $this->assertTrue($bystander->isHeld(), 'an unrelated holder is untouched');
        $bystander->keepAlive();
        $bystander->release();
    }

    /** Kills the lock's own MySQL session from another connection: the realistic "connection lost" case. */
    private function killLockConnection(TestRunLock $lock): void
    {
        $id = $lock->connectionId();
        $this->assertNotNull($id);
        DB::statement('KILL '.(int) $id);
    }

    private function assertLost(callable $operation, string $why = ''): void
    {
        try {
            $operation();
            $this->fail('expected TEST RUN LOCK LOST '.$why);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('TEST RUN LOCK LOST', $e->getMessage(), $why);
        }
    }

    public function test_lost_lock_connection_is_detected_fail_closed_and_sticky(): void
    {
        $name = $this->uniqueName();
        $first = $this->lock($name);
        $first->acquire();
        $first->assertOwned();

        $this->killLockConnection($first);

        $this->assertLost(fn () => $first->assertOwned('reset the test schema'), 'after the connection was killed');
        $this->assertTrue($first->isLost());
        $this->assertFalse($first->isHeld());
        $this->assertLost(fn () => $first->acquire(), 'a lost runner never re-acquires');
        $this->assertLost(fn () => $first->keepAlive(), 'keepAlive no longer re-acquires');

        // The server released the lock: a second runner can take it...
        $second = $this->lock($name);
        $second->acquire();
        $second->assertOwned();

        // ...and the first runner can neither use nor release it.
        $first->release();
        $second->assertOwned();
        $this->assertLost(fn () => $first->assertOwned(), 'still lost while another runner owns it');

        $second->release();
    }

    public function test_lock_taken_by_another_session_is_reported_as_lost(): void
    {
        $name = $this->uniqueName();
        $first = $this->lock($name);
        $first->acquire();
        $this->killLockConnection($first);

        $second = $this->lock($name);
        $second->acquire();

        try {
            $first->assertOwned('launch a concurrency actor');
            $this->fail('must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('TEST RUN LOCK LOST: refusing to launch a concurrency actor', $e->getMessage());
        } finally {
            $second->release();
        }
    }

    public function test_runner_that_lost_its_lock_refuses_schema_reset_and_actor_launch(): void
    {
        $lost = $this->lock($this->uniqueName());
        $lost->acquire();
        $this->killLockConnection($lost);

        $real = TestRunLock::replaceCurrentForTesting($lost);
        try {
            // 1. Schema reset: the real trait method must refuse BEFORE migrate:fresh.
            $host = new class
            {
                use \Tests\Support\RestoresIsolatedTestDatabase;

                public function reset(): void
                {
                    $this->restoreIsolatedTestDatabase('test');
                }
            };
            $this->assertLost(fn () => $host->reset(), 'schema reset');

            // 2. New actor batch and single actor launch: refused before any process starts.
            $harness = new class extends ConcurrencyHarness
            {
                public function launch(): void
                {
                    $this->startProcess(['--role=never-started']);
                }

                public function batch(): void
                {
                    $this->ensureRuntimeDir();
                }
            };
            $this->assertLost(fn () => $harness->batch(), 'actor batch');
            $this->assertLost(fn () => $harness->launch(), 'actor launch');

            // 3. Booting the next test.
            $this->assertLost(fn () => TestRunLock::acquireForApplication($this->app), 'next test boot');
        } finally {
            TestRunLock::replaceCurrentForTesting($real);
        }

        // The real runner lock of this process is untouched by all of the above.
        $this->assertTrue(TestRunLock::current()->isHeld());
        TestRunLock::current()->assertOwned();
    }

    public function test_lock_connection_idle_timeout_is_raised_for_long_runs(): void
    {
        $lock = $this->lock($this->uniqueName());
        $lock->acquire();
        try {
            $property = new \ReflectionProperty(TestRunLock::class, 'pdo');
            $pdo = $property->getValue($lock);
            $this->assertSame('172800', (string) $pdo->query('SELECT @@SESSION.wait_timeout')->fetchColumn());
        } finally {
            $lock->release();
        }
    }

    public function test_child_actors_never_boot_a_testcase_and_are_not_blocked_by_the_lock(): void
    {
        $actor = (string) file_get_contents(dirname(__DIR__, 2).'/.phpunit-concurrency-actor.php');
        $this->assertStringNotContainsString('TestRunLock', $actor);
        $this->assertStringNotContainsString('Tests\\TestCase', $actor);

        // The lock sits on its own connection, so ordinary queries are never blocked by it.
        $this->assertSame(1, (int) DB::selectOne('select 1 as one')->one);
    }

    public function test_lock_refuses_a_non_testing_database_before_connecting(): void
    {
        $app = new class($this->connection()) implements ArrayAccess
        {
            public Repository $config;

            public function __construct(array $connection)
            {
                $this->config = new Repository(['database' => [
                    'default' => 'mysql',
                    'connections' => ['mysql' => ['database' => 'primeclassy_dev'] + $connection],
                ]]);
            }

            public function environment(): string
            {
                return 'testing';
            }

            public function storagePath(string $path = ''): string
            {
                return sys_get_temp_dir();
            }

            public function offsetExists(mixed $offset): bool
            {
                return true;
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

        TestRunLock::acquireForApplication($app);
    }
}
