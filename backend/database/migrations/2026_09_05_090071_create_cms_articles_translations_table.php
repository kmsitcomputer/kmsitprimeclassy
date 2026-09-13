<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_articles_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cms_article_id')->constrained('cms_articles')->cascadeOnDelete();
            $table->foreignId('language_id')->constrained('languages')->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('excerpt', 300)->nullable();
            $table->longText('body');
            $table->timestamps();

            $table->unique(['cms_article_id', 'language_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_articles_translations');
    }
};
