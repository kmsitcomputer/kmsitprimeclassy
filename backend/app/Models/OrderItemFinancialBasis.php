<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Read-only per-line projection of the order-wide snapshot (B-01). */
class OrderItemFinancialBasis extends Model
{
    protected $fillable = [
        'order_id', 'order_item_id', 'snapshot_id', 'qty_basis', 'unit_gross_basis',
        'line_gross_basis', 'allocated_discount_total', 'line_net_basis',
        'consumed_units_count', 'consumed_gross_total', 'consumed_discount_total',
        'consumed_net_total', 'allocation_checksum', 'frozen_at', 'provenance',
        'migration_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'qty_basis' => 'integer',
            'consumed_units_count' => 'integer',
            'unit_gross_basis' => 'decimal:2',
            'line_gross_basis' => 'decimal:2',
            'allocated_discount_total' => 'decimal:2',
            'line_net_basis' => 'decimal:2',
            'consumed_gross_total' => 'decimal:2',
            'consumed_discount_total' => 'decimal:2',
            'consumed_net_total' => 'decimal:2',
            'frozen_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(OrderFinancialSnapshot::class, 'snapshot_id');
    }
}
