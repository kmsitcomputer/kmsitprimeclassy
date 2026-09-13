<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_homepage_blocks_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cms_homepage_block_id')->constrained('cms_homepage_blocks')->cascadeOnDelete();
            $table->foreignId('language_id')->constrained('languages')->cascadeOnDelete();
            $table->string('title', 200)->nullable();
            $table->string('subtitle', 300)->nullable();
            $table->longText('body')->nullable();
            $table->timestamps();

            $table->unique(['cms_homepage_block_id', 'language_id'], 'chbt_block_language_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_homepage_blocks_translations');
    }
};
