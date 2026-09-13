<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variation_compositions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variation_id')
                ->constrained('product_variations')->cascadeOnDelete();
            $table->foreignId('product_variation_attribute_id')
                ->constrained('product_variation_attributes', 'id', 'pvc_attribute_fk')
                ->cascadeOnDelete();
            $table->foreignId('product_variation_attribute_option_id')
                ->constrained('product_variation_attribute_options', 'id', 'pvc_option_fk')
                ->cascadeOnDelete();

            // one chosen option per attribute per variation (e.g. cannot have two "Ukuran" values on one SKU)
            $table->unique(
                ['product_variation_id', 'product_variation_attribute_id'],
                'pvc_variation_attribute_unique'
            );
            $table->index('product_variation_attribute_option_id', 'pvc_option_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variation_compositions');
    }
};
