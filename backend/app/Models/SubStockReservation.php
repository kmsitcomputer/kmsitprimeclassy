<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** R-02: one Sub stock reservation per order item — see SubStockService for the lifecycle. */
class SubStockReservation extends Model
{
    public const ACTIVE = 'active';

    public const CONSUMED = 'consumed';

    public const RELEASED = 'released';

    protected $fillable = [
        'agent_id', 'sub_location_id', 'order_item_id', 'product_id', 'product_variation_id', 'quantity', 'status',
        'reserved_by', 'consumed_at', 'consumed_by', 'released_at', 'released_by', 'release_reason',
    ];

    protected function casts(): array
    {
        return ['agent_id' => 'integer', 'sub_location_id' => 'integer', 'order_item_id' => 'integer', 'quantity' => 'integer', 'consumed_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function subLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseSubLocation::class, 'sub_location_id');
    }
}
