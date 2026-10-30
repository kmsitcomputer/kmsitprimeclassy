<?php

namespace App\Services\Refund;

use App\Models\Order;
use App\Models\RefundLedgerEvent;
use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * Append-only ledger audit. An event_key makes the write once-only: a replay that tries to append
 * the same key is a silent no-op (never a second audit row).
 */
class RefundAudit
{
    /** @param array<string, mixed> $payload */
    public function record(
        string $eventType,
        ?Order $order = null,
        ?int $obligationId = null,
        ?int $attemptId = null,
        ?int $payoutId = null,
        ?string $eventKey = null,
        ?User $actor = null,
        ?string $reason = null,
        array $payload = [],
    ): ?RefundLedgerEvent {
        try {
            return RefundLedgerEvent::create([
                'order_id' => $order?->id,
                'obligation_id' => $obligationId,
                'attempt_id' => $attemptId,
                'payout_id' => $payoutId,
                'event_type' => $eventType,
                'event_key' => $eventKey,
                'actor_type' => $actor ? 'user' : 'system',
                'actor_id' => $actor?->id,
                'reason' => $reason,
                'payload' => $payload ?: null,
                'occurred_at' => now(),
            ]);
        } catch (QueryException $e) {
            if ($eventKey !== null && $this->isUniqueViolation($e)) {
                return null;
            }

            throw $e;
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['23000', '23505'], true)
            || str_contains(strtolower($e->getMessage()), 'duplicate');
    }
}
