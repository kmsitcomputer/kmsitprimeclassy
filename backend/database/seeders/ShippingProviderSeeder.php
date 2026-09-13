<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Fixed shipping-provider taxonomy (system rows, same pattern as
 * RoleSeeder/PaymentMethodSeeder) — both start inactive until Super Admin
 * configures real credentials; "Jika disabled: FREE SHIPPING" is the safe
 * out-of-the-box behavior.
 */
class ShippingProviderSeeder extends Seeder
{
    public function run(): void
    {
        $providers = [
            ['code' => 'rajaongkir', 'name' => 'RajaOngkir'],
            ['code' => 'openroute', 'name' => 'OpenRouteService'],
        ];

        foreach ($providers as $provider) {
            DB::table('shipping_providers')->updateOrInsert(
                ['code' => $provider['code']],
                [
                    'name' => $provider['name'],
                    'is_active' => false,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
