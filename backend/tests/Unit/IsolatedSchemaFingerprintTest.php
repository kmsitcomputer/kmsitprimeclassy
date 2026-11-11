<?php

namespace Tests\Unit;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\IsolatedSchemaFingerprint;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

/**
 * THR-01/02/03 — the fingerprint decides whether a ~43 s rebuild may be skipped, so it must be
 * stable when nothing changed, notice every kind of change, and fail closed.
 *
 * Every mutation targets a uniquely named temporary object (fp_t_<hex>) created and dropped in
 * try/finally; each schema test also proves that after cleanup the fingerprint is back to its
 * original value. Only primeclassy_testing is touched (guarded by Tests\TestCase).
 */
class IsolatedSchemaFingerprintTest extends TestCase
{
    private function fp(): string
    {
        return IsolatedSchemaFingerprint::take(DB::connection());
    }

    private function isMariaDb(): bool
    {
        return IsolatedSchemaFingerprint::engine(DB::connection()) === IsolatedSchemaFingerprint::ENGINE_MARIADB;
    }

    private function tmpName(string $prefix = 'fp_t_'): string
    {
        return $prefix.bin2hex(random_bytes(5));
    }

    private function createTable(string $table): void
    {
        DB::statement("CREATE TABLE `{$table}` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20) NOT NULL DEFAULT '', qty INT NOT NULL DEFAULT 0) ENGINE=InnoDB");
    }

