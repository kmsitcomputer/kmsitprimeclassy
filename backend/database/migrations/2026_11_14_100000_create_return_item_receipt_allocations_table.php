<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REM-07 / D-06 A: explicit, evidenced association of ACCEPTED physical return units to the actual
 * receipt event(s) they came from. Recorded by an authorised person — never inferred (no FIFO,
 * no pro-rata). Append-only; legacy returns simply have no rows (⇒ unresolved, excluded from ranking
 * only when the item has several receipt events).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_item_receipt_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_item_id')->constrained('return_items')->cascadeOnDelete();
            $table->foreignId('order_item_receipt_id')->constrained('order_item_receipts')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->foreignId('allocated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('allocated_at');
            $table->string('evidence_note', 500)->nullable();
            $table->timestamps();

            $table->unique(['return_item_id', 'order_item_receipt_id'], 'rira_return_receipt_unique');
            $table->index('order_item_receipt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_item_receipt_allocations');
    }
};
