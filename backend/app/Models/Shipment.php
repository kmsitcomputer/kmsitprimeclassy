<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    /** Normal courier/office delivery — `courier_id` may be set. */
    public const DELIVERY_MODE_STANDARD = 'standard';

    /** Sales-Kurir-Sub self-delivery of Sub-sourced goods — `courier_id` is always NULL. */
    public const DELIVERY_MODE_SELF_SUB = 'self_sub';

    protected $fillable = [
        'order_id', 'courier_id', 'shipping_provider_id', 'shipping_provider_code',
        'origin_latitude', 'origin_longitude', 'destination_latitude', 'destination_longitude',
        'distance_km', 'rate_per_km', 'shipping_fee_snapshot', 'provider_meta', 'tracking_number', 'proof_media_id', 'status',
        'delivery_mode', 'self_delivered_by_user_id',
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
            'self_delivered_by_user_id' => 'integer',
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

    /** A Sub-sourced shipment delivered by its owning Sales-Kurir-Sub, never a normal Kurir. */
    public function isSelfDelivery(): bool
    {
        return $this->delivery_mode === self::DELIVERY_MODE_SELF_SUB;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    /** The Sales-Kurir-Sub who self-delivered this shipment — a stable user reference, not a Kurir. */
    public function selfDeliveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'self_delivered_by_user_id');
    }

    /** R-03 append-only Admin delivery-verification history for this shipment. */
    public function deliveryVerifications(): HasMany
    {
        return $this->hasMany(DeliveryVerification::class);
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
