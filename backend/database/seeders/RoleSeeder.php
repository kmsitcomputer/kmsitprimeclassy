<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    /**
     * Fixed system role taxonomy required for users.role_id to have anything to
     * reference — not business/demo data, just the closed set of 8 roles the
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
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['slug' => $role['slug']],
                ['name' => $role['name'], 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }
}
