<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'shipment_id', 'product_id', 'product_variation_id', 'additional_payment_id',
        'product_name_snapshot', 'variation_label_snapshot', 'sku_snapshot',
        'unit_price_snapshot', 'agent_fee_amount', 'sales_fee_amount', 'courier_fee_amount', 'subtotal_snapshot',
        'original_quantity', 'fulfilled_quantity',
        'cancelled_quantity', 'returned_quantity', 'refund_quantity', 'additional_quantity',
        'requested_delivery_date', 'status',
    ];

    /**
     * Mirrors Order::TRANSITIONS exactly (Blueprint: "status harus diperiksa
     * PER ORDER ITEM") — an item's own status can diverge from the order's
     * overall status (e.g. this item 'dibatalkan' at the fulfillment stage
     * while the rest of the order continues on to 'dikirim').
     */
    public const TRANSITIONS = [
        'diterima' => ['diproses', 'dibatalkan'],
        'diproses' => ['dikirim', 'dibatalkan'],
        'dikirim' => ['terkirim'],
        'terkirim' => ['pengembalian'],
        'pengembalian' => ['kembali'],
        'dibatalkan' => [],
        'kembali' => [],
    ];

    protected function casts(): array
    {
        return [
            'unit_price_snapshot' => 'decimal:2',
            'agent_fee_amount' => 'decimal:2',
            'sales_fee_amount' => 'decimal:2',
            'courier_fee_amount' => 'decimal:2',
            'subtotal_snapshot' => 'decimal:2',
            'requested_delivery_date' => 'date',
        ];
    }

    public function canTransitionTo(string $next): bool
    {
        return in_array($next, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class, 'product_variation_id');
    }

    public function additionalPayment(): BelongsTo
    {
        return $this->belongsTo(OrderAdditionalPayment::class, 'additional_payment_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(OrderItemAdjustment::class);
    }

    public function returnItems(): HasMany
    {
        return $this->hasMany(ReturnItem::class);
    }
}
