<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Layer 3 — verified money that actually left. Created only from a succeeded attempt. */
class RefundPayout extends Model
{
    protected $fillable = [
        'payout_no', 'obligation_id', 'order_id', 'attempt_id', 'payout_key', 'amount',
        'method', 'paid_at', 'proof_media_id', 'proof_type', 'gateway_reference',
        'verification_status', 'affects_paid_amount', 'paid_effect_applied_at',
        'recorded_on_cancelled_obligation', 'migration_batch_id', 'legacy_source', 'metadata',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'paid_effect_applied_at' => 'datetime',
            'affects_paid_amount' => 'boolean',
            'recorded_on_cancelled_obligation' => 'boolean',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (RefundPayout $payout): void {
            $dirty = array_keys($payout->getDirty());
            $allowed = ['verification_status', 'updated_at'];

            if (array_diff($dirty, $allowed) !== []) {
                throw new \RuntimeException('refund_payouts rows are immutable; only verification_status may change.');
            }

            if ($payout->isDirty('verification_status')) {
                $from = (string) $payout->getOriginal('verification_status');
                $to = (string) $payout->verification_status;

                if ($from !== 'legacy_unverified' || $to !== 'proven') {
                    throw new \RuntimeException('refund_payouts verification may only go legacy_unverified -> proven.');
                }

                if (! (bool) $payout->affects_paid_amount) {
                    throw new \RuntimeException('Only payouts that affect paid_amount may become proven.');
                }
            }
        });

        static::deleting(function (): void {
            throw new \RuntimeException('refund_payouts rows are never deleted.');
        });
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(RefundObligation::class, 'obligation_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(RefundAttempt::class, 'attempt_id');
    }
}
