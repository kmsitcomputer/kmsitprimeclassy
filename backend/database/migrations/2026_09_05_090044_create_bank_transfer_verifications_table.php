<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transfer_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_transaction_id')->unique()
                ->constrained('payment_transactions')->cascadeOnDelete();
            $table->string('bank_name', 60);
            $table->string('account_name', 100);
            $table->string('account_number', 40);
            $table->string('proof_image_path', 255);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->enum('status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->string('rejection_reason', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transfer_verifications');
    }
};
