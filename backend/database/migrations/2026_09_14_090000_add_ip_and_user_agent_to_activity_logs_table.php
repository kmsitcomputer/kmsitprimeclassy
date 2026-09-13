<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every audit entry must carry "actor, role, action, target, target id, old
 * value, new value, IP, user agent, timestamp" — IP/user agent were the only
 * two of those with nowhere to live; ActivityLogger now captures both from
 * the current request automatically, never a value a caller can spoof by
 * passing one in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->after('properties');
            $table->string('user_agent', 500)->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'user_agent']);
        });
    }
};
