<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IMP-006 slice 1 (BUSINESS-RULES §27): login-independent CRM Customer. Never a `users` row.
 * Belongs to exactly one Agent and one owning Sales/Sales-Kurir-Sub. Additive only; there is no
 * link to a Konsumen here (linking is the WS-9 lifecycle) and no order FK (buyer-origin, G-17).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('customer_type', 50)->nullable();
            $table->string('relation', 100)->nullable();
            $table->text('address')->nullable();
            $table->unsignedTinyInteger('prospect_percent')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'owner_user_id']);
            // One owner cannot hold two records for the same normalized phone.
            $table->unique(['owner_user_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_customers');
    }
};
