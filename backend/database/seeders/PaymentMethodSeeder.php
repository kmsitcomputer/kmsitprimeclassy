<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Fixed payment-method taxonomy (system rows, not business/demo data — same
 * pattern as RoleSeeder). Gateway methods start inactive: each agen needs to
 * configure their own real credentials (agent_payment_gateway_configs)
 * before enabling their branch's use of them; cod/bank_transfer need no
 * external credentials so they start active. Selecting bank_transfer with
 * no config row yet for that agen still fails cleanly at checkout
 * (PaymentService) rather than fabricating a bank account number.
 */
class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            ['code' => 'cod', 'name' => 'Cash on Delivery', 'type' => 'cod', 'is_active' => true],
            ['code' => 'bank_transfer', 'name' => 'Transfer Bank', 'type' => 'manual', 'is_active' => true],
            // DP / down payment — a proof-based partial payment. Reuses the
            // branch's bank_transfer destination account (see
            // PaymentService::initiateDownPayment), so it needs no credentials
            // of its own and starts active.
            ['code' => 'down_payment', 'name' => 'DP / Down Payment', 'type' => 'manual', 'is_active' => true],
            ['code' => 'xendit', 'name' => 'Xendit', 'type' => 'gateway', 'is_active' => false],
            ['code' => 'tripay', 'name' => 'Tripay', 'type' => 'gateway', 'is_active' => false],
            ['code' => 'stripe', 'name' => 'Stripe', 'type' => 'gateway', 'is_active' => false],
        ];

        foreach ($methods as $method) {
            DB::table('payment_methods')->updateOrInsert(
                ['code' => $method['code']],
                [
                    'name' => $method['name'],
                    'type' => $method['type'],
                    'is_active' => $method['is_active'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
