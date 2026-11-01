<?php

namespace Tests\Support;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * A content-and-structure fingerprint of the isolated test database.
 *
 * Purpose: let {@see RestoresIsolatedTestDatabase} skip a ~43 s `migrate:fresh` when the
 * database is indistinguishable from what the previous rebuild produced. It is a
 * strong equality check, not a proof of byte identity (see docs/TESTING.md §2 for limits).
 *
 * Covered (MySQL 8.4 information_schema, columns verified against 8.4.11):
 *  - schema defaults (charset/collation);
 *  - every base table: engine, row format, collation, create options, comment, AUTO_INCREMENT;
 *  - row CONTENT of every base table (CHECKSUM TABLE, validated fail-closed);
 *  - columns: position, type, nullability, default, extra, charset, collation, generation expression, comment;
 *  - indexes: name, column order, prefix length, type, visibility, expression, uniqueness, collation;
 *  - all constraints with enforcement state; FK column mapping incl. composite positions and rules;
 *  - CHECK clauses; triggers; views; stored routines; events; partitions.
 *
 * Fail-closed: any query failure, missing/duplicate/NULL checksum or incomplete
 * metadata throws. Callers must treat a throw as "dirty" ({@see self::isClean()}).
 * Only hashes leave this class — never table contents.
 */
final class IsolatedSchemaFingerprint
{
    /** Fingerprint taken right after the last migrate:fresh of this PHP process (null = state unknown → rebuild). */
    public static ?string $baseline = null;

    /**
     * The only decision function: skip a rebuild ONLY when a baseline exists and a freshly
     * taken fingerprint equals it. Unknown state, any exception or any mismatch → not clean.
     */
    public static function isClean(?string $baseline, callable $take, ?string &$reason = null): bool
    {
        if ($baseline === null) {
            $reason = 'no baseline in this process';

            return false;
        }

        try {
            $current = $take();
        } catch (\Throwable $e) {
            $reason = 'fingerprint unavailable: '.substr($e->getMessage(), 0, 200);

            return false;
        }

        if (! is_string($current) || $current === '') {
            $reason = 'fingerprint empty';

            return false;
        }

        $clean = hash_equals($baseline, $current);
        $reason = $clean ? 'clean' : 'differs from baseline';

        return $clean;
    }