    private function drop(string ...$tables): void
    {
        foreach ($tables as $table) {
            DB::statement("DROP VIEW IF EXISTS `{$table}`");
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    /**
     * Creates a temp table, takes a baseline, runs $mutate (which must change the fingerprint),
     * then drops everything and proves the fingerprint returned to the pre-test value.
     */
    private function assertMutationDetected(callable $mutate, string $label): void
    {
        $original = $this->fp();
        $table = $this->tmpName();
        $extra = [];

        try {
            $this->createTable($table);
            $before = $this->fp();
            $mutate($table, $extra);
            $this->assertNotSame($before, $this->fp(), $label.' must change the fingerprint');
        } finally {
            foreach ($extra as $statement) {
                DB::statement($statement);
            }
            $this->drop($table);
        }

        $this->assertSame($original, $this->fp(), $label.': cleanup must restore the original fingerprint');
    }

    /* ----------------------------------------------------------------- determinism */

    public function test_fingerprint_is_deterministic_when_nothing_changed(): void
    {
        $this->assertSame($this->fp(), $this->fp());
    }

    /* ----------------------------------------------------------------- data */

    public function test_data_insert_is_detected(): void
    {
        $this->assertMutationDetected(fn (string $t) => DB::table($t)->insert(['code' => 'a', 'qty' => 1]), 'insert');
    }

    public function test_data_update_with_same_row_count_is_detected(): void
    {
        $this->assertMutationDetected(function (string $t) {
            DB::table($t)->insert(['code' => 'a', 'qty' => 1]);
            $withA = $this->fp();
            DB::table($t)->update(['qty' => 2]);
            $this->assertNotSame($withA, $this->fp(), 'changed value, same row count');
        }, 'update');
    }

    public function test_data_delete_is_detected(): void
    {
        $original = $this->fp();
        $t = $this->tmpName();
        try {
            $this->createTable($t);
            DB::table($t)->insert([['code' => 'a'], ['code' => 'b']]);
            $two = $this->fp();
            DB::table($t)->where('code', 'b')->delete();
            $this->assertNotSame($two, $this->fp());
        } finally {
            $this->drop($t);
        }
        $this->assertSame($original, $this->fp());
    }

    public function test_advanced_auto_increment_is_detected_even_when_content_is_identical(): void
    {
        $this->assertMutationDetected(function (string $t) {
            $id = DB::table($t)->insertGetId(['code' => 'x']);
            DB::table($t)->where('id', $id)->delete(); // content back to empty, counter moved
        }, 'auto-increment');
    }

    public function test_rolled_back_writes_leave_content_unchanged(): void
    {
        $before = $this->fp();
        DB::beginTransaction();
        try {
            DB::table('cache')->insert(['key' => 'fingerprint-probe', 'value' => 'a', 'expiration' => 1]);
            $this->assertNotSame($before, $this->fp(), 'uncommitted rows on the same connection are seen');
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->fp(), 'cache has no auto-increment, so a rollback restores the fingerprint');
    }

    /* ----------------------------------------------------------------- schema */

    public function test_add_and_drop_table_is_detected(): void
    {
        $original = $this->fp();
        $t = $this->tmpName();
        try {
            $this->createTable($t);
            $this->assertNotSame($original, $this->fp());
        } finally {
            $this->drop($t);
        }
        $this->assertSame($original, $this->fp());
    }

    public function test_add_alter_and_drop_column_are_detected(): void
    {
        $this->assertMutationDetected(function (string $t) {
            DB::statement("ALTER TABLE `{$t}` ADD COLUMN note VARCHAR(10) NULL");
            $added = $this->fp();
            DB::statement("ALTER TABLE `{$t}` MODIFY COLUMN note VARCHAR(30) NULL");
            $altered = $this->fp();
            $this->assertNotSame($added, $altered, 'alter type');
            DB::statement("ALTER TABLE `{$t}` MODIFY COLUMN note VARCHAR(30) NULL DEFAULT 'x'");
            $this->assertNotSame($altered, $default = $this->fp(), 'alter default');
            DB::statement("ALTER TABLE `{$t}` MODIFY COLUMN note VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT 'x'");
            $this->assertNotSame($default, $collated = $this->fp(), 'alter collation');
            DB::statement("ALTER TABLE `{$t}` ADD COLUMN total INT AS (qty * 2) VIRTUAL");
            $this->assertNotSame($collated, $generated = $this->fp(), 'generated column');
            DB::statement("ALTER TABLE `{$t}` MODIFY COLUMN total INT AS (qty * 3) VIRTUAL");
            $this->assertNotSame($generated, $changedExpression = $this->fp(), 'generation expression');
            DB::statement("ALTER TABLE `{$t}` DROP COLUMN total");
            $this->assertNotSame($changedExpression, $this->fp(), 'drop column');
            // `note` stays: the table is dropped by the caller, and the end state must still differ from the
            // start state so the helper's own "must change" check is not vacuous on any engine.
        }, 'columns');
    }

    public function test_add_change_and_drop_index_are_detected(): void
    {
        $this->assertMutationDetected(function (string $t) {
            DB::statement("CREATE INDEX idx_code ON `{$t}` (code)");
            $plain = $this->fp();
            DB::statement("DROP INDEX idx_code ON `{$t}`");
            DB::statement("CREATE INDEX idx_code ON `{$t}` (code(5))");
            $this->assertNotSame($plain, $prefix = $this->fp(), 'prefix length');
            // Same property on both engines: MySQL INVISIBLE == MariaDB IGNORED.
            DB::statement($this->isMariaDb()
                ? "ALTER TABLE `{$t}` ALTER INDEX idx_code IGNORED"
                : "ALTER TABLE `{$t}` ALTER INDEX idx_code INVISIBLE");
            $this->assertNotSame($prefix, $invisible = $this->fp(), 'visibility');
            DB::statement("DROP INDEX idx_code ON `{$t}`");
            DB::statement("CREATE INDEX idx_code ON `{$t}` (qty, code)");
            $this->assertNotSame($invisible, $order = $this->fp(), 'column order / composition');
            DB::statement("DROP INDEX idx_code ON `{$t}`");
            DB::statement("CREATE UNIQUE INDEX idx_code ON `{$t}` (qty, code)");
            $this->assertNotSame($order, $this->fp(), 'uniqueness');
            if (! $this->isMariaDb()) { // functional index keys exist on MySQL only
                DB::statement("CREATE INDEX idx_expr ON `{$t}` ((qty + 1))");
            }
        }, 'indexes');
    }

    public function test_add_and_drop_foreign_key_are_detected(): void
    {
        $original = $this->fp();
        $parent = $this->tmpName();
        $child = $this->tmpName();
        try {
            $this->createTable($parent);
            DB::statement("CREATE TABLE `{$child}` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, parent_id BIGINT UNSIGNED NOT NULL, KEY ix_parent (parent_id))");
            $none = $this->fp();
            $noneParts = IsolatedSchemaFingerprint::parts(DB::connection());
            DB::statement("ALTER TABLE `{$child}` ADD CONSTRAINT fk_{$child} FOREIGN KEY (parent_id) REFERENCES `{$parent}` (id) ON DELETE RESTRICT");
            $restrict = $this->fp();
            $this->assertNotSame($none, $restrict, 'add FK');
            DB::statement("ALTER TABLE `{$child}` DROP FOREIGN KEY fk_{$child}");
            DB::statement("ALTER TABLE `{$child}` ADD CONSTRAINT fk_{$child} FOREIGN KEY (parent_id) REFERENCES `{$parent}` (id) ON DELETE CASCADE");
            $this->assertNotSame($restrict, $this->fp(), 'FK delete rule');
            DB::statement("ALTER TABLE `{$child}` DROP FOREIGN KEY fk_{$child}");
            $this->assertSame($noneParts, IsolatedSchemaFingerprint::parts(DB::connection()), 'drop FK returns to the FK-less state');
        } finally {
            $this->drop($child, $parent);
        }
        $this->assertSame($original, $this->fp());
    }

    public function test_add_change_and_drop_check_constraint_are_detected(): void
    {
        $this->assertMutationDetected(function (string $t) {
            $mariaDb = $this->isMariaDb();
            DB::statement("ALTER TABLE `{$t}` ADD CONSTRAINT chk_{$t} CHECK (qty >= 0)");
            $added = $this->fp();
            DB::statement($mariaDb ? "ALTER TABLE `{$t}` DROP CONSTRAINT chk_{$t}" : "ALTER TABLE `{$t}` DROP CHECK chk_{$t}");
            DB::statement("ALTER TABLE `{$t}` ADD CONSTRAINT chk_{$t} CHECK (qty >= 1)");
            $this->assertNotSame($added, $changed = $this->fp(), 'check clause');
            if (! $mariaDb) { // MariaDB cannot declare a CHECK NOT ENFORCED: it is always enforced
                DB::statement("ALTER TABLE `{$t}` ALTER CHECK chk_{$t} NOT ENFORCED");
                $this->assertNotSame($changed, $this->fp(), 'enforcement');
            }
        }, 'check');
    }

    public function test_add_change_and_drop_trigger_are_detected(): void
    {
        $this->assertMutationDetected(function (string $t, array &$extra) {
            $trigger = 'trg_'.$t;
            $extra[] = "DROP TRIGGER IF EXISTS `{$trigger}`";
            DB::unprepared("CREATE TRIGGER `{$trigger}` BEFORE INSERT ON `{$t}` FOR EACH ROW SET NEW.qty = NEW.qty + 1");
            $added = $this->fp();
            DB::unprepared("DROP TRIGGER `{$trigger}`");
            DB::unprepared("CREATE TRIGGER `{$trigger}` BEFORE INSERT ON `{$t}` FOR EACH ROW SET NEW.qty = NEW.qty + 2");
            $this->assertNotSame($added, $changed = $this->fp(), 'trigger body');
            DB::unprepared("DROP TRIGGER `{$trigger}`");
            $this->assertNotSame($changed, $dropped = $this->fp(), 'drop trigger');
            DB::unprepared("CREATE TRIGGER `{$trigger}` BEFORE UPDATE ON `{$t}` FOR EACH ROW SET NEW.qty = NEW.qty");
            $this->assertNotSame($dropped, $this->fp(), 'trigger timing/event');
        }, 'trigger');
    }

    public function test_add_change_and_drop_view_are_detected(): void
    {
        $this->assertMutationDetected(function (string $t, array &$extra) {
            $view = 'v_'.$t;
            $extra[] = "DROP VIEW IF EXISTS `{$view}`";
            DB::statement("CREATE VIEW `{$view}` AS SELECT id, code FROM `{$t}`");
            $added = $this->fp();
            DB::statement("CREATE OR REPLACE VIEW `{$view}` AS SELECT id, qty FROM `{$t}`");
            $this->assertNotSame($added, $changed = $this->fp(), 'view definition');
            DB::statement("CREATE OR REPLACE VIEW `{$view}` AS SELECT id, qty FROM `{$t}` WITH CHECK OPTION");
            $this->assertNotSame($changed, $this->fp(), 'check option');
        }, 'view');
    }

    /* ----------------------------------------------------------------- concurrency */

    public function test_schema_mutation_by_another_process_is_detected(): void
    {
        $c = (array) config('database.connections.'.config('database.default'));
        $env = [
            'PROBE_DB_HOST' => (string) ($c['host'] ?? '127.0.0.1'),
            'PROBE_DB_PORT' => (string) ($c['port'] ?? 3306),
            'PROBE_DB_USER' => (string) ($c['username'] ?? ''),
            'PROBE_DB_PASS' => (string) ($c['password'] ?? ''),
            'PROBE_DB_NAME' => (string) ($c['database'] ?? ''),
        ];
        $script = dirname(__DIR__).'/Support/bin/schema-probe.php';
        $table = $this->tmpName('fp_sub_');
        $original = $this->fp();

        try {
            $create = new Process([PHP_BINARY, $script, 'create', $table], null, $env, null, 30);
            $create->run();
            $this->assertSame(0, $create->getExitCode(), $create->getErrorOutput());
            $this->assertNotSame($original, $this->fp(), 'a table created by another connection must be seen');
        } finally {
            (new Process([PHP_BINARY, $script, 'drop', $table], null, $env, null, 30))->run();
            $this->drop($table);
        }

        $this->assertSame($original, $this->fp());
    }

    /* ----------------------------------------------------------------- THR-01 checksum validation */

    private const DB = 'primeclassy_testing';

    public function test_valid_checksums_are_accepted_and_sorted(): void
    {
        $this->assertSame(
            ['a' => '0', 'b' => '123'],
            IsolatedSchemaFingerprint::validatedChecksums(self::DB, ['b', 'a'], [
                (object) ['Table' => self::DB.'.b', 'Checksum' => 123],
                (object) ['Table' => self::DB.'.a', 'Checksum' => '0'],
            ]),
        );
    }

    /** @return array<string, array{0: string[], 1: array<int, array<string, mixed>>, 2: string}> */
    public static function invalidChecksumResults(): array
    {
        $db = 'primeclassy_testing';

        return [
            'NULL checksum (missing/unsupported table)' => [['a'], [['Table' => "$db.a", 'Checksum' => null]], 'NULL'],
            'empty result' => [['a'], [], 'expected 1 rows, got 0'],
            'more rows than tables' => [['a'], [['Table' => "$db.a", 'Checksum' => 1], ['Table' => "$db.a", 'Checksum' => 1]], 'expected 1 rows, got 2'],
            'duplicate row hides a missing one' => [['a', 'b'], [['Table' => "$db.a", 'Checksum' => 1], ['Table' => "$db.a", 'Checksum' => 1]], 'duplicate'],
            'wrong table name' => [['a'], [['Table' => "$db.z", 'Checksum' => 1]], 'not requested'],
            'wrong schema' => [['a'], [['Table' => 'primeclassy_dev.a', 'Checksum' => 1]], 'unexpected schema'],
            'non-numeric checksum' => [['a'], [['Table' => "$db.a", 'Checksum' => 'n/a']], 'non-numeric'],
            'negative/garbage checksum' => [['a'], [['Table' => "$db.a", 'Checksum' => '-1']], 'non-numeric'],
            'empty-string checksum' => [['a'], [['Table' => "$db.a", 'Checksum' => '']], 'non-numeric'],
            'malformed row' => [['a'], [['Name' => "$db.a"]], 'malformed'],
            'no tables requested' => [[], [], 'no tables'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidChecksumResults')]
    public function test_untrustworthy_checksum_results_are_rejected(array $tables, array $rows, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        IsolatedSchemaFingerprint::validatedChecksums(self::DB, $tables, array_map(fn ($r) => (object) $r, $rows));
    }

    public function test_real_checksum_of_a_missing_table_is_rejected(): void
    {
        $rows = DB::select('CHECKSUM TABLE `fp_missing_'.bin2hex(random_bytes(4)).'`');

        $this->expectException(RuntimeException::class);
        IsolatedSchemaFingerprint::validatedChecksums(self::DB, ['other'], $rows);
    }

    /* ----------------------------------------------------------------- metadata failures */

    private function fakeConnection(callable $select, string $version = '8.4.11'): ConnectionInterface
    {
        $db = Mockery::mock(ConnectionInterface::class);
        $db->shouldReceive('statement')->andReturn(true);
        $db->shouldReceive('getDatabaseName')->andReturn(self::DB);
        $db->shouldReceive('select')->andReturnUsing(
            static fn (string $sql) => str_starts_with($sql, 'select version()') ? [(object) ['v' => $version]] : $select($sql)
        );

        return $db;
    }

    public function test_a_failing_metadata_query_makes_take_throw(): void
    {
        $db = $this->fakeConnection(function (string $sql) {
            throw new RuntimeException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        });

        $this->expectException(RuntimeException::class);
        IsolatedSchemaFingerprint::take($db);
    }

    public function test_no_visible_tables_is_not_treated_as_an_empty_clean_schema(): void
    {
        $db = $this->fakeConnection(fn (string $sql) => []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no base tables visible');
        IsolatedSchemaFingerprint::take($db);
    }

    public function test_incomplete_column_metadata_makes_take_throw(): void
    {
        $db = $this->fakeConnection(function (string $sql) {
            if (str_contains($sql, 'information_schema.tables')) {
                return [(object) ['TABLE_NAME' => 'a'], (object) ['TABLE_NAME' => 'b']];
            }
            if (str_starts_with($sql, 'CHECKSUM TABLE')) {
                return [(object) ['Table' => self::DB.'.a', 'Checksum' => 1], (object) ['Table' => self::DB.'.b', 'Checksum' => 2]];
            }
            if (str_contains($sql, 'information_schema.columns')) {
                return [(object) ['TABLE_NAME' => 'a', 'COLUMN_NAME' => 'id']]; // nothing for b
            }

            return [];
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('column metadata incomplete');
        IsolatedSchemaFingerprint::take($db);
    }

    public function test_is_clean_fails_closed(): void
    {
        $reason = null;
        $this->assertFalse(IsolatedSchemaFingerprint::isClean(null, fn () => 'x', $reason), 'no baseline');
        $this->assertSame('no baseline in this process', $reason);
        $this->assertFalse(IsolatedSchemaFingerprint::isClean('x', fn () => throw new RuntimeException('boom'), $reason), 'exception');
        $this->assertStringContainsString('fingerprint unavailable', $reason);
        $this->assertFalse(IsolatedSchemaFingerprint::isClean('x', fn () => '', $reason), 'empty');
        $this->assertFalse(IsolatedSchemaFingerprint::isClean('x', fn () => 'y', $reason), 'differs');
        $this->assertTrue(IsolatedSchemaFingerprint::isClean('x', fn () => 'x', $reason), 'equal');
    }

    /* ----------------------------------------------------------------- engine paths (MySQL 8 / MariaDB) */

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function serverVersions(): array
    {
        return [
            'mysql 8.4' => ['8.4.11', IsolatedSchemaFingerprint::ENGINE_MYSQL],
            'mysql 8.0' => ['8.0.36-0ubuntu0.22.04.1', IsolatedSchemaFingerprint::ENGINE_MYSQL],
            'mariadb 10.11' => ['10.11.19-MariaDB-ubu2204', IsolatedSchemaFingerprint::ENGINE_MARIADB],
            'mariadb 10.6' => ['10.6.0-MariaDB', IsolatedSchemaFingerprint::ENGINE_MARIADB],
            'mariadb 11.4' => ['11.4.2-MariaDB', IsolatedSchemaFingerprint::ENGINE_MARIADB],
            'mariadb replication banner' => ['5.5.5-10.11.19-MariaDB', IsolatedSchemaFingerprint::ENGINE_MARIADB],
            'mysql 5.7 unsupported' => ['5.7.44', null],
            'mariadb 10.5 unsupported' => ['10.5.23-MariaDB', null],
            'mariadb 10.3 unsupported' => ['10.3.39-MariaDB', null],
            'unknown server' => ['PostgreSQL 16', null],
            'empty' => ['', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('serverVersions')]
    public function test_engine_is_detected_explicitly_and_unsupported_servers_fail_closed(string $version, ?string $expected): void
    {
        if ($expected === null) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Fingerprint: unsupported');
            IsolatedSchemaFingerprint::engineFromVersion($version);

            return;
        }

        $this->assertSame($expected, IsolatedSchemaFingerprint::engineFromVersion($version));
    }

    public function test_live_engine_matches_the_server_and_is_part_of_the_fingerprint_input(): void
    {
        $engine = IsolatedSchemaFingerprint::engine(DB::connection());
        $this->assertContains($engine, [IsolatedSchemaFingerprint::ENGINE_MYSQL, IsolatedSchemaFingerprint::ENGINE_MARIADB]);
        $this->assertSame($engine, IsolatedSchemaFingerprint::parts(DB::connection())['engine']);
    }

    public function test_mysql_path_uses_the_original_queries_and_mariadb_path_never_selects_mysql_only_columns(): void
    {
        $mysql = IsolatedSchemaFingerprint::mysqlQueries();
        $maria = IsolatedSchemaFingerprint::mariadbQueries();
        $this->assertSame(array_keys($mysql), array_keys($maria), 'both engines cover the same property groups');

        foreach (['SRS_ID', 'EXPRESSION', 'IS_VISIBLE', 'ENFORCED', 'DEFAULT_ENCRYPTION'] as $mysqlOnly) {
            $pattern = '/\\b'.$mysqlOnly.'\\b/'; // whole word: GENERATION_EXPRESSION / PARTITION_EXPRESSION are not EXPRESSION
            $this->assertMatchesRegularExpression($pattern, implode(' ', $mysql), "MySQL path keeps {$mysqlOnly}");
            $this->assertDoesNotMatchRegularExpression($pattern, implode(' ', $maria), "MariaDB path must not select {$mysqlOnly}");
        }
        $this->assertStringContainsString('IGNORED', $maria['indexes'], 'MariaDB index visibility is IGNORED');
        $this->assertStringContainsString("table_type <> 'VIEW'", $maria['tables'], 'system-versioned tables and sequences are not skipped');
    }

    /** Fake server that records every statement the fingerprint issues. */
    private function recordingMetadata(string $version, array &$log): ConnectionInterface
    {
        $db = Mockery::mock(ConnectionInterface::class);
        $db->shouldReceive('statement')->andReturnUsing(function (string $sql) use (&$log) {
            $log[] = $sql;

            return true;
        });
        $db->shouldReceive('getDatabaseName')->andReturn(self::DB);
        $db->shouldReceive('select')->andReturnUsing(function (string $sql) use (&$log, $version) {
            $log[] = $sql;
            if (str_starts_with($sql, 'select version()')) {
                return [(object) ['v' => $version]];
            }
            if (str_contains($sql, 'information_schema.tables')) {
                return [(object) ['TABLE_NAME' => 'geo'], (object) ['TABLE_NAME' => 'plain']];
            }
            if (str_starts_with($sql, 'CHECKSUM TABLE')) {
                return [(object) ['Table' => self::DB.'.geo', 'Checksum' => 1], (object) ['Table' => self::DB.'.plain', 'Checksum' => 2]];
            }
            if (str_contains($sql, 'information_schema.columns')) {
                return [
                    (object) ['TABLE_NAME' => 'geo', 'COLUMN_NAME' => 'p', 'COLUMN_TYPE' => 'point'],
                    (object) ['TABLE_NAME' => 'plain', 'COLUMN_NAME' => 'id', 'COLUMN_TYPE' => 'bigint(20) unsigned'],
                ];
            }
            if (str_starts_with($sql, 'SHOW CREATE TABLE')) {
                return [(object) ['Table' => 'geo', 'Create Table' => 'CREATE TABLE `geo` (`p` point NOT NULL REF_SYSTEM_ID=4326) AUTO_INCREMENT=9']];
            }

            return [];
        });

        return $db;
    }

    public function test_mysql_path_sets_stats_expiry_and_does_not_read_create_table(): void
    {
        $log = [];
        $parts = IsolatedSchemaFingerprint::parts($this->recordingMetadata('8.4.11', $log));

        $this->assertSame('mysql', $parts['engine']);
        $this->assertContains('SET SESSION information_schema_stats_expiry = 0', $log);
        $this->assertArrayNotHasKey('spatial_definitions', $parts);
        $this->assertEmpty(array_filter($log, static fn (string $s) => str_starts_with($s, 'SHOW CREATE TABLE')));
    }

    public function test_mariadb_path_skips_the_mysql_only_statement_and_hashes_spatial_definitions(): void
    {
        $log = [];
        $parts = IsolatedSchemaFingerprint::parts($this->recordingMetadata('10.11.19-MariaDB', $log));

        $this->assertSame('mariadb', $parts['engine']);
        $this->assertNotContains('SET SESSION information_schema_stats_expiry = 0', $log);
        $this->assertSame(['geo'], array_keys($parts['spatial_definitions']), 'only the spatial table is hashed');
        $this->assertSame(
            hash('sha256', 'CREATE TABLE `geo` (`p` point NOT NULL REF_SYSTEM_ID=4326)'),
            $parts['spatial_definitions']['geo'],
            'SRID is part of the hash, the AUTO_INCREMENT counter is not',
        );
    }

    public function test_unsupported_server_makes_take_throw_before_any_metadata_is_hashed(): void
    {
        $db = $this->fakeConnection(fn (string $sql) => [], '5.7.44');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported database server');
        IsolatedSchemaFingerprint::take($db);
    }

    /* ----------------------------------------------------------------- lifecycle (trait decision) */

    /** A host for the real trait whose rebuild is recorded instead of executed (no 43 s migrate:fresh). */
    private function traitHost(bool $alwaysRebuild = false): object
    {
        return new class($alwaysRebuild)
        {
            use RestoresIsolatedTestDatabase;

            /** @var string[] */
            public array $rebuilds = [];

            public function __construct(public bool $isolatedSchemaAlwaysRebuild) {}

            protected function restoreIsolatedTestDatabase(string $phase = 'rebuild'): void
            {
                $this->rebuilds[] = $phase;
            }

            public function check(string $phase): void
            {
                $this->ensureIsolatedSchemaClean($phase);
            }
        };
    }

    private function withBaseline(?string $baseline, callable $body): void
    {
        $saved = IsolatedSchemaFingerprint::$baseline;
        IsolatedSchemaFingerprint::$baseline = $baseline;
        try {
            $body();
        } finally {
            IsolatedSchemaFingerprint::$baseline = $saved;
        }
    }

    public function test_fresh_process_or_aborted_previous_run_always_rebuilds(): void
    {
        // A new PHP process (including one started after an abrupt termination) has no baseline.
        $this->withBaseline(null, function () {
            $host = $this->traitHost();
            $host->check('setup');
            $this->assertSame(['setup'], $host->rebuilds);
        });
    }

    public function test_clean_database_skips_and_dirty_database_rebuilds(): void
    {
        $this->withBaseline($this->fp(), function () {
            $host = $this->traitHost();
            $host->check('setup');
            $this->assertSame([], $host->rebuilds, 'identical to baseline → skip');

            // A failing test that committed a leftover before teardown:
            $t = $this->tmpName();
            try {
                $this->createTable($t);
                $host->check('teardown');
                $this->assertSame(['teardown'], $host->rebuilds, 'leftover → rebuild');
            } finally {
                $this->drop($t);
            }
        });
    }

    public function test_unverifiable_fingerprint_rebuilds(): void
    {
        $this->withBaseline('a-baseline-that-cannot-match', function () {
            $host = $this->traitHost();
            $host->check('setup');
            $this->assertSame(['setup'], $host->rebuilds);
        });
    }

    public function test_ddl_contract_classes_always_rebuild_even_when_clean(): void
    {
        $this->withBaseline($this->fp(), function () {
            $host = $this->traitHost(alwaysRebuild: true);
            $host->check('setup');
            $this->assertSame(['setup'], $host->rebuilds);
        });
    }
}
