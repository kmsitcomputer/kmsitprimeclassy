<?php

namespace App\Services\WhatsApp;

/**
 * Outcome of ONE gateway call, expressed in the only terms that matter for duplicate-safety:
 *
 *  - SENT     the provider acknowledged the message (2xx + SUCCESS).
 *  - RETRY    the message was provably NOT handed to WhatsApp (could not connect, device disconnected): retrying cannot duplicate.
 *  - FAILED   permanent and definite (bad credentials, rejected number, device not found): retrying cannot help.
 *  - UNKNOWN  the request may or may not have been delivered (timeout after connect, 5xx with no proof): NEVER auto-retried,
 *             a duplicate would be possible; an operator must reconcile first.
 */
final class GatewayResult
{
    public const SENT = 'sent';

    public const RETRY = 'retry';

    public const FAILED = 'failed';

    public const UNKNOWN = 'unknown';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $errorClass = null,
        public readonly ?string $message = null,
        public readonly ?string $providerMessageId = null,
        public readonly ?int $httpStatus = null,
    ) {}

    public static function sent(?string $providerMessageId, ?int $http = 200): self
    {
        return new self(self::SENT, null, null, $providerMessageId, $http);
    }

    public static function retry(string $class, string $message, ?int $http = null): self
    {
        return new self(self::RETRY, $class, self::sanitize($message), null, $http);
    }

    public static function failed(string $class, string $message, ?int $http = null): self
    {
        return new self(self::FAILED, $class, self::sanitize($message), null, $http);
    }

    public static function unknown(string $class, string $message, ?int $http = null): self
    {
        return new self(self::UNKNOWN, $class, self::sanitize($message), null, $http);
    }

    /** Error text is stored and shown to operators: it must never carry credentials or tokens. */
    public static function sanitize(string $message): string
    {
        $m = preg_replace('/(Authorization|Basic|Bearer)\s*[:=]?\s*[A-Za-z0-9+\/=._-]{6,}/i', '$1 [redacted]', $message) ?? '';
        $m = preg_replace('#(https?://)[^/\s@]+:[^/\s@]+@#', '$1[redacted]@', $m) ?? '';

        return mb_substr(trim(preg_replace('/\s+/', ' ', $m) ?? ''), 0, 200);
    }
}
