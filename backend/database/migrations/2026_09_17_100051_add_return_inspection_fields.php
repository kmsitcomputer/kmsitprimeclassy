<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_items', function (Blueprint $table) {
            $table->unsignedInteger('quantity_received')->default(0)->after('quantity_returned');
            $table->unsignedInteger('good_quantity')->default(0)->after('quantity_received');
            $table->unsignedInteger('damaged_quantity')->default(0)->after('good_quantity');
            $table->enum('condition_status', ['pending', 'good', 'damaged', 'mixed'])->default('pending')->after('damaged_quantity');
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete()->after('condition_status');
            $table->timestamp('inspected_at')->nullable()->after('inspected_by');
            $table->timestamp('restock_processed_at')->nullable()->after('inspected_at');
            $table->string('disposition_status', 40)->default('pending')->after('restock_processed_at');
        });
    }

    public function down(): void
    {
        Schema::table('return_items', function (Blueprint $table) {
            $table->dropForeign(['inspected_by']);
            $table->dropColumn(['quantity_received', 'good_quantity', 'damaged_quantity', 'condition_status', 'inspected_by', 'inspected_at', 'restock_processed_at', 'disposition_status']);
        });
    }
};
