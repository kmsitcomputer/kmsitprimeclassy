<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * IMP-003 — adds the KOORDINATOR-KURIR role: a branch-level delivery
 * dispatcher created/managed by the Agen (same branch, agent_id required).
 * The role row is the only thing this migration touches — idempotent and
 * safe on populated production data (see the keuangan-role precedent).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->updateOrInsert(
            ['slug' => 'koordinator-kurir'],
            ['name' => 'Koordinator Kurir', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('slug', 'koordinator-kurir')->value('id');

        if (! $roleId) {
            return;
        }

        // Never drop the role out from under a real account (FK restrict).
        if (DB::table('users')->where('role_id', $roleId)->exists()) {
            return;
        }

        DB::table('roles')->where('id', $roleId)->delete();
    }
};