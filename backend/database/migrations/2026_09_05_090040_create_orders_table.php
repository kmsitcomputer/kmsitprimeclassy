<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 30)->unique();

            $table->foreignId('konsumen_id')->constrained('users')->restrictOnDelete();
            // Referral chain snapshotted at order time — never re-derived live from users.*_id,
            // so historical commission/reporting stays correct even if the org tree changes later.
            $table->foreignId('sales_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('korsal_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();

            $table->foreignId('source_address_id')->nullable()
                ->constrained('konsumen_addresses')->nullOnDelete();

            $table->enum('status', [
                'diterima', 'diproses', 'dikirim', 'terkirim', 'dibatalkan', 'pengembalian', 'kembali',
            ])->default('diterima');
            $table->enum('payment_status', [
                'unpaid', 'pending_verification', 'paid', 'partially_refunded', 'refunded', 'failed',
            ])->default('unpaid');

            $table->decimal('subtotal_amount', 12, 2)->unsigned();
            $table->decimal('discount_amount', 12, 2)->unsigned()->default(0);
            $table->decimal('shipping_fee_amount', 12, 2)->unsigned()->default(0);
            $table->decimal('admin_fee_amount', 12, 2)->unsigned()->default(0);
            $table->decimal('total_amount', 12, 2)->unsigned();

            // Address snapshot — authoritative for this order, independent of konsumen_addresses edits.
            $table->string('recipient_name_snapshot', 150);
            $table->string('recipient_phone_snapshot', 20);
            $table->text('address_snapshot');
            $table->decimal('latitude_snapshot', 10, 7)->nullable();
            $table->decimal('longitude_snapshot', 10, 7)->nullable();

            $table->date('delivery_date_estimate')->nullable();
            $table->timestamp('delivery_date_actual')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 255)->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'created_at']);
            $table->index('konsumen_id');
            $table->index('sales_id');
            $table->index('korsal_id');
            $table->index('status');
            $table->index('payment_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
