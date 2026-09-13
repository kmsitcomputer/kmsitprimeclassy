<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the KEUANGAN role — a branch-level financial-operations role that is
 * strictly separated from ADMIN (transaction operations). Safe/idempotent on
 * an already-populated production database: it only inserts the role row the
 * hierarchy and policies already expect, touching no existing data.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->updateOrInsert(
            ['slug' => 'keuangan'],
            ['name' => 'Keuangan', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('slug', 'keuangan')->value('id');

        if (! $roleId) {
            return;
        }

        // Never drop the role out from under a real account — the users.role_id
        // FK is restrictOnDelete and would throw anyway; this makes the intent
        // explicit instead of surfacing as a raw SQL error.
        if (DB::table('users')->where('role_id', $roleId)->exists()) {
            return;
        }

        DB::table('roles')->where('id', $roleId)->delete();
    }
};
