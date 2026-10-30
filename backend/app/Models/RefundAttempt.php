<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Layer 2 — every disbursement attempt, including failed/unknown. Never deleted. */
class RefundAttempt extends Model
{
    public const STATUS_CREATED = 'created';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_CANCELLED = 'cancelled';

    public const IN_FLIGHT_STATUSES = [
        self::STATUS_CREATED,
        self::STATUS_PROCESSING,
        self::STATUS_UNKNOWN,
    ];

    protected $fillable = [
        'obligation_id', 'attempt_no', 'method', 'amount', 'status', 'idempotency_key',
        'external_reference', 'provider_event_id', 'payment_transaction_id', 'destination_snapshot',
        'proof_media_id', 'proof_type', 'initiated_at', 'failure_code', 'failure_message',
        'reconciliation_evidence', 'reconciled_by', 'reconciled_at', 'unknown_since',
        'request_payload', 'response_payload', 'started_at', 'finished_at', 'processed_by',
    ];

    protected function casts(): array
    {
        return [
            'attempt_no' => 'integer',
            'amount' => 'decimal:2',
            'destination_snapshot' => 'array',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'initiated_at' => 'datetime',
            'reconciled_at' => 'datetime',
            'unknown_since' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(RefundObligation::class, 'obligation_id');
    }

    public function payout(): HasOne
    {
        return $this->hasOne(RefundPayout::class, 'attempt_id');
    }

    public function isInFlight(): bool
    {
        return in_array($this->status, self::IN_FLIGHT_STATUSES, true);
    }
}
