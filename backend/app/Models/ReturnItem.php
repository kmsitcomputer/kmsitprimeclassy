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
        // See User::casts() — courier_id is compared with strict ===/!== against
        // already-int ids (CourierReturnResource::picked_up_by_me,
        // ReturnService::claimReturnPickup). Uncast it returns as a string on
        // some PDO/MySQL driver builds, so picked_up_by_me stays false and the
        // "Refund"/"Terkirim" return buttons never render for the kurir.
        return [
            'return_id' => 'integer',
            'order_item_id' => 'integer',
            'courier_id' => 'integer',
            'refund_amount' => 'decimal:2',
            'restock' => 'boolean',
        ];
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
