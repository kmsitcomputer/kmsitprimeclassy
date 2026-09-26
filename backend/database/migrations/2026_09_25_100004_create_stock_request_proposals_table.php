<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_request_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('stock_request_id')->constrained('stock_requests')->restrictOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['agent_id', 'status']);
            $table->index('stock_request_id');
        });

        Schema::create('stock_request_proposal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_request_proposal_id')->constrained('stock_request_proposals')->cascadeOnDelete();
            $table->foreignId('stock_request_item_id')->constrained('stock_request_items')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->index('stock_request_proposal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_request_proposal_items');
        Schema::dropIfExists('stock_request_proposals');
    }
};
