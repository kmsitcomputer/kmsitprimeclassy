<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REM-01 / ODR-03 B / D-07 A — additive, nullable, legacy rows untouched.
 *
 * A catalog import row that carries a stock target is carried by ONE existing
 * stock_opname_item (its own single-target opname, so approval is independent per row):
 *  - import_row_key        durable idempotency key (UNIQUE) — a retry can never create a second document
 *  - import_fingerprint    normalised payload hash — same key + different payload → 409
 *  - import_target_quantity the file's physical target (the Gudang still counts; this is never applied as a balance)
 *  - pending_catalog_change the withheld catalogue change, published ONLY by the approval commit (D-07)
 *  - catalog_published_at  set in the same transaction that approves the stock
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_opname_items', function (Blueprint $table) {
            $table->string('import_row_key', 120)->nullable()->after('adjusted_quantity');
            $table->char('import_fingerprint', 64)->nullable()->after('import_row_key');
            $table->unsignedInteger('import_target_quantity')->nullable()->after('import_fingerprint');
            $table->json('pending_catalog_change')->nullable()->after('import_target_quantity');
            $table->timestamp('catalog_published_at')->nullable()->after('pending_catalog_change');
            $table->unique('import_row_key', 'soi_import_row_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('stock_opname_items', function (Blueprint $table) {
            $table->dropUnique('soi_import_row_key_unique');
            $table->dropColumn(['import_row_key', 'import_fingerprint', 'import_target_quantity', 'pending_catalog_change', 'catalog_published_at']);
        });
    }
};
