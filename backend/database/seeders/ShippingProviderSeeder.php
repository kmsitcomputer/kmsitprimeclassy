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
            ['code' => 'kmsit', 'name' => 'KMSIT Routing Server'],
        ];

        foreach ($providers as $provider) {
            // Missing rows start inactive; an existing row's activation state is never overwritten.
            if (DB::table('shipping_providers')->where('code', $provider['code'])->exists()) {
                continue;
            }
            DB::table('shipping_providers')->insert([
                'code' => $provider['code'], 'name' => $provider['name'], 'is_active' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
