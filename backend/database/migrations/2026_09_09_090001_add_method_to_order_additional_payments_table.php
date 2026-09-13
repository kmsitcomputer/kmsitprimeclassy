<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_additional_payments', function (Blueprint $table) {
            // "Additional payment dapat: transfer, COD" — transfer creates a
            // real PaymentTransaction (proof + verification, same as the
            // manual-transfer order flow); cod is collected physically and
            // marked paid by Admin/Agen directly.
            $table->enum('method', ['transfer', 'cod'])->default('transfer')->after('requested_by');
        });
    }

    public function down(): void
    {
        Schema::table('order_additional_payments', function (Blueprint $table) {
            $table->dropColumn('method');
        });
    }
};
