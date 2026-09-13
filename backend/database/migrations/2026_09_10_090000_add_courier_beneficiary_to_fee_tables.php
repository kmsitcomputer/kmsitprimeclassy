<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds 'courier' as a valid fee/commission beneficiary alongside 'agent' and
 * 'sales' — the courier delivery fee configured per product/variation
 * (Blueprint: "Tambahkan sistem Fee untuk kurir di produk").
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE product_fees MODIFY beneficiary_role ENUM('agent','sales','courier') NOT NULL");
        DB::statement("ALTER TABLE product_variation_fees MODIFY beneficiary_role ENUM('agent','sales','courier') NOT NULL");
        DB::statement("ALTER TABLE commissions MODIFY beneficiary_role ENUM('agent','sales','courier') NOT NULL");
    }

    public function down(): void
    {
        DB::table('commissions')->where('beneficiary_role', 'courier')->delete();
        DB::table('product_fees')->where('beneficiary_role', 'courier')->delete();
        DB::table('product_variation_fees')->where('beneficiary_role', 'courier')->delete();

        DB::statement("ALTER TABLE product_fees MODIFY beneficiary_role ENUM('agent','sales') NOT NULL");
        DB::statement("ALTER TABLE product_variation_fees MODIFY beneficiary_role ENUM('agent','sales') NOT NULL");
        DB::statement("ALTER TABLE commissions MODIFY beneficiary_role ENUM('agent','sales') NOT NULL");
    }
};
