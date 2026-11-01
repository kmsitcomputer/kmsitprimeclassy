<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Additive: registers the KMSIT Routing Server provider row, INACTIVE. Never touches existing rows/activation states. */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('shipping_providers')->where('code', 'kmsit')->exists()) {
            DB::table('shipping_providers')->insert([
                'code' => 'kmsit', 'name' => 'KMSIT Routing Server', 'is_active' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $id = DB::table('shipping_providers')->where('code', 'kmsit')->value('id');
        if ($id) {
            DB::table('agent_shipping_provider_configs')->where('shipping_provider_id', $id)->delete();
            DB::table('agent_shipping_provider_settings')->where('shipping_provider_id', $id)->delete();
            DB::table('shipping_providers')->where('id', $id)->delete();
        }
    }
};
