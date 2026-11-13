<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REM-07 / D-06 A — actual PER-ITEM recipient receipt events.
 *
 * Deliberately NOT a column set on delivery_verifications: that table is shipment-keyed
 * (shipment_id NOT NULL, enum outcome, append-only history/latest readers in Order, Shipment,
 * ShipmentCanonicalizationService, resources and the reset tooling). Mixing item facts in would
 * contaminate every one of those readers. This table is additive, append-only evidence:
 * legacy shipments/items get NO synthetic rows — unknown stays unknown (ineligible).
 *
 * received_on is the ACTUAL date the recipient received the units (never derived from
 * shipments.delivered_at / verified_at). received_at is only filled when a real time is proven.
 * created_at / verified_at stay truthful recording times.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete(); // Agent scope, copied from the order
            $table->unsignedInteger('quantity');
            $table->date('received_on');
            $table->timestamp('received_at')->nullable();
            $table->foreignId('evidence_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('evidence_note', 500)->nullable();
            $table->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at');
            $table->string('idempotency_key', 100);
            $table->char('request_fingerprint', 64);
            $table->timestamps();

            $table->unique(['verified_by', 'idempotency_key'], 'oir_verifier_key_unique');
            $table->index(['order_item_id', 'received_on']);
            $table->index(['agent_id', 'received_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_receipts');
    }
};
