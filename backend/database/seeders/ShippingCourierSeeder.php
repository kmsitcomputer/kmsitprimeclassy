<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seed data for the shipping_couriers master registry (see its migration).
 *
 * SOURCE / PROVENANCE — this list could NOT be fully verified against
 * Komerce's own live documentation (https://collaborator.komerce.id/docs/...
 * renders via client-side JS; not fetchable as static content at seeding
 * time) — it is compiled from:
 *   - Community aggregation of Komerce's public "Shipping Cost API V2"
 *     announcement/tutorial content (courier codes used in real
 *     domestic-cost examples: jne, sicepat, jnt, tiki, pos, ninja, anteraja,
 *     lion, ncs, rpx, sap, sentral, star, wahana, dse, ide, rex).
 *   - `idexpress`/other codes are deliberately NOT included — unverifiable
 *     at seed time.
 *
 * This is a STARTING POINT, not a guarantee — Super Admin should verify
 * against their own Komerce account/dashboard and correct via the
 * shipping-couriers admin endpoint (deactivate what isn't actually
 * supported, add what's missing) rather than trusting this blindly.
 * updateOrInsert by `code` keeps this idempotent and never clobbers an
 * `is_active` flip Super Admin has already made.
 */
class ShippingCourierSeeder extends Seeder
{
    public function run(): void
    {
        $couriers = [
            ['code' => 'jne', 'name' => 'JNE'],
            ['code' => 'pos', 'name' => 'POS Indonesia'],
            ['code' => 'tiki', 'name' => 'TIKI'],
            ['code' => 'jnt', 'name' => 'J&T Express'],
            ['code' => 'sicepat', 'name' => 'SiCepat'],
            ['code' => 'anteraja', 'name' => 'AnterAja'],
            ['code' => 'ninja', 'name' => 'Ninja Xpress'],
            ['code' => 'lion', 'name' => 'Lion Parcel'],
            ['code' => 'rpx', 'name' => 'RPX'],
            ['code' => 'sap', 'name' => 'SAP Express'],
            ['code' => 'ncs', 'name' => 'Nusantara Card Semesta (NCS)'],
            ['code' => 'wahana', 'name' => 'Wahana'],
            ['code' => 'star', 'name' => 'Star Cargo'],
            ['code' => 'sentral', 'name' => 'Sentral Cargo'],
            ['code' => 'dse', 'name' => '21 Express (DSE)'],
            ['code' => 'ide', 'name' => 'ID Express'],
            ['code' => 'rex', 'name' => 'REX Kirim Express'],
        ];

        foreach ($couriers as $courier) {
            DB::table('shipping_couriers')->updateOrInsert(
                ['code' => $courier['code']],
                ['name' => $courier['name'], 'is_active' => true, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }
}
