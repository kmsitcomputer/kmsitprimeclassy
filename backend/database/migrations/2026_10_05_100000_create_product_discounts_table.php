<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IMP-002 — first-class product/variation discounts.
 *
 * Additive, forward-only. A discount row targets EITHER a product (simple or
 * parent) OR a specific variation, never both. Scope is the Agent (branch),
 * exactly like every other agent-scoped catalog feature; super_admin may
 * manage any branch via the canonical super_admin cross-agent authority.
 *
 * Discount percentages are stored in the integer percentage-point range
 * (e.g. 15 = 15%). The server clamps to [1, 100] and refuses anything that
 * would produce a negative/zero effective price (the price floor is enforced
 * in the discount service, not here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_discounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->unsignedBigInteger('product_variation_id')->nullable()->index();
            $table->string('name', 120);
            $table->unsignedTinyInteger('percentage')->comment('integer percent, 1-100');
            $table->boolean('is_active')->default(true);
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->timestamps();

            // A row targets either a product OR a variation, exactly one side.
            $table->index(['agent_id', 'product_id']);
            $table->index(['agent_id', 'product_variation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_discounts');
    }
};