<?php

namespace App\Support;

/** Normalises user-entered Indonesian/international numbers to the digits-only msisdn WhatsApp expects (62812…). */
final class WhatsAppNumber
{
    public static function msisdn(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }

        return strlen($digits) >= 9 && strlen($digits) <= 15 ? $digits : null;
    }

    /** `62812••••345` — recognisable to an operator, useless for harvesting. */
    public static function mask(?string $msisdn): ?string
    {
        if ($msisdn === null || $msisdn === '') {
            return null;
        }

        return substr($msisdn, 0, 2).'••••'.substr($msisdn, -3);
    }
}
