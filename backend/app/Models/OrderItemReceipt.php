<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** REM-07 / D-06 A: append-only actual per-item receipt evidence. Never updated after insert. */
class OrderItemReceipt extends Model
{
    protected $fillable = [
        'order_item_id', 'order_id', 'agent_id', 'quantity', 'received_on', 'received_at', 'evidence_media_id',
        'evidence_note', 'verified_by', 'verified_at', 'idempotency_key', 'request_fingerprint',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'received_on' => 'date:Y-m-d', 'received_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
