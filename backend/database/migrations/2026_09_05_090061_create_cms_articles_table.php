<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "articles" and "news" share one identical shape — differentiated by `type`
        // rather than duplicating a near-identical table.
        Schema::create('cms_articles', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['article', 'news']);
            $table->string('slug', 180)->unique();
            $table->string('cover_image_path', 255)->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_articles');
    }
};
