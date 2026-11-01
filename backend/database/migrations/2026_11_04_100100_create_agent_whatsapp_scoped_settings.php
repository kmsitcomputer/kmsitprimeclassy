<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive. Per-Agen WhatsApp notification event switches/templates (one authoritative row per Agen+event, edited by the
 * Agen or its Admin) and the per-Agen allow-list of registered test numbers. No existing row is touched; the legacy global
 * wa.event.* / wa.template.* settings stay as the fallback when an Agen has no row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_whatsapp_event_settings')) {
            Schema::create('agent_whatsapp_event_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agent_id');
                $table->string('event', 40);
                $table->boolean('enabled')->nullable();   // null = inherit the legacy global flag
                $table->text('template')->nullable();      // null = inherit the global/default template
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['agent_id', 'event']);
                $table->foreign('agent_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('agent_whatsapp_test_numbers')) {
            Schema::create('agent_whatsapp_test_numbers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agent_id');
                $table->string('msisdn', 20);
                $table->unsignedBigInteger('registered_by')->nullable();
                $table->timestamps();
                $table->unique(['agent_id', 'msisdn']);
                $table->foreign('agent_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_whatsapp_test_numbers');
        Schema::dropIfExists('agent_whatsapp_event_settings');
    }
};
