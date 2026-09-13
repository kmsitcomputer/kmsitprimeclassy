<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnItem extends Model
{
    protected $fillable = [
        'return_id', 'order_item_id', 'courier_id', 'quantity_returned', 'refund_amount',
        'restock', 'condition_note', 'status', 'refund_status',
    ];

    protected function casts(): array
    {
        return ['refund_amount' => 'decimal:2', 'restock' => 'boolean'];
    }

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class, 'return_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** Which kurir picked this specific return up — null until claimed (Blueprint: "setelah ada kurir yang pickup... tidak boleh tampil di kurir lain"). */
    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }
}
