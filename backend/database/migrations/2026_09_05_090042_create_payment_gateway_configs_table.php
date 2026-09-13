<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->cascadeOnDelete();
            $table->enum('environment', ['sandbox', 'production']);
            // Encrypted (Laravel 'encrypted' cast) JSON blob of gateway credentials —
            // stored as text, never as plain DB-native JSON, so secrets are unreadable at rest.
            $table->text('config');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['payment_method_id', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_configs');
    }
};
