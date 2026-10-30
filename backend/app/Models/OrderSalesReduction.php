<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderSalesReduction extends Model
{
    protected $fillable = [
        'order_id', 'source_type', 'source_id', 'amount', 'applied_to_order_total',
        'reinstated_at', 'reinstated_by', 'reinstatement_reason',
        'reinstatement_reconciliation_ref', 'provenance',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'applied_to_order_total' => 'boolean',
            'reinstated_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
