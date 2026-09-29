<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * R-01: `sales-kurir` evolves into `sales-kurir-sub`. The EXISTING roles row (id 10 in
 * production) is renamed in place, so every users.role_id keeps pointing at the same row —
 * no 11th role, no user rewrite, no historical transaction rewrite. Referral codes are not
 * touched (historical SA- and SK- prefixed codes survive; only NEW codes use the SS- prefix).
 *
 * Idempotent: safe to re-run, safe on a fresh database (where the seeder creates the new slug).
 */
return new class extends Migration
{
    public function up(): void
    {
        $legacy = DB::table('roles')->where('slug', 'sales-kurir')->first();
        $current = DB::table('roles')->where('slug', 'sales-kurir-sub')->first();

        if ($legacy && ! $current) {
            DB::table('roles')->where('id', $legacy->id)->update(['slug' => 'sales-kurir-sub', 'name' => 'Sales-Kurir-Sub', 'updated_at' => now()]);

            return;
        }

        if ($legacy && $current) {
            // Both rows exist (seeder ran before this migration): fold users onto the surviving
            // canonical row instead of leaving an 11th role behind.
            DB::table('users')->where('role_id', $legacy->id)->update(['role_id' => $current->id]);
            DB::table('roles')->where('id', $legacy->id)->delete();
        }
    }

    public function down(): void
    {
        DB::table('roles')->where('slug', 'sales-kurir-sub')->update(['slug' => 'sales-kurir', 'name' => 'Sales-Kurir', 'updated_at' => now()]);
    }
};
