<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Fixed system-configuration keys the app reads via Setting::get()/flag() —
 * safe defaults only, never a business/pricing decision on the user's behalf
 * (admin can change every value here from the dashboard afterwards).
 */
class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['key' => 'checkout.admin_fee_amount', 'value' => '0', 'group' => 'checkout'],
            ['key' => 'shipping.enabled', 'value' => '1', 'group' => 'shipping'],
            ['key' => 'shipping.distance_provider', 'value' => 'haversine', 'group' => 'shipping'],
        ];

        foreach ($settings as $setting) {
            DB::table('settings')->updateOrInsert(
                ['key' => $setting['key']],
                [
                    'value' => $setting['value'],
                    'group' => $setting['group'],
                    'is_public' => false,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
