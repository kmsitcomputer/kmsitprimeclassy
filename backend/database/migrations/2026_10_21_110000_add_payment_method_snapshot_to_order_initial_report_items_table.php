<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The initial-transaction report needs the payment method as it was at order
     * creation. `payment_methods.name` is master data and may be rewritten by a
     * seeder, so the label is copied onto the immutable snapshot row instead of
     * being resolved live at export time.
     */
    public function up(): void
    {
        Schema::table('order_initial_report_items', function (Blueprint $table) {
            $table->string('payment_method_code_snapshot', 30)->nullable();
            $table->string('payment_method_name_snapshot', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_initial_report_items', function (Blueprint $table) {
            $table->dropColumn(['payment_method_code_snapshot', 'payment_method_name_snapshot']);
        });
    }
};
