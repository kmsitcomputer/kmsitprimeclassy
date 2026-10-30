<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-line fulfilment-change proposals: a request to change the fulfilled quantity (and/or the
 * requested delivery date) of an existing order line, decided per proposal.
 *
 * PARTIAL-EXECUTION SAFETY (added 2026-10-20, DEV recovery RECON-PC-POST-PULL-RECOVERY-001)
 *
 * MySQL DDL is not transactional, so this file can create the table and abort before the
 * constraints are attached — and Laravel then never records it, leaving the file Pending with a
 * table that carries the columns and the indexes but none of the five foreign keys. That is the
 * exact state observed on `primeclassy_dev` (table created 2026-10-04 with all indexes, zero
 * foreign keys, no ledger row), and re-running the original statement list would abort with
 * errno 1050 "Table already exists".
 *
 * `up()` is therefore SELF-HEALING and converges on exactly the schema the original statement list
 * produced on a clean database:
 *
 *   - the table is created only when it is absent, using the original column definitions;
 *   - when the table is already present, every column it owns is checked and the missing ones are
 *     added (a NOT NULL column is only added to an empty table — otherwise the file fails closed
 *     instead of inventing a default);
 *   - the two explicit indexes are added only when missing;
 *   - the five foreign keys are added only when missing, each one preceded by a preflight that
 *     proves it is addable (parent table/column exist, column types agree, no orphan row exists)
 *     and each one verified against the canonical definition when it is already present.
 *
 * The preflight FAILS CLOSED: a conflicting object aborts the file before any DDL runs.
 * Foreign-key validation is never disabled and `foreign_key_checks` is never touched.
 *
 * The file stays additive and data-preserving: it never rewrites, cancels or re-decides an
 * existing fulfilment, and it changes no existing fulfilment behaviour.
 */
