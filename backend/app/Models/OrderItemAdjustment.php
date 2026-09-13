<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemAdjustment extends Model
{
    protected $fillable = [
        'order_item_id', 'adjusted_by', 'quantity_reduced', 'reason', 'refund_amount', 'refund_status',
    ];

    protected function casts(): array
    {
        return ['refund_amount' => 'decimal:2'];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function adjustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }
}
