<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Konsumen dapat memasukan bukti pembayaran COD dengan memasukan foto
 * pembayaran kepada kurir di tempat, konsumen dapat melakukan request
 * pembayaran lunas ke admin" — the COD counterpart of BankTransferVerification:
 * a photo the konsumen submits, staying 'pending' until an Admin/Agen
 * explicitly confirms it (the only thing that ever actually flips
 * orders.payment_status to 'paid' — never the konsumen's own submission).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cod_payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_transaction_id')->unique()->constrained('payment_transactions')->cascadeOnDelete();
            $table->foreignId('proof_media_id')->constrained('media')->restrictOnDelete();
            $table->enum('status', ['pending', 'confirmed', 'rejected'])->default('pending');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cod_payment_proofs');
    }
};
