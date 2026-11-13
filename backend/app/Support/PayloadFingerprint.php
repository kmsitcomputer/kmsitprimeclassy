<?php

namespace App\Support;

/**
 * Group 1 idempotency helper: a stable SHA-256 over a NORMALISED payload
 * (recursively key-sorted, scalar types canonicalised, nulls kept distinct
 * from empty strings). Same key + same fingerprint → replay; same key +
 * different fingerprint → conflict (HTTP 409). Uniqueness itself is enforced by
 * a DB unique index at the call site — never by an application-level check.
 */
class PayloadFingerprint
{
    /** @param  array<mixed>  $payload */
    public static function of(array $payload): string
    {
        return hash('sha256', json_encode(self::normalise($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function normalise(mixed $value): mixed
    {
        if (is_array($value)) {
            $isList = array_is_list($value);
            $out = array_map(fn ($v) => self::normalise($v), $value);
            if (! $isList) {
                ksort($out);
            }

            return $out;
        }
        if (is_float($value) || is_int($value)) {
            return (string) (is_float($value) ? round($value, 6) : $value);
        }
        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }
}
