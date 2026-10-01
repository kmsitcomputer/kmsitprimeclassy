<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-03 / decision B: append-only Admin final delivery verification.
 *
 * Separate from payment verification, transaction verification and the courier's own shipment
 * delivery action. Each verification is a NEW row — a later action (e.g. not_received -> received)
 * never erases the previous outcome, so the full history is preserved with actor + timestamp +
 * note + shipment reference (proof is reached through shipments.proof_media_id).
 *
 * No `order_id`: the order is derived through `shipment_id`.
 * No `return_request_id`: an Admin `return` outcome does not yet identify the exact returned
 * items/quantities, and ReturnRequest remains its own workflow.
 *
 * `(verified_by, idempotency_key)` is unique so a retried request (same Admin, same key) cannot
 * create duplicate verification history; a NULL key (older/plain submissions) never collides
 * because MySQL permits multiple NULLs in a unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->enum('outcome', ['received', 'not_received', 'return']);
            $table->text('note')->nullable();
            $table->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at');
            $table->string('idempotency_key', 100)->nullable();
            $table->timestamps();

            $table->index(['shipment_id', 'id']);
            $table->index('outcome');
            $table->unique(['verified_by', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_verifications');
    }
};
