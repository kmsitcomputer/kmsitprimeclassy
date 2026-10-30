<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Layer 1 — money lawfully owed back. One source may yield multiple obligations. */
class RefundObligation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_NEEDS_VERIFICATION = 'needs_verification';

    public const STATUS_CANCELLED = 'cancelled';

    public const OPEN_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_PARTIALLY_PAID,
        self::STATUS_NEEDS_VERIFICATION,
    ];

    protected $fillable = [
        'obligation_no', 'order_id', 'source_type', 'source_id', 'obligation_key', 'amount',
        'status', 'provenance', 'reason', 'approved_by', 'approved_at', 'approval_source',
        'completed_at', 'cancelled_at', 'cancel_reason', 'snapshot_id', 'deferred_amount',
        'deferred_remaining', 'deferred_reason', 'parent_obligation_id', 'migration_batch_id',
        'legacy_source_type', 'legacy_source_id', 'created_by', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'deferred_amount' => 'decimal:2',
            'deferred_remaining' => 'decimal:2',
            'approved_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (RefundObligation $obligation): void {
            if ($obligation->isDirty('amount') && $obligation->getOriginal('approved_at') !== null) {
                throw new \RuntimeException('refund_obligations.amount is immutable after approval; create a new manual obligation instead.');
            }
        });

        static::deleting(function (): void {
            throw new \RuntimeException('refund_obligations rows are never deleted.');
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(OrderFinancialSnapshot::class, 'snapshot_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(RefundAttempt::class, 'obligation_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(RefundPayout::class, 'obligation_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_obligation_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_obligation_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isZeroAmountDeferredContainer(): bool
    {
        return (float) $this->amount === 0.0 && (float) $this->deferred_amount > 0.0;
    }

    public function remainingPayable(): float
    {
        return round(max(0.0, (float) $this->amount - (float) $this->payouts()->sum('amount')), 2);
    }
}
