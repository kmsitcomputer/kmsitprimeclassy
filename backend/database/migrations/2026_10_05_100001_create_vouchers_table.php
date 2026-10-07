<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IMP-002 — vouchers (promo codes) integrated with canonical checkout.
 *
 * Additive, forward-only. Scope mirrors the discount design: a voucher is
 * created by an agen for its own Agent (agent_id always set; super_admin may
 * act cross-agent). Each voucher applies to either a specific product or a
 * specific variation (product_id XOR product_variation_id, both nullable and
 * mutually exclusive via the service layer), or to any product when both are
 * null (whole-cart / catalog-wide voucher).
 *
 * `max_uses` is the total redemption budget (nullable = unlimited);
 * `used_count` is incremented server-side under a row lock, so repeated
 * concurrent application cannot overspend a limited voucher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id')->index();
            $table->string('code', 60)->index();
            $table->string('name', 120);
            $table->enum('type', ['percentage', 'fixed'])->default('percentage');
            // percentage: 1-100 (server clamps); fixed: nominal rupiah > 0.
            $table->decimal('value', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->unsignedBigInteger('product_variation_id')->nullable()->index();
            $table->unsignedBigInteger('max_uses')->nullable();
            $table->unsignedBigInteger('used_count')->default(0);
            $table->timestamps();

            $table->unique(['agent_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};