<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One allocated unit of the order-wide snapshot. Consumption is a conditional update
 * (WHERE consumed_at IS NULL); a unit is consumed at most once for any reason.
 */
class OrderFinancialSnapshotUnit extends Model
{
    protected $fillable = [
        'snapshot_id', 'order_item_id', 'unit_index', 'unit_gross', 'allocated_discount',
        'unit_net', 'consumed_at', 'consumed_by_type', 'consumed_by_id', 'consumption_seq',
    ];

    protected function casts(): array
    {
        return [
            'unit_index' => 'integer',
            'consumption_seq' => 'integer',
            'unit_gross' => 'decimal:2',
            'allocated_discount' => 'decimal:2',
            'unit_net' => 'decimal:2',
            'consumed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (OrderFinancialSnapshotUnit $unit): void {
            $allowed = ['consumed_at', 'consumed_by_type', 'consumed_by_id', 'consumption_seq', 'updated_at'];

            if (array_diff(array_keys($unit->getDirty()), $allowed) !== []) {
                throw new \RuntimeException('order_financial_snapshot_units allocations are immutable; only consumption fields may change.');
            }

            if ($unit->getOriginal('consumed_at') !== null) {
                throw new \RuntimeException('A consumed snapshot unit is final and cannot be changed again.');
            }
        });

        static::deleting(function (): void {
            throw new \RuntimeException('order_financial_snapshot_units rows are never deleted.');
        });
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(OrderFinancialSnapshot::class, 'snapshot_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
