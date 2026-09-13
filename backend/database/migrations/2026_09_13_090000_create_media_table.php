<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The single storage abstraction for every uploaded image in the dashboard
 * (Blueprint §Media Management) — a thin metadata row over whatever disk
 * MediaService currently targets, so switching that disk to S3/object
 * storage later never touches a single controller. `mediable_*` is
 * nullable: a file can exist "standalone" (CKEditor 5's own image-upload
 * button uploads before the article/page it belongs to is even saved) and
 * get attached to its owner afterward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 50)->default('public');
            $table->string('path', 500);
            $table->string('collection', 60);
            $table->string('original_filename', 255)->nullable();
            $table->string('mime_type', 100);
            $table->string('extension', 10);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->nullableMorphs('mediable');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('collection');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
