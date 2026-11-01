<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A link an admin types into a CMS block. Only destinations that cannot execute script are accepted:
 * absolute http(s), mailto:, tel:, a site-relative path ("/produk") or an in-page anchor ("#kontak").
 * Anything else — javascript:, data:, vbscript:, protocol-relative "//host", whitespace or control
 * characters — is rejected, so a stored link can never become an XSS vector when rendered in an href.
 */
class SafeLinkUrl implements ValidationRule
{
    public const PATTERN = '~^(?:https?://[^\s<>"\'\\\\]+|mailto:[^\s<>"\'\\\\]+|tel:\+?[0-9][0-9\-\s().]{3,30}|/(?!/)[^\s<>"\'\\\\]*|#[^\s<>"\'\\\\]*)$~i';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || preg_match('/[\x00-\x1F\x7F]/', $value) || ! preg_match(self::PATTERN, $value)) {
            $fail('Tautan harus berupa alamat http(s), mailto:, tel:, path situs (/…) atau anchor (#…) yang valid.');
        }
    }
}
