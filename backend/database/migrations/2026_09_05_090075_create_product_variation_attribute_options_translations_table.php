<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variation_attribute_options_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variation_attribute_option_id')
                ->constrained('product_variation_attribute_options', 'id', 'pvaot_option_fk')
                ->cascadeOnDelete();
            $table->foreignId('language_id')
                ->constrained('languages', 'id', 'pvaot_language_fk')
                ->cascadeOnDelete();
            $table->string('value', 100);
            $table->timestamps();

            $table->unique(
                ['product_variation_attribute_option_id', 'language_id'],
                'pvaot_option_language_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variation_attribute_options_translations');
    }
};
