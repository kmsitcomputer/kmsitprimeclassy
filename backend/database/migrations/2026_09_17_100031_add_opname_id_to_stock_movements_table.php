<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('opname_id')->nullable()->after('handover_id')->constrained('stock_opnames')->restrictOnDelete();
            $table->index('opname_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['opname_id']);
            $table->dropForeign(['opname_id']);
            $table->dropColumn('opname_id');
        });
    }
};
