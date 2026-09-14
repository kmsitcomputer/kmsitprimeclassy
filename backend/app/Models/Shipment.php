<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    protected $fillable = [
        'order_id', 'courier_id', 'shipping_provider_id', 'shipping_provider_code',
        'origin_latitude', 'origin_longitude', 'destination_latitude', 'destination_longitude',
        'distance_km', 'rate_per_km', 'shipping_fee_snapshot', 'provider_meta', 'tracking_number', 'proof_media_id', 'status',
        'shipped_at', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            // See User::casts() — these FKs are read by strict ===/!== against
            // already-int ids (CourierOrderResource::itemVisibleToViewer,
            // CourierService::selfAssignIfUnassigned). On some PDO/MySQL driver
            // builds an uncast column returns as a string, so the match silently
            // fails and a kurir's own 'dikirim' item (and its "Terkirim" button)
            // disappears from their dashboard.
            'order_id' => 'integer',
            'courier_id' => 'integer',
            'shipping_provider_id' => 'integer',
            'proof_media_id' => 'integer',
            'origin_latitude' => 'decimal:7',
            'origin_longitude' => 'decimal:7',
            'destination_latitude' => 'decimal:7',
            'destination_longitude' => 'decimal:7',
            'distance_km' => 'decimal:2',
            'rate_per_km' => 'decimal:2',
            'shipping_fee_snapshot' => 'decimal:2',
            'provider_meta' => 'array',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ShippingProvider::class, 'shipping_provider_id');
    }

    /** The delivery photo a kurir must submit to move this shipment 'dikirim' -> 'terkirim'. */
    public function proof(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'proof_media_id');
    }

    /** The order_items this specific shipment/courier batch is responsible for — see migration docblock. */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
