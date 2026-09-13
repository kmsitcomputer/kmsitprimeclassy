<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            // restrictOnDelete (not nullOnDelete): MySQL forbids a CHECK constraint on a
            // column that is also the child of a SET NULL foreign key, and products/variations
            // are soft-deleted anyway so a real delete should never hit this table's rows.
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variation_id')->nullable()
                ->constrained('product_variations')->restrictOnDelete();
            $table->enum('type', [
                'in', 'out', 'reserve', 'release', 'adjustment',
                'transfer_in', 'transfer_out', 'return_restock',
            ]);
            // Signed delta applied to the stock row (e.g. -5 for "out", +5 for "in"),
            // so SUM(quantity) reconciles directly against quantity_on_hand for audits.
            $table->integer('quantity');
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['agent_id', 'product_id']);
            $table->index(['agent_id', 'product_variation_id']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('created_at');
        });

        // Exactly one of product_id / product_variation_id must be set per movement row.
        DB::statement(<<<'SQL'
            ALTER TABLE stock_movements
            ADD CONSTRAINT chk_stock_movements_target
            CHECK (
                (product_id IS NOT NULL AND product_variation_id IS NULL)
                OR
                (product_id IS NULL AND product_variation_id IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
