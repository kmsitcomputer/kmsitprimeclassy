<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Only fixed system taxonomy is seeded here (roles, base languages) —
     * no demo users, no dummy business data.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            LanguageSeeder::class,
            SettingSeeder::class,
            PaymentMethodSeeder::class,
            ShippingProviderSeeder::class,
            ShippingCourierSeeder::class,
        ]);
    }
}
