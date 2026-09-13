<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Profile photo — resolved to a URL the same way site_logo_media_id is (see WebsiteSettingsService::attachMediaUrls). Nullable: most accounts never set one. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('avatar_media_id')->nullable()->after('phone')
                ->constrained('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('avatar_media_id');
        });
    }
};
