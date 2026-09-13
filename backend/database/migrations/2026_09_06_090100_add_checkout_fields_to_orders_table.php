<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('payment_method_id')->nullable()->after('agent_id')
                ->constrained('payment_methods')->nullOnDelete();

            // One request = one order: a retried/duplicated submission with the
            // same key returns the original order instead of creating a second
            // one. Unique per konsumen so two different konsumen can never
            // collide on a client-generated UUID.
            $table->string('idempotency_key', 80)->nullable()->after('order_no');
            $table->unique(['konsumen_id', 'idempotency_key'], 'orders_konsumen_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_konsumen_idempotency_unique');
            $table->dropColumn('idempotency_key');
            $table->dropConstrainedForeignId('payment_method_id');
        });
    }
};
