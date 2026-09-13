<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Used only when products.has_variations = true.
        Schema::create('product_variation_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('product_variation_id')->constrained('product_variations')->restrictOnDelete();
            $table->unsignedInteger('quantity_on_hand')->default(0);
            $table->unsignedInteger('quantity_reserved')->default(0);
            $table->timestamps();

            $table->unique(['agent_id', 'product_variation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variation_stocks');
    }
};
