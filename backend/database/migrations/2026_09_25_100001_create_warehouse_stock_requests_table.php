<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_stock_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            $table->enum('request_type', ['addition', 'sub_adjustment'])->default('addition');
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variation_id')->nullable()->constrained('product_variations')->restrictOnDelete();
            $table->enum('target_stock_type', ['transit', 'factory_plan', 'sub'])->default('transit');
            $table->foreignId('sub_location_id')->nullable()->constrained('warehouse_sub_locations')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('reference', 255)->nullable();
            $table->string('note', 255)->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'status'], 'wsr_agent_status');
            $table->index(['agent_id', 'request_type', 'product_id', 'product_variation_id'], 'wsr_agent_type_target');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE warehouse_stock_requests
            ADD CONSTRAINT chk_warehouse_stock_requests_target
            CHECK (
                (product_id IS NOT NULL AND product_variation_id IS NULL)
                OR
                (product_id IS NULL AND product_variation_id IS NOT NULL)
            )
        SQL);

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('warehouse_stock_request_id')->nullable()->after('opname_id')->constrained('warehouse_stock_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_stock_request_id');
        });
        Schema::dropIfExists('warehouse_stock_requests');
    }
};
