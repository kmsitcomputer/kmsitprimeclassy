<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * B-01: immutable order-wide financial allocation snapshot. One per order (UNIQUE order_id);
 * later basis-requiring events reuse it and never re-allocate.
 */
class OrderFinancialSnapshot extends Model
{
    protected $fillable = [
        'order_id', 'snapshot_version', 'trigger', 'subtotal_basis', 'effective_voucher_basis',
        'voucher_source_type', 'voucher_source_id', 'allocation_method', 'allocation_checksum',
        'frozen_at', 'frozen_by', 'provenance', 'migration_batch_id', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_version' => 'integer',
            'subtotal_basis' => 'decimal:2',
            'effective_voucher_basis' => 'decimal:2',
            'frozen_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \RuntimeException('order_financial_snapshots is immutable after insert.');
        });

        static::deleting(function (): void {
            throw new \RuntimeException('order_financial_snapshots rows are never deleted.');
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(OrderFinancialSnapshotUnit::class, 'snapshot_id');
    }

    public function bases(): HasMany
    {
        return $this->hasMany(OrderItemFinancialBasis::class, 'snapshot_id');
    }

    public function isProvisionalLegacy(): bool
    {
        return $this->provenance === 'provisional_legacy';
    }
}
