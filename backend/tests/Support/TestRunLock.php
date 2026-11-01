<?php

namespace Tests\Support;

use PDO;
use RuntimeException;

/**
 * One test runner per test database, enforced by the database server.
 *
 * Why a MySQL named lock (GET_LOCK) instead of a lock file:
 *  - it is bound to a dedicated connection, so MySQL releases it the moment the
 *    owning PHP process exits or crashes — there is no stale lock to clean up
 *    and nobody ever has to delete a lock file by hand;
 *  - it protects every entry point that boots {@see \Tests\TestCase}
 *    (`php artisan test`, `vendor/bin/phpunit`, the serialized runner, IDE
 *    runners), because the lock is taken before the first schema operation;
 *  - ownership is provable (IS_USED_LOCK = our connection id), not inferred
 *    from the existence of a file.
 *
 * Concurrency actors ({@see ConcurrencyHarness} children) never boot a
 * TestCase, so they never ask for the lock and cannot deadlock against the
 * runner that spawned them. A *second runner* does boot a TestCase and is
 * refused immediately (fail-fast, timeout 0).
 *
 * The metadata file written next to the lock is informational only (it tells
 * the refused runner who the holder is); it is never used to decide ownership.
 * It carries no credentials.
 */
final class TestRunLock
{
    public const PREFIX = 'primeclassy:testrun:';

    private static ?self $current = null;

    private ?PDO $pdo = null;

    private bool $held = false;

    private bool $lost = false;

    private string $runId;

    private float $startedAt;

    /** @param array<string, mixed> $connection host, port, username, password, unix_socket */
    public function __construct(
        private readonly string $database,
        private readonly array $connection,
        private readonly ?string $metadataPath = null,
        ?string $runId = null,
        private readonly string $lockName = '',
    ) {
        $this->runId = $runId ?? self::newRunId();
        $this->startedAt = microtime(true);
    }

