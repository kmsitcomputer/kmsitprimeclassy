<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheets_destinations', function (Blueprint $table) {
            $table->string('spreadsheet_title', 255)->nullable()->after('agent_id');
            $table->string('connection_status', 30)->default('not_checked')->after('spreadsheet_title');
            $table->timestamp('last_tested_at')->nullable()->after('connection_status');
            $table->string('last_error_code', 50)->nullable()->after('last_tested_at');
        });
    }

    public function down(): void
    {
        Schema::table('sheets_destinations', function (Blueprint $table) {
            $table->dropColumn(['spreadsheet_title', 'connection_status', 'last_tested_at', 'last_error_code']);
        });
    }
};
