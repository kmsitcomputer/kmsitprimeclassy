<?php

namespace App\Support;

final class PhoneNormalizer
{
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $phone = trim($phone);

        if ($phone === '') {
            return '';
        }

        $compact = preg_replace('/[\\s().-]+/', '', $phone);

        if (! is_string($compact)) {
            return $phone;
        }

        if (preg_match('/^\\+628[0-9]+$/', $compact)) {
            return '0'.substr($compact, 3);
        }

        if (preg_match('/^628[0-9]+$/', $compact)) {
            return '0'.substr($compact, 2);
        }

        if (preg_match('/^08[0-9]+$/', $compact)) {
            return $compact;
        }

        return $phone;
    }
}
