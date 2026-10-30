<?php

namespace App\Services\Refund;

/**
 * Integer-cent arithmetic for the refund ledger. Floating point is never used for allocation or
 * comparison: every money value enters as a decimal string/float and is rounded to cents once.
 */
final class Money
{
    public static function cents(float|int|string|null $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    public static function amount(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function float(int $cents): float
    {
        return $cents / 100;
    }
}
