<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            // Which payment_gateway_configs row (sandbox vs production) is
            // actually live — an explicit Super Admin choice, independent of
            // Laravel's own APP_ENV (a store may run APP_ENV=production while
            // still testing a gateway in sandbox mode, or vice versa).
            $table->enum('active_environment', ['sandbox', 'production'])->default('sandbox')->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('active_environment');
        });
    }
};
