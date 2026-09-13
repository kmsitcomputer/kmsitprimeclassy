<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variation_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variation_id')
                ->constrained('product_variations')->cascadeOnDelete();
            $table->enum('beneficiary_role', ['agent', 'sales']);
            $table->decimal('amount', 12, 2)->unsigned();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_variation_id', 'beneficiary_role'], 'pvf_variation_role_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variation_fees');
    }
};
