<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Canonical invoice/order number allocator (Owner 2026-10-29, workstream 1).
 *
 * Format:  D-MMYY#####   (D- + month + year + 5-digit GLOBAL running sequence)
 * Example: D-102600001, D-102600002 (next month: D-112600003 — never resets).
 *
 * Contract:
 *  - `orders.order_no` stays the ONLY invoice identifier (no second id).
 *  - Allocation MUST happen inside the order-creation DB transaction; the
 *    settings-row `lockForUpdate()` serialises concurrent creators so two
 *    orders can never receive the same number. Never a bare MAX() over orders.
 *  - Historic numbers are never touched (only newly created orders use this).
 *  - After 99999 the sequence rolls over to 00001. If the complete candidate
 *    already exists (same MMYY namespace exhausted) it FAILS SAFELY with 409 —
 *    no silent reuse or overwrite.
 *  - A replay of the same Idempotency-Key returns the existing order BEFORE
 *    this service is reached, so a replay never burns a sequence number.
 */
final class InvoiceNumberService
{
    public const SETTING_KEY = 'orders.invoice_sequence';

    public const MAX_SEQUENCE = 99999;

    public function next(): string
    {
        DB::table('settings')->insertOrIgnore([
            'key' => self::SETTING_KEY,
            'value' => '0',
            'group' => 'orders',
            'is_public' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('settings')->where('key', self::SETTING_KEY)->lockForUpdate()->first();
        $next = ((int) ($row->value ?? 0)) + 1;
        if ($next > self::MAX_SEQUENCE) {
            $next = 1; // documented rollover
        }

        $candidate = 'D-'.now()->format('my').str_pad((string) $next, 5, '0', STR_PAD_LEFT);
        if (DB::table('orders')->where('order_no', $candidate)->exists()) {
            // Rollover collision inside the same MMYY namespace — refuse.
            throw new ApiException(
                'Rentang nomor invoice untuk periode ini sudah habis. Nomor tidak pernah dipakai ulang; '
                .'selesaikan konflik kapasitas sebelum membuat pesanan baru.',
                409,
            );
        }

        DB::table('settings')->where('key', self::SETTING_KEY)->update([
            'value' => (string) $next,
            'updated_at' => now(),
        ]);

        return $candidate;
    }
}