<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('language_id')->constrained('languages')->cascadeOnDelete();
            $table->string('name', 150);
            $table->longText('description')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'language_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products_translations');
    }
};
