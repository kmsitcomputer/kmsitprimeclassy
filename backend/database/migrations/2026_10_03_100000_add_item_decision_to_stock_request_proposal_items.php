<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Package C / production UAT remediation: per-product (proposal-item) Admin decision.
 *
 * A fulfilment proposal is Gudang's PROPOSED fulfilment of order demand, one line per product. The
 * Admin used to approve/reject the whole proposal, so one click executed every product. The decision
 * now lives on the proposal ITEM (`decision_status`), never on `stock_request_items` — rejecting a
 * proposed fulfilment must not cancel the underlying order demand.
 *
 * Additive: new columns default to `pending`; the proposal header enum gains `partial` (derived:
 * items decided non-uniformly). Backfill uses ONLY existing header evidence (an approved/rejected
 * proposal already recorded who/when) — pending proposals stay pending, nothing is guessed.
 *
 * PARTIAL-EXECUTION SAFETY (added 2026-10-20, DEV recovery RECON-PC-POST-PULL-RECOVERY-001)
 *
 * MySQL DDL is not transactional: a migration can apply half of its statements and then abort, and
 * Laravel then refuses to record it, so the file stays Pending forever and re-running it fails on
 * the first statement that already landed. That is exactly what happened on `primeclassy_dev`:
 * the four decision columns, the composite index, the `partial` enum value and the
 * `decided_by` index were present while the `migrations` ledger had no row for this file, so
 * `php artisan migrate` aborted with errno 1060 "Duplicate column name 'decision_status'".
 *
 * `up()` is therefore written to be SELF-HEALING: every object it owns is checked in
 * `information_schema` first and is created only when it is genuinely absent. The end state is
 * identical to the original statement list, on a fresh database and on a half-migrated one alike:
 *
 *   - the four columns are added only when missing;
 *   - the composite index is added only when missing;
 *   - the `decided_by` foreign key is added only when missing, and only after a preflight proves
 *     it is addable (parent table/column exist, column types agree, no orphan row exists);
 *   - the `partial` enum value is applied only when the current column type is not already the
 *     four-value enum;
 *   - the backfill stays restricted to still-`pending` items, so it is idempotent.
 *
 * The preflight FAILS CLOSED: a conflicting object (same name, different definition) aborts the
 * whole file before any DDL runs, instead of silently "fixing" a schema this migration does not
 * own. Foreign-key validation is never disabled and `foreign_key_checks` is never touched.
 *
 * `down()` mirrors the same guard rules so a rollback of a partially applied file cannot fail on
 * an object that was never created. The rollback of the decision columns is still destructive by
 * design (the columns only exist because of this migration) and is therefore the operator's
 * explicit choice.
 *
 * ON A NON-MySQL/MariaDB DRIVER
 *   The information_schema guards cannot prove anything, so the file refuses to guess and aborts.
 */
