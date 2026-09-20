<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_migration_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 80)->unique();
            $table->boolean('dry_run')->default(true);
            $table->string('status', 30)->default('running');
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('rows_scanned')->default(0);
            $table->unsignedInteger('rows_migrated')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->unsignedInteger('rows_conflicted')->default(0);
            $table->unsignedInteger('rows_failed')->default(0);
            $table->unsignedBigInteger('quantity_total')->default(0);
            $table->text('summary')->nullable();
            $table->timestamps();
        });

        Schema::create('warehouse_migration_markers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('warehouse_migration_runs')->cascadeOnDelete();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variation_id')->nullable()->constrained('product_variations')->restrictOnDelete();
            $table->unsignedInteger('source_quantity');
            $table->string('destination_stock_type', 30)->default('transit');
            $table->string('status', 30);
            $table->string('error_code', 80)->nullable();
            $table->text('error_detail')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id']);
            $table->index(['agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_migration_markers');
        Schema::dropIfExists('warehouse_migration_runs');
    }
};
