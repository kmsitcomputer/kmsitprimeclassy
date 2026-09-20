<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('stock_type', 30)->nullable()->after('quantity');
            $table->string('counterpart_stock_type', 30)->nullable()->after('stock_type');
            $table->unsignedBigInteger('transfer_id')->nullable()->after('counterpart_stock_type');
            $table->unsignedBigInteger('handover_id')->nullable()->after('transfer_id');
            $table->index(['agent_id', 'stock_type', 'product_id', 'product_variation_id'], 'stock_movements_agent_type_target');
            $table->index('transfer_id', 'stock_movements_transfer');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('stock_movements_agent_type_target');
            $table->dropIndex('stock_movements_transfer');
            $table->dropColumn(['stock_type', 'counterpart_stock_type', 'transfer_id', 'handover_id']);
        });
    }
};