return new class extends Migration
{
    private const TABLE = 'order_fulfillment_change_proposals';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table) {
                $table->id();
                $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
                $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
                $table->unsignedInteger('base_fulfilled_quantity');
                $table->unsignedInteger('proposed_fulfilled_quantity');
                $table->date('base_requested_delivery_date')->nullable();
                $table->date('proposed_requested_delivery_date')->nullable();
                $table->string('reason', 255)->nullable();
                $table->string('status', 20)->default('pending');
                $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('decided_at')->nullable();
                $table->string('decision_reason', 255)->nullable();
                $table->timestamps();

                $table->index(['agent_id', 'status']);
                $table->index(['order_id', 'order_item_id', 'status'], 'ofcp_order_item_status_idx');
            });
        }

        $this->assertColumnsCanBeCompleted();

        $this->addColumnIfMissing('agent_id', function (Blueprint $table) {
            $table->foreignId('agent_id');
        });
        $this->addColumnIfMissing('order_id', function (Blueprint $table) {
            $table->foreignId('order_id');
        });
        $this->addColumnIfMissing('order_item_id', function (Blueprint $table) {
            $table->foreignId('order_item_id');
        });
        $this->addColumnIfMissing('proposed_by', function (Blueprint $table) {
            $table->foreignId('proposed_by');
        });
        $this->addColumnIfMissing('base_fulfilled_quantity', function (Blueprint $table) {
            $table->unsignedInteger('base_fulfilled_quantity');
        });
        $this->addColumnIfMissing('proposed_fulfilled_quantity', function (Blueprint $table) {
            $table->unsignedInteger('proposed_fulfilled_quantity');
        });
        $this->addColumnIfMissing('base_requested_delivery_date', function (Blueprint $table) {
            $table->date('base_requested_delivery_date')->nullable();
        });
        $this->addColumnIfMissing('proposed_requested_delivery_date', function (Blueprint $table) {
            $table->date('proposed_requested_delivery_date')->nullable();
        });
        $this->addColumnIfMissing('reason', function (Blueprint $table) {
            $table->string('reason', 255)->nullable();
        });
        $this->addColumnIfMissing('status', function (Blueprint $table) {
            $table->string('status', 20)->default('pending');
        });
        $this->addColumnIfMissing('decided_by', function (Blueprint $table) {
            $table->foreignId('decided_by')->nullable();
        });
        $this->addColumnIfMissing('decided_at', function (Blueprint $table) {
            $table->timestamp('decided_at')->nullable();
        });
        $this->addColumnIfMissing('decision_reason', function (Blueprint $table) {
            $table->string('decision_reason', 255)->nullable();
        });
        $this->addColumnIfMissing('created_at', function (Blueprint $table) {
            $table->timestamp('created_at')->nullable();
        });
        $this->addColumnIfMissing('updated_at', function (Blueprint $table) {
            $table->timestamp('updated_at')->nullable();
        });

        $this->addIndexIfMissing('order_fulfillment_change_proposals_agent_id_status_index', ['agent_id', 'status']);
        $this->addIndexIfMissing('ofcp_order_item_status_idx', ['order_id', 'order_item_id', 'status']);

        $this->ensureForeignKey('agent_id', 'users', 'id', 'restrict');
        $this->ensureForeignKey('order_id', 'orders', 'id', 'cascade');
        $this->ensureForeignKey('order_item_id', 'order_items', 'id', 'cascade');
        $this->ensureForeignKey('proposed_by', 'users', 'id', 'restrict');
        $this->ensureForeignKey('decided_by', 'users', 'id', 'set null');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    /**
     * A NOT NULL column can only be added to an empty table without inventing a value. The file
     * refuses that situation instead of guessing.
     */
    private function assertColumnsCanBeCompleted(): void
    {
        $required = [
            'agent_id' => true,
            'order_id' => true,
            'order_item_id' => true,
            'proposed_by' => true,
            'base_fulfilled_quantity' => true,
            'proposed_fulfilled_quantity' => true,
            'status' => true,
        ];

        $missing = array_keys(array_filter(
            $required,
            fn (bool $notNull, string $column) => ! Schema::hasColumn(self::TABLE, $column),
            ARRAY_FILTER_USE_BOTH
        ));

        if ($missing === []) {
            return;
        }

        $rows = (int) DB::table(self::TABLE)->count();

        if ($rows > 0) {
            throw new RuntimeException(sprintf(
                'Cannot complete `%s`: the NOT NULL column(s) %s are missing and the table already holds %d row(s); no value is invented.',
                self::TABLE,
                implode(', ', $missing),
                $rows
            ));
        }
    }

    private function addColumnIfMissing(string $column, \Closure $definition): void
    {
        if (Schema::hasColumn(self::TABLE, $column)) {
            return;
        }

        Schema::table(self::TABLE, $definition);
    }

    private function addIndexIfMissing(string $name, array $columns): void
    {
        if ($this->indexExists($name)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $blueprint) use ($columns, $name) {
            $blueprint->index($columns, $name);
        });
    }

    /**
     * Adds the constraint when it is absent, verifies it when it is present, and refuses the whole
     * file — before any DDL — when the schema contradicts what this migration declares.
     */
    private function ensureForeignKey(string $column, string $parent, string $parentColumn, string $deleteRule): void
    {
        $constraint = $this->constraintName($column);

        if ($this->foreignKeyExists($constraint)) {
            if (! $this->foreignKeyMatches($column, $parent, $parentColumn, $deleteRule)) {
                throw new RuntimeException(
                    "Foreign-key restoration refused; the existing constraint `{$constraint}` has a different definition than this migration declares (expected {$parent}.{$parentColumn} ON DELETE ".strtoupper($deleteRule).'); no statement was executed.'
                );
            }

            return;
        }

        $this->assertForeignKeyAddable($column, $parent, $parentColumn);

        DB::statement(sprintf(
            'alter table `%s` add constraint `%s` foreign key (`%s`) references `%s` (`%s`) on delete %s on update restrict',
            self::TABLE,
            $constraint,
            $column,
            $parent,
            $parentColumn,
            $deleteRule
        ));
    }

    /** Fails closed with the complete list of blockers and zero mutations. */
    private function assertForeignKeyAddable(string $column, string $parent, string $parentColumn): void
    {
        $problems = [];

        if (! Schema::hasColumn(self::TABLE, $column)) {
            $problems[] = "child column `".self::TABLE."`.`{$column}` does not exist";
        }

        if (! Schema::hasTable($parent)) {
            $problems[] = "referenced table `{$parent}` does not exist";
        } elseif (! Schema::hasColumn($parent, $parentColumn)) {
            $problems[] = "referenced column `{$parent}`.`{$parentColumn}` does not exist";
        }

        if ($problems === []) {
            [$childType, $childNullable] = $this->columnDefinition(self::TABLE, $column);
            [$parentType, $parentNullable] = $this->columnDefinition($parent, $parentColumn);

            if ($childType !== $parentType) {
                $problems[] = "column type `{$childType}` does not match `{$parentType}`";
            }

            if (! $childNullable && $parentNullable) {
                $problems[] = "a NOT NULL column cannot reference a nullable column";
            }

            if (! $this->hasLeadingIndex($column)) {
                $problems[] = "no existing index starts on `".self::TABLE."`.`{$column}` — the constraint would have to build one";
            }

            if ($this->hasOrphan($column, $parent, $parentColumn)) {
                $problems[] = "existing rows reference a `{$parent}` row that does not exist — resolve the orphan, never disable foreign-key checks";
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(
                "Foreign-key restoration refused; no constraint was changed:\n  - ".implode("\n  - ", $problems)
            );
        }
    }

    private function constraintName(string $column): string
    {
        return self::TABLE."_{$column}_foreign";
    }

    private function foreignKeyExists(string $constraint): bool
    {
        return DB::selectOne(
            'select 1 as present from information_schema.referential_constraints
             where constraint_schema = ? and table_name = ? and constraint_name = ?',
            [DB::getDatabaseName(), self::TABLE, $constraint]
        ) !== null;
    }

    private function foreignKeyMatches(string $column, string $parent, string $parentColumn, string $deleteRule): bool
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
                self::TABLE,
                $this->constraintName($column),
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

    private function indexExists(string $name): bool
    {
        return DB::selectOne(
            'select 1 as present from information_schema.statistics
             where table_schema = ? and table_name = ? and index_name = ? limit 1',
            [DB::getDatabaseName(), self::TABLE, $name]
        ) !== null;
    }

    private function hasLeadingIndex(string $column): bool
    {
        return DB::selectOne(
            'select 1 as present from information_schema.statistics
             where table_schema = ? and table_name = ? and column_name = ? and seq_in_index = 1 limit 1',
            [DB::getDatabaseName(), self::TABLE, $column]
        ) !== null;
    }

    private function hasOrphan(string $column, string $parent, string $parentColumn): bool
    {
        $row = DB::selectOne(sprintf(
            'select exists(select 1 from `%s` where `%s` is not null and `%s` not in (select `%s` from `%s`) limit 1) as orphan_exists',
            self::TABLE,
            $column,
            $column,
            $parentColumn,
            $parent
        ));

        return $row !== null && (int) $row->orphan_exists === 1;
    }
};
