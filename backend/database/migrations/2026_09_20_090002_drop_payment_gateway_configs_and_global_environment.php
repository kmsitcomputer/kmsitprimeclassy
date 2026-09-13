<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('payment_gateway_configs');

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('active_environment');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->enum('active_environment', ['sandbox', 'production'])->default('sandbox');
        });

        Schema::create('payment_gateway_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->cascadeOnDelete();
            $table->enum('environment', ['sandbox', 'production']);
            $table->text('config');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['payment_method_id', 'environment']);
        });
    }
};
