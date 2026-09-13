<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_pages_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cms_page_id')->constrained('cms_pages')->cascadeOnDelete();
            $table->foreignId('language_id')->constrained('languages')->cascadeOnDelete();
            $table->string('title', 200);
            $table->longText('body')->nullable();
            $table->string('seo_title', 200)->nullable();
            $table->string('seo_description', 300)->nullable();
            $table->timestamps();

            $table->unique(['cms_page_id', 'language_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_pages_translations');
    }
};
