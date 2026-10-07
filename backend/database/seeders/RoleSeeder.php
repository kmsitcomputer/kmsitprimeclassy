<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    /**
     * Fixed system role taxonomy required for users.role_id to have anything to
     * reference — not business/demo data, just the closed set of 10 roles the
     * hierarchy is built on.
     */
    public function run(): void
    {
        $roles = [
            ['slug' => 'super_admin', 'name' => 'Super Admin'],
            ['slug' => 'agen', 'name' => 'Agen'],
            ['slug' => 'korsal', 'name' => 'Korsal'],
            ['slug' => 'sales', 'name' => 'Sales'],
            ['slug' => 'konsumen', 'name' => 'Konsumen'],
            ['slug' => 'admin', 'name' => 'Admin'],
            ['slug' => 'keuangan', 'name' => 'Keuangan'],
            ['slug' => 'kurir', 'name' => 'Kurir'],
            ['slug' => 'gudang', 'name' => 'Gudang'],
            // IMP-003: Agen-managed delivery dispatcher — same branch, never
            // a separate hierarchy node (see HierarchyRules / Role::canonicalSlug
            // for the role-based scoping).
            ['slug' => 'koordinator-kurir', 'name' => 'Koordinator Kurir'],
            ['slug' => 'sales-kurir-sub', 'name' => 'Sales-Kurir-Sub'],
        ];

        // R-01: rename the pre-existing legacy row in place (never insert an 11th role).
        if (DB::table('roles')->where('slug', 'sales-kurir')->exists() && ! DB::table('roles')->where('slug', 'sales-kurir-sub')->exists()) {
            DB::table('roles')->where('slug', 'sales-kurir')->update(['slug' => 'sales-kurir-sub']);
        }

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['slug' => $role['slug']],
                ['name' => $role['name'], 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }
}
