<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only audit stream. UPDATE and DELETE are refused at the model boundary. */
class RefundLedgerEvent extends Model
{
    protected $fillable = [
        'order_id', 'obligation_id', 'attempt_id', 'payout_id', 'event_type', 'event_key',
        'actor_type', 'actor_id', 'reason', 'payload', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \RuntimeException('refund_ledger_events is append-only (UPDATE refused).');
        });

        static::deleting(function (): void {
            throw new \RuntimeException('refund_ledger_events is append-only (DELETE refused).');
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(RefundObligation::class, 'obligation_id');
    }
}
