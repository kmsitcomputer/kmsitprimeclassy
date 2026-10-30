<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G17-D1 / D2 (Owner 2026-10-30, ARCHITECTURE §16b): buyer origin = exactly ONE of `konsumen_id`
 * (account) or `crm_customer_id` (CRM customer) for every NEW order; `placed_by_user_id` records who
 * actually submitted it. Additive, with one nullability relaxation: `konsumen_id` becomes nullable.
 *
 * Historical rows are untouched and NO database CHECK is added (the Owner forbade constraining
 * history before an audit): "exactly one origin" is enforced in the service layer for new orders.
 * The idempotency index for CRM-origin orders is separate, so a CRM request can never replay against
 * an account buyer's order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('crm_customer_id')->nullable()->after('konsumen_id')
                ->constrained('crm_customers')->restrictOnDelete();
            $table->foreignId('placed_by_user_id')->nullable()->after('crm_customer_id')
                ->constrained('users')->restrictOnDelete();
            // Immutable buyer identity snapshot for CRM-origin orders (NULL for account-origin/historical).
            $table->string('buyer_name_snapshot')->nullable();
            $table->string('buyer_phone_snapshot', 30)->nullable();

            $table->unique(['crm_customer_id', 'idempotency_key'], 'orders_crm_idempotency_unique');
        });

        // Relax nullability only; type/FK/index stay as they were.
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('konsumen_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // konsumen_id intentionally stays nullable on rollback: restoring NOT NULL would fail (or force a
        // data decision) as soon as one CRM-origin order exists.
        Schema::table('orders', function (Blueprint $table) {
            // The unique index leads with crm_customer_id, so MySQL uses it to back that FK: drop the
            // foreign keys first, then the index, then the columns (the reverse of up()).
            $table->dropForeign(['crm_customer_id']);
            $table->dropForeign(['placed_by_user_id']);
            $table->dropUnique('orders_crm_idempotency_unique');
            $table->dropColumn(['crm_customer_id', 'placed_by_user_id', 'buyer_name_snapshot', 'buyer_phone_snapshot']);
        });
    }
};
