<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IMP-006 slice 1: visit log + follow-up plan (`plan_next_date`). `status` holds only the verified
 * SimpleV2 vocabulary N,1..7 — `FU`/`D` stay unimplemented until the Owner defines them (CI-07).
 * History is never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('crm_customer_id')->constrained('crm_customers')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('visit_date');
            $table->string('status', 2);
            $table->unsignedTinyInteger('prospect_percent')->nullable();
            $table->text('note')->nullable();
            $table->date('plan_next_date')->nullable();
            $table->timestamps();

            $table->index(['crm_customer_id', 'visit_date']);
            $table->index(['user_id', 'plan_next_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_visits');
    }
};
