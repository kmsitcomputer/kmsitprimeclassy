<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `users.email` becomes optional and `users.phone` is normalised to `varchar(20) NULL`.
 *
 * WHY (repository evidence, merge 2ee05c4 "Register And Login Update")
 *   `RegisterKonsumenRequest` now declares `email` as `nullable`, and `AuthController::register()`
 *   writes `null` when the request carries no email (phone-only registration). The `users` table
 *   still declared `email varchar(255) NOT NULL`, so a phone-only registration could not be stored:
 *   the column had no default and MySQL runs in STRICT_TRANS_TABLES mode. This migration is the
 *   storage half of that feature and must stay paired with the request/validation change.
 *
 *   The `users_email_unique` index survives the change untouched: the migration emits a plain
 *   `alter table ... modify`, which never rebuilds or re-creates an index. MySQL allows any number
 *   of NULL values in a UNIQUE index, so email-less accounts coexist with the existing accounts.
 *
 * PARTIAL-EXECUTION SAFETY (2026-10-20, DEV recovery RECON-PC-POST-PULL-RECOVERY-001)
 *   The migration is idempotent by construction: each column is compared with the target
 *   definition and is only modified when it differs, so a re-run on an already-converted database
 *   is a no-op instead of a needless rebuild. This file has never been applied anywhere (it is
 *   absent from the DEV and TEST ledgers), so changing it carries no cross-environment risk.
 *
 * ROLLBACK
 *   The rollback refuses to run while a user has no email, exactly as it already refuses while a
 *   user has no phone. The previous version wrote an empty string into the missing emails, which
 *   silently destroyed the address of an email-less account and collided with
 *   `users_email_unique` as soon as two such accounts existed. A rollback that cannot restore the
 *   data it depends on is refused instead of being executed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->matchesTarget('email', 'varchar(255)', true)) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('email')->nullable()->change();
            });
        }

        if (! $this->matchesTarget('phone', 'varchar(20)', true)) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('phone', 20)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // A NOT NULL column cannot be restored while a row has no value for it. The rollback is
        // refused instead of writing a placeholder that would destroy the original address.
        if (DB::table('users')->whereNull('email')->exists()) {
            throw new RuntimeException(
                'Tidak dapat rollback: masih ada pengguna dengan email NULL.'
            );
        }

        if (DB::table('users')->whereNull('phone')->exists()) {
            throw new RuntimeException(
                'Tidak dapat rollback: masih ada pengguna dengan phone NULL.'
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->string('phone', 20)->nullable()->change();
        });
    }

    /**
     * True when the column already has the target type and nullability, so the `modify` can be
     * skipped instead of rebuilding the table for nothing.
     */
    private function matchesTarget(string $column, string $type, bool $nullable): bool
    {
        $row = DB::selectOne(
            'select COLUMN_TYPE as column_type, IS_NULLABLE as is_nullable from information_schema.columns
             where table_schema = ? and table_name = ? and column_name = ?',
            [DB::getDatabaseName(), 'users', $column]
        );

        if ($row === null) {
            return false;
        }

        return strtolower((string) $row->column_type) === $type
            && (strtoupper((string) $row->is_nullable) === 'YES') === $nullable;
    }
};