    public static function take(ConnectionInterface $db): string
    {
        return hash('sha256', (string) json_encode(self::parts($db), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * The canonical metadata the fingerprint hashes (exposed for diagnostics/tests only;
     * contains checksums and metadata, never row contents).
     *
     * @return array<string, mixed>
     */
    public static function parts(ConnectionInterface $db): array
    {
        // information_schema statistics are cached by default (up to 24h) — AUTO_INCREMENT would be stale.
        $db->statement('SET SESSION information_schema_stats_expiry = 0');

        $database = (string) $db->getDatabaseName();
        if ($database === '') {
            throw new RuntimeException('Fingerprint: no database selected.');
        }

        $tables = self::rows($db, "select TABLE_NAME, ENGINE, ROW_FORMAT, TABLE_COLLATION, CREATE_OPTIONS, TABLE_COMMENT, AUTO_INCREMENT
            from information_schema.tables where table_schema = database() and table_type = 'BASE TABLE'");
        if ($tables === []) {
            throw new RuntimeException('Fingerprint: no base tables visible (metadata unavailable or schema empty).');
        }
        $tableNames = array_map(static fn (array $t) => $t['TABLE_NAME'], $tables);
        sort($tableNames, SORT_STRING);

        $quoted = implode(',', array_map(static fn (string $t) => '`'.str_replace('`', '``', $t).'`', $tableNames));
        $checksums = self::validatedChecksums($database, $tableNames, $db->select('CHECKSUM TABLE '.$quoted));

        $columns = self::rows($db, 'select TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA,
            CHARACTER_SET_NAME, COLLATION_NAME, GENERATION_EXPRESSION, COLUMN_COMMENT, SRS_ID
            from information_schema.columns where table_schema = database()');
        $tablesWithColumns = array_unique(array_map(static fn (array $c) => $c['TABLE_NAME'], $columns));
        $missing = array_diff($tableNames, $tablesWithColumns);
        if ($missing !== []) {
            throw new RuntimeException('Fingerprint: column metadata incomplete for '.count($missing).' table(s).');
        }

        $parts = [
            'schema' => self::rows($db, 'select DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME, DEFAULT_ENCRYPTION
                from information_schema.schemata where schema_name = database()'),
            'tables' => $tables,
            'checksums' => $checksums,
            'columns' => $columns,
            'indexes' => self::rows($db, 'select TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, EXPRESSION, SUB_PART, NON_UNIQUE,
                INDEX_TYPE, IS_VISIBLE, COLLATION, NULLABLE, INDEX_COMMENT
                from information_schema.statistics where table_schema = database()'),
            'constraints' => self::rows($db, 'select TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
                from information_schema.table_constraints where table_schema = database()'),
            'key_columns' => self::rows($db, 'select CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, POSITION_IN_UNIQUE_CONSTRAINT,
                REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                from information_schema.key_column_usage where table_schema = database()'),
            'foreign_keys' => self::rows($db, 'select CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME, UNIQUE_CONSTRAINT_NAME, MATCH_OPTION, UPDATE_RULE, DELETE_RULE
                from information_schema.referential_constraints where constraint_schema = database()'),
            'checks' => self::rows($db, "select tc.TABLE_NAME, cc.CONSTRAINT_NAME, cc.CHECK_CLAUSE, tc.ENFORCED
                from information_schema.check_constraints cc
                join information_schema.table_constraints tc
                  on tc.constraint_schema = cc.constraint_schema and tc.constraint_name = cc.constraint_name and tc.constraint_type = 'CHECK'
                where cc.constraint_schema = database()"),
            'triggers' => self::rows($db, 'select TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_ORDER, ACTION_CONDITION, ACTION_STATEMENT,
                ACTION_ORIENTATION, ACTION_TIMING, SQL_MODE, DEFINER, CHARACTER_SET_CLIENT, COLLATION_CONNECTION, DATABASE_COLLATION
                from information_schema.triggers where trigger_schema = database()'),
            'views' => self::rows($db, 'select TABLE_NAME, VIEW_DEFINITION, CHECK_OPTION, IS_UPDATABLE, DEFINER, SECURITY_TYPE, CHARACTER_SET_CLIENT, COLLATION_CONNECTION
                from information_schema.views where table_schema = database()'),
            'routines' => self::rows($db, 'select SPECIFIC_NAME, ROUTINE_TYPE, DATA_TYPE, ROUTINE_DEFINITION, IS_DETERMINISTIC, SQL_DATA_ACCESS, SECURITY_TYPE,
                SQL_MODE, DEFINER, CHARACTER_SET_CLIENT, COLLATION_CONNECTION, DATABASE_COLLATION
                from information_schema.routines where routine_schema = database()'),
            'events' => self::rows($db, 'select EVENT_NAME, DEFINER, TIME_ZONE, EVENT_DEFINITION, EVENT_TYPE, EXECUTE_AT, INTERVAL_VALUE, INTERVAL_FIELD,
                SQL_MODE, STARTS, ENDS, STATUS, ON_COMPLETION
                from information_schema.events where event_schema = database()'),
            'partitions' => self::rows($db, 'select TABLE_NAME, PARTITION_NAME, SUBPARTITION_NAME, PARTITION_ORDINAL_POSITION, PARTITION_METHOD,
                PARTITION_EXPRESSION, PARTITION_DESCRIPTION
                from information_schema.partitions where table_schema = database() and partition_name is not null'),
        ];

        return $parts;
    }

    /**
     * THR-01 — CHECKSUM TABLE must yield exactly one non-NULL numeric checksum per requested table,
     * under the requested name, with no extras and no duplicates. Anything else throws.
     *
     * @param  string[]  $tables  requested base tables
     * @param  array<int, object|array<string, mixed>>  $rows  raw CHECKSUM TABLE result
     * @return array<string, string> table => checksum, sorted by table
     */
    public static function validatedChecksums(string $database, array $tables, array $rows): array
    {
        if ($tables === []) {
            throw new RuntimeException('Checksum: no tables requested.');
        }
        if (count($rows) !== count($tables)) {
            throw new RuntimeException(sprintf('Checksum: expected %d rows, got %d.', count($tables), count($rows)));
        }

        $expected = array_fill_keys($tables, true);
        $result = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            if (! array_key_exists('Table', $row) || ! array_key_exists('Checksum', $row)) {
                throw new RuntimeException('Checksum: malformed row (missing Table/Checksum).');
            }

            $qualified = (string) $row['Table'];
            $prefix = $database.'.';
            if (! str_starts_with($qualified, $prefix)) {
                throw new RuntimeException('Checksum: row for an unexpected schema.');
            }
            $table = substr($qualified, strlen($prefix));

            if (! isset($expected[$table])) {
                throw new RuntimeException('Checksum: row for a table that was not requested ('.$table.').');
            }
            if (isset($result[$table])) {
                throw new RuntimeException('Checksum: duplicate row for '.$table.'.');
            }

            $checksum = $row['Checksum'];
            if ($checksum === null) {
                throw new RuntimeException('Checksum: NULL for '.$table.' (missing table or unsupported).');
            }
            $checksum = (string) $checksum;
            if ($checksum === '' || ! ctype_digit($checksum)) {
                throw new RuntimeException('Checksum: non-numeric value for '.$table.'.');
            }

            $result[$table] = $checksum;
        }

        if (count($result) !== count($expected)) {
            throw new RuntimeException('Checksum: missing rows for '.implode(', ', array_keys(array_diff_key($expected, $result))).'.');
        }

        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Runs a metadata query and canonicalises it: every row becomes an associative array of
     * strings/nulls, and the list is sorted in PHP (never trusting the server's row order).
     *
     * @return array<int, array<string, ?string>>
     */
    private static function rows(ConnectionInterface $db, string $sql): array
    {
        $rows = array_map(static function ($row): array {
            $out = [];
            foreach ((array) $row as $key => $value) {
                $out[strtoupper((string) $key)] = $value === null ? null : (string) $value;
            }
            ksort($out, SORT_STRING);

            return $out;
        }, $db->select($sql));

        usort($rows, static fn (array $a, array $b) => strcmp((string) json_encode($a), (string) json_encode($b)));

        return $rows;
    }
}