return new class extends Migration
{
    private const TABLE_ITEMS = 'stock_request_proposal_items';

    private const TABLE_HEADERS = 'stock_request_proposals';

    private const INDEX_DECISION = 'srpi_proposal_decision_idx';

    private const STATUS_ENUM_FOUR = "enum('pending','partial','approved','rejected')";

    private const STATUS_ENUM_THREE = "enum('pending','approved','rejected')";

    public function up(): void
    {
        $this->addColumnIfMissing(self::TABLE_ITEMS, 'decision_status', function (Blueprint $table) {
            $table->enum('decision_status', ['pending', 'approved', 'rejected'])->default('pending')->after('quantity');
        });

        $this->addColumnIfMissing(self::TABLE_ITEMS, 'decided_by', function (Blueprint $table) {
            $table->foreignId('decided_by')->nullable()->after('decision_status');
        });

        $this->addColumnIfMissing(self::TABLE_ITEMS, 'decided_at', function (Blueprint $table) {
            $table->timestamp('decided_at')->nullable()->after('decided_by');
        });

        $this->addColumnIfMissing(self::TABLE_ITEMS, 'decision_reason', function (Blueprint $table) {
            $table->string('decision_reason', 255)->nullable()->after('decided_at');
        });

        $this->addIndexIfMissing(self::TABLE_ITEMS, self::INDEX_DECISION, ['stock_request_proposal_id', 'decision_status']);

        // The index that carries `decided_by` is created explicitly and before the constraint, so
        // the constraint never has to build one implicitly. The name is the one MySQL itself would
        // use for the auto-generated index, so the resulting index set is identical on a fresh
        // database and on a half-migrated one.
        $this->addIndexIfMissing(self::TABLE_ITEMS, $this->constraintName(self::TABLE_ITEMS, 'decided_by'), ['decided_by']);

        // The foreign key is created separately from the column so that a half-applied file can
        // finish the constraint without touching the column that already exists.
        $this->ensureForeignKey(self::TABLE_ITEMS, 'decided_by', 'users', 'id', 'set null');

        if ($this->columnType(self::TABLE_HEADERS, 'status') !== self::STATUS_ENUM_FOUR) {
            DB::statement("ALTER TABLE stock_request_proposals MODIFY status ENUM('pending','partial','approved','rejected') NOT NULL DEFAULT 'pending'");
        }

        // Backfill from the header's own recorded evidence (idempotent: only still-pending items).
        DB::statement("UPDATE stock_request_proposal_items i JOIN stock_request_proposals p ON p.id = i.stock_request_proposal_id
            SET i.decision_status = 'approved', i.decided_by = p.approved_by, i.decided_at = p.approved_at
            WHERE p.status = 'approved' AND i.decision_status = 'pending'");
        DB::statement("UPDATE stock_request_proposal_items i JOIN stock_request_proposals p ON p.id = i.stock_request_proposal_id
            SET i.decision_status = 'rejected', i.decided_by = p.rejected_by, i.decided_at = p.rejected_at, i.decision_reason = p.rejection_reason
            WHERE p.status = 'rejected' AND i.decision_status = 'pending'");
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE_HEADERS) && $this->columnType(self::TABLE_HEADERS, 'status') === self::STATUS_ENUM_FOUR) {
            DB::table(self::TABLE_HEADERS)->where('status', 'partial')->update(['status' => 'pending']);
            DB::statement("ALTER TABLE stock_request_proposals MODIFY status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
        }

        $this->dropForeignKeyIfPresent(self::TABLE_ITEMS, 'decided_by');
        $this->dropIndexIfPresent(self::TABLE_ITEMS, self::INDEX_DECISION);

        foreach (['decision_reason', 'decided_at', 'decided_by', 'decision_status'] as $column) {
            if (Schema::hasTable(self::TABLE_ITEMS) && Schema::hasColumn(self::TABLE_ITEMS, $column)) {
                Schema::table(self::TABLE_ITEMS, function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    private function addColumnIfMissing(string $table, string $column, \Closure $definition): void
    {
        if (Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, $definition);
    }

    private function addIndexIfMissing(string $table, string $name, array $columns): void
    {
        if ($this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
            $blueprint->index($columns, $name);
        });
    }

    /**
     * Adds the constraint when it is absent, verifies it when it is present, and refuses the whole
     * file — before any DDL — when the schema contradicts what this migration declares.
     */
    private function ensureForeignKey(string $table, string $column, string $parent, string $parentColumn, string $deleteRule): void
    {
        $constraint = $this->constraintName($table, $column);

        if ($this->foreignKeyExists($table, $constraint)) {
            if (! $this->foreignKeyMatches($table, $column, $parent, $parentColumn, $deleteRule)) {
                throw new RuntimeException(
                    "Foreign-key restoration refused; the existing constraint `{$constraint}` has a different definition than this migration declares (expected {$parent}.{$parentColumn} ON DELETE ".strtoupper($deleteRule).'); no statement was executed.'
                );
            }

            return;
        }

        $this->assertForeignKeyAddable($table, $column, $parent, $parentColumn);

        DB::statement(sprintf(
            'alter table `%s` add constraint `%s` foreign key (`%s`) references `%s` (`%s`) on delete %s on update restrict',
            $table,
            $constraint,
            $column,
            $parent,
            $parentColumn,
            $deleteRule
        ));
    }

    private function dropForeignKeyIfPresent(string $table, string $column): void
    {
        $constraint = $this->constraintName($table, $column);

        if ($this->foreignKeyExists($table, $constraint)) {
            DB::statement(sprintf('alter table `%s` drop foreign key `%s`', $table, $constraint));
        }
    }

    private function dropIndexIfPresent(string $table, string $name): void
    {
        if ($this->indexExists($table, $name)) {
            DB::statement(sprintf('alter table `%s` drop index `%s`', $table, $name));
        }
    }

    /** Fails closed with the complete list of blockers and zero mutations. */
    private function assertForeignKeyAddable(string $table, string $column, string $parent, string $parentColumn): void
    {
        $problems = [];

        if (! Schema::hasTable($table)) {
            $problems[] = "child table `{$table}` does not exist";
        } elseif (! Schema::hasColumn($table, $column)) {
            $problems[] = "child column `{$table}`.`{$column}` does not exist";
        }

        if (! Schema::hasTable($parent)) {
            $problems[] = "referenced table `{$parent}` does not exist";
        } elseif (! Schema::hasColumn($parent, $parentColumn)) {
            $problems[] = "referenced column `{$parent}`.`{$parentColumn}` does not exist";
        }

        if ($problems === []) {
            [$childType, $childNullable] = $this->columnDefinition($table, $column);
            [$parentType, $parentNullable] = $this->columnDefinition($parent, $parentColumn);

            if ($childType !== $parentType) {
                $problems[] = "column type `{$childType}` does not match `{$parentType}`";
            }

            if (! $childNullable && $parentNullable) {
                $problems[] = "a NOT NULL column cannot reference a nullable column";
            }

            if (! $this->hasLeadingIndex($table, $column)) {
                $problems[] = "no existing index starts on `{$table}`.`{$column}` — the constraint would have to build one";
            }

            if ($this->hasOrphan($table, $column, $parent, $parentColumn)) {
                $problems[] = "existing rows reference a `{$parent}` row that does not exist — resolve the orphan, never disable foreign-key checks";
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(
                "Foreign-key restoration refused; no constraint was changed:\n  - ".implode("\n  - ", $problems)
            );
        }
    }

    private function constraintName(string $table, string $column): string
    {
        return "{$table}_{$column}_foreign";
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        return DB::selectOne(
            'select 1 as present from information_schema.referential_constraints
             where constraint_schema = ? and table_name = ? and constraint_name = ?',
            [DB::getDatabaseName(), $table, $constraint]
        ) !== null;
    }

    private function foreignKeyMatches(string $table, string $column, string $parent, string $parentColumn, string $deleteRule): bool
    {
        $rows = DB::select(
            'select
        k.COLUMN_NAME as column_name,
        k.REFERENCED_TABLE_NAME as referenced_table_name,
        k.REFERENCED_COLUMN_NAME as referenced_column_name,
        r.DELETE_RULE as delete_rule,
        r.UPDATE_RULE as update_rule
    from information_schema.KEY_COLUMN_USAGE k
    join information_schema.REFERENTIAL_CONSTRAINTS r
      on r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
     and r.TABLE_NAME = k.TABLE_NAME
     and r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
    where k.CONSTRAINT_SCHEMA = ?
      and k.TABLE_NAME = ?
      and k.CONSTRAINT_NAME = ?
      and k.REFERENCED_TABLE_NAME is not null',
            [
                DB::getDatabaseName(),
                $table,
                $this->constraintName($table, $column),
            ]
        );

        return count($rows) === 1
            && (string) $rows[0]->column_name === $column
            && (string) $rows[0]->referenced_table_name === $parent
            && (string) $rows[0]->referenced_column_name === $parentColumn
            && $this->normalizeRestrictRule((string) $rows[0]->delete_rule) === $this->normalizeRestrictRule($deleteRule)
            && $this->normalizeRestrictRule((string) $rows[0]->update_rule) === 'RESTRICT';
    }

    private function normalizeRestrictRule(string $rule): string
    {
        $rule = strtoupper(trim($rule));

        return $rule === 'NO ACTION' ? 'RESTRICT' : $rule;
    }

    /** @return array{0: ?string, 1: bool} [lowercased column type, nullable] */
    private function columnDefinition(string $table, string $column): array
    {
        $row = DB::selectOne(
            'select COLUMN_TYPE as column_type, IS_NULLABLE as is_nullable from information_schema.columns
             where table_schema = ? and table_name = ? and column_name = ?',
            [DB::getDatabaseName(), $table, $column]
        );

        if ($row === null) {
            return [null, false];
        }

        return [strtolower((string) $row->column_type), strtoupper((string) $row->is_nullable) === 'YES'];
    }

    private function columnType(string $table, string $column): ?string
    {
        $row = DB::selectOne(
            'select COLUMN_TYPE as column_type from information_schema.columns
             where table_schema = ? and table_name = ? and column_name = ?',
            [DB::getDatabaseName(), $table, $column]
        );

        return $row === null ? null : strtolower((string) $row->column_type);
    }

    private function indexExists(string $table, string $name): bool
    {
        return DB::selectOne(
            'select 1 as present from information_schema.statistics
             where table_schema = ? and table_name = ? and index_name = ? limit 1',
            [DB::getDatabaseName(), $table, $name]
        ) !== null;
    }

    private function hasLeadingIndex(string $table, string $column): bool
    {
        return DB::selectOne(
            'select 1 as present from information_schema.statistics
             where table_schema = ? and table_name = ? and column_name = ? and seq_in_index = 1 limit 1',
            [DB::getDatabaseName(), $table, $column]
        ) !== null;
    }

    private function hasOrphan(string $table, string $column, string $parent, string $parentColumn): bool
    {
        $row = DB::selectOne(sprintf(
            'select exists(select 1 from `%s` where `%s` is not null and `%s` not in (select `%s` from `%s`) limit 1) as orphan_exists',
            $table,
            $column,
            $column,
            $parentColumn,
            $parent
        ));

        return $row !== null && (int) $row->orphan_exists === 1;
    }
};
