<?php

namespace App\Services\Logging;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes to `activity_logs` — the durable, queryable audit trail (Blueprint
 * §Audit Log) for actions that must be reconstructable later: who (actor +
 * role, via causer_id/subject relations plus 'actor_role' in properties by
 * convention), did what (event), to which record (subject_type/subject_id),
 * with what changed (old/new keys inside properties), from where (ip_address/
 * user_agent, captured automatically below — never something a caller
 * supplies, so it can't be spoofed), and when (created_at). Distinct from
 * Laravel's file/stack Log facade, which is for operational/error
 * diagnostics, not business audit.
 */
class ActivityLogger
{
    /** Property keys that must never be persisted even if a caller passes them by mistake. */
    private const REDACTED_KEYS = ['password', 'password_confirmation', 'token', 'secret', 'api_key', 'api_secret', 'access_token', 'refresh_token'];

    public static function log(?int $causerId, Model $subject, string $event, ?string $description = null, array $properties = []): ActivityLog
    {
        return ActivityLog::create([
            'causer_id' => $causerId,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'event' => $event,
            'description' => $description,
            'properties' => ($properties = self::redact($properties)) ? $properties : null,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent() ? substr(request()->userAgent(), 0, 500) : null,
        ]);
    }

    private static function redact(array $properties): array
    {
        foreach ($properties as $key => $value) {
            if (is_array($value)) {
                $properties[$key] = self::redact($value);
            } elseif (is_string($key) && in_array(strtolower($key), self::REDACTED_KEYS, true)) {
                $properties[$key] = '[redacted]';
            }
        }

        return $properties;
    }
}
