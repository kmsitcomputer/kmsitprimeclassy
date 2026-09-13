<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_payment_method_settings', function (Blueprint $table) {
            // The agen's own choice of sandbox/production for a gateway method —
            // replaces payment_methods.active_environment, which was global.
            $table->enum('active_environment', ['sandbox', 'production'])->default('sandbox')->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('agent_payment_method_settings', function (Blueprint $table) {
            $table->dropColumn('active_environment');
        });
    }
};