    /** Process-wide lock for the booted application (idempotent; also a keep-alive). */
    public static function acquireForApplication(object $app): self
    {
        $connectionName = (string) $app['config']->get('database.default');
        $config = (array) $app['config']->get("database.connections.{$connectionName}");
        $database = (string) ($config['database'] ?? '');

        // Never even open a lock connection for a database the guard forbids.
        TestDatabaseGuard::assertSafe($app->environment(), $connectionName, $database);

        if (self::$current === null) {
            $dir = $app->storagePath('framework/testing');
            $lock = new self(
                $database,
                $config,
                $dir.'/run-lock.json',
                getenv('PRIMECLASSY_TEST_RUN_ID') ?: null,
            );
            // Publish only after a successful acquire: a refused runner must refuse EVERY test, not just the first.
            $lock->acquire();
            self::$current = $lock;
            register_shutdown_function(static fn () => self::$current?->release());
        } else {
            // Every test boots an application: prove ownership before it may touch the schema.
            self::$current->assertOwned('boot a test');
        }

        return self::$current;
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    public static function newRunId(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function name(): string
    {
        return $this->lockName !== '' ? $this->lockName : self::PREFIX.$this->database;
    }

    public function isHeld(): bool
    {
        return $this->held;
    }

    /** @throws RuntimeException when another runner owns the lock */
    public function acquire(): void
    {
        if ($this->lost) {
            throw $this->lostException('re-acquire', 'ownership was lost; a lost runner never re-acquires');
        }
        if ($this->held) {
            return;
        }

        $this->pdo = $this->connect();
        $got = (int) $this->scalar('SELECT GET_LOCK(?, 0)', [$this->name()]);

        if ($got !== 1) {
            $holder = $this->describeHolder();
            $this->pdo = null;

            throw new RuntimeException(
                'TEST DATABASE ALREADY IN USE: another test runner owns '.$this->database
                .'. Refusing to touch the schema. '.$holder
                .' Wait for it to finish, or (if it is a crashed run) simply retry — the lock disappears with its process.'
            );
        }

        $this->held = true;
        // A named lock has no lease/TTL: it lives exactly as long as this session. Keep an idle
        // lock connection from being reaped during a multi-hour run (server default is 8 h).
        $this->scalar('SET SESSION wait_timeout = 172800');
        $this->writeMetadata();
    }

    /**
     * THR-04 — proves, right now, that this process still owns the lock: the lock connection
     * is alive AND the server reports it as the holder. Fail-closed and sticky: once ownership
     * was lost (connection killed, timeout, server restart, someone else holding it) this
     * instance never reconnects or re-acquires — between the loss and a re-acquire another
     * runner may already have reset the schema, so continuing could mean two active runners.
     *
     * @throws RuntimeException `TEST RUN LOCK LOST` when ownership cannot be proven
     */
    public function assertOwned(string $operation = 'continue'): void
    {
        if ($this->lost) {
            throw $this->lostException($operation, 'ownership was already lost earlier in this run');
        }
        if (! $this->held || $this->pdo === null) {
            throw $this->lostException($operation, 'this runner never held the lock');
        }

        try {
            $owner = $this->scalar('SELECT IS_USED_LOCK(?)', [$this->name()]);
            $me = $this->scalar('SELECT CONNECTION_ID()');
        } catch (\Throwable $e) {
            $this->markLost();

            throw $this->lostException($operation, 'lock connection is gone ('.substr($e->getMessage(), 0, 120).')');
        }

        if ($owner === null || (string) $owner !== (string) $me) {
            $this->markLost();

            throw $this->lostException($operation, $owner === null ? 'the lock is free (released by the server)' : 'another connection ('.$owner.') holds the lock');
        }
    }

    /** Kept for callers of the old name; it no longer re-acquires (see assertOwned()). */
    public function keepAlive(): void
    {
        $this->assertOwned();
    }

    /** Boundary guard for schema resets and actor launches in this process. */
    public static function assertCurrentOwned(string $operation): void
    {
        if (self::$current === null) {
            throw new RuntimeException('TEST RUN LOCK LOST: no test run lock is held by this process; refusing to '.$operation.'.');
        }

        self::$current->assertOwned($operation);
    }

    /** Test seam: swap the process-wide lock and get the previous one back (always restore it in finally). */
    public static function replaceCurrentForTesting(?self $lock): ?self
    {
        $previous = self::$current;
        self::$current = $lock;

        return $previous;
    }

    public function connectionId(): ?int
    {
        if ($this->pdo === null) {
            return null;
        }

        try {
            return (int) $this->scalar('SELECT CONNECTION_ID()');
        } catch (\Throwable) {
            return null;
        }
    }

    public function isLost(): bool
    {
        return $this->lost;
    }

    private function markLost(): void
    {
        $this->lost = true;
        $this->held = false;
        $this->pdo = null;
    }

    private function lostException(string $operation, string $why): RuntimeException
    {
        return new RuntimeException(sprintf(
            'TEST RUN LOCK LOST: refusing to %s — %s. Run %s no longer has exclusive use of %s; stop this run and start a new one after the preflight in docs/TESTING.md.',
            $operation,
            $why,
            $this->runId,
            $this->database,
        ));
    }

    public function release(): void
    {
        // Never touch the lock when ownership is not proven: RELEASE_LOCK only affects our own
        // session anyway, and a lost/never-held instance has no session to release from.
        if ($this->lost || ! $this->held || $this->pdo === null) {
            return;
        }

        try {
            $this->scalar('SELECT RELEASE_LOCK(?)', [$this->name()]);
        } catch (\Throwable) {
            // connection already gone => the server released the lock itself
        }

        $this->held = false;
        $this->pdo = null;

        if ($this->metadataPath && is_file($this->metadataPath)) {
            $meta = json_decode((string) @file_get_contents($this->metadataPath), true);
            if (is_array($meta) && ($meta['run_id'] ?? null) === $this->runId) {
                @unlink($this->metadataPath);
            }
        }
    }

    /** Non-secret description of the current holder, for the refusal message. */
    private function describeHolder(): string
    {
        $holderConnection = $this->scalar('SELECT IS_USED_LOCK(?)', [$this->name()]);
        $text = 'Holder connection id: '.($holderConnection ?? 'unknown').'.';

        if ($this->metadataPath && is_file($this->metadataPath)) {
            $meta = json_decode((string) @file_get_contents($this->metadataPath), true);
            if (is_array($meta)) {
                $text .= sprintf(
                    ' Holder run %s, pid %s, started %s, command %s, head %s.',
                    $meta['run_id'] ?? '?',
                    $meta['pid'] ?? '?',
                    $meta['started_at'] ?? '?',
                    $meta['command'] ?? '?',
                    $meta['git_head'] ?? '?',
                );
            }
        }

        return $text;
    }

    private function writeMetadata(): void
    {
        if (! $this->metadataPath) {
            return;
        }

        $meta = [
            'state' => 'held',
            'run_id' => $this->runId,
            'pid' => getmypid(),
            'parent_pid' => function_exists('posix_getppid') ? posix_getppid() : null,
            'started_at' => gmdate('c'),
            'command' => self::commandFingerprint(),
            'database' => $this->database,
            'git_head' => self::gitHead(dirname(__DIR__, 3)),
            'lock' => $this->name(),
        ];

        @file_put_contents($this->metadataPath, json_encode($meta, JSON_PRETTY_PRINT));
    }

    /** Script basename + short hash of argv: identifies the runner without echoing arguments. */
    public static function commandFingerprint(): string
    {
        $argv = $_SERVER['argv'] ?? [];

        return basename((string) ($argv[0] ?? 'unknown')).'#'.substr(sha1(implode("\0", $argv)), 0, 10);
    }

    public static function gitHead(string $repoRoot): ?string
    {
        $head = @file_get_contents($repoRoot.'/.git/HEAD');
        if ($head === false) {
            return null;
        }
        $head = trim($head);
        if (! str_starts_with($head, 'ref: ')) {
            return $head;
        }
        $ref = trim(substr($head, 5));
        $sha = @file_get_contents($repoRoot.'/.git/'.$ref);
        if ($sha !== false) {
            return trim($sha);
        }
        foreach (@file($repoRoot.'/.git/packed-refs', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_ends_with($line, ' '.$ref)) {
                return substr($line, 0, 40);
            }
        }

        return null;
    }

    private function connect(): PDO
    {
        $c = $this->connection;
        $socket = (string) ($c['unix_socket'] ?? '');
        $dsn = $socket !== ''
            ? 'mysql:unix_socket='.$socket
            : 'mysql:host='.($c['host'] ?? '127.0.0.1').';port='.($c['port'] ?? 3306);

        // No database selected on purpose: this connection only holds the lock.
        return new PDO($dsn, (string) ($c['username'] ?? ''), (string) ($c['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 10,
        ]);
    }

    /** @param array<int, mixed> $bindings */
    private function scalar(string $sql, array $bindings = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }
}
