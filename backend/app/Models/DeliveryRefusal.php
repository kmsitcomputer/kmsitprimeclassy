<?php

namespace App\Models;

use App\Models\Scopes\BelongsToAgentScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** REM-003 — a customer's refusal of goods at delivery; custody chain courier → Koordinator → redelivery/Gudang. */
class DeliveryRefusal extends Model
{
    public const OPEN_STATUSES = ['with_courier', 'handed_back', 'received'];

    protected static function booted(): void
    {
        static::addGlobalScope(new BelongsToAgentScope);
    }

    protected $fillable = [
        'agent_id', 'order_id', 'order_item_id', 'shipment_id', 'quantity', 'reason', 'status',
        'recorded_by', 'recorded_at', 'handed_back_by', 'handed_back_at', 'received_by', 'received_at',
        'disposition_by', 'disposition_at', 'disposition_note',
    ];

    protected function casts(): array
    {
        return ['agent_id' => 'integer', 'quantity' => 'integer', 'recorded_at' => 'datetime', 'handed_back_at' => 'datetime', 'received_at' => 'datetime', 'disposition_at' => 'datetime'];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
