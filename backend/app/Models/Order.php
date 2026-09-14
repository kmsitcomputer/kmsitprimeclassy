<?php

namespace App\Models;

use App\Models\Scopes\BelongsToAgentScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new BelongsToAgentScope);
    }

    protected $fillable = [
        'order_no', 'idempotency_key', 'konsumen_id', 'sales_id', 'korsal_id', 'agent_id',
        'payment_method_id', 'source_address_id',
        'status', 'payment_status',
        'subtotal_amount', 'discount_amount', 'shipping_fee_amount', 'admin_fee_amount',
        'dp_amount', 'paid_amount', 'remaining_amount', 'total_amount',
        'recipient_name_snapshot', 'recipient_phone_snapshot', 'address_snapshot',
        'village_snapshot', 'district_snapshot', 'regency_snapshot', 'province_snapshot',
        'latitude_snapshot', 'longitude_snapshot',
        'delivery_date_estimate', 'delivery_date_actual',
        'cancelled_at', 'cancelled_by', 'cancellation_reason', 'notes',
    ];

    /** Valid status transitions — enforced in OrderService, not just documented here. */
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
            // See User::casts() — same strict-comparison-vs-uncast-column
            // footgun applies here (OrderPolicy compares these against
            // ->id/->agent_id with === / !==).
            'agent_id' => 'integer',
            'konsumen_id' => 'integer',
            'korsal_id' => 'integer',
            'sales_id' => 'integer',
            'subtotal_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'shipping_fee_amount' => 'decimal:2',
            'admin_fee_amount' => 'decimal:2',
            'dp_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'latitude_snapshot' => 'decimal:7',
            'longitude_snapshot' => 'decimal:7',
            'delivery_date_estimate' => 'date',
            'delivery_date_actual' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function konsumen(): BelongsTo
    {
        return $this->belongsTo(User::class, 'konsumen_id');
    }

    public function sales(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_id');
    }

    public function korsal(): BelongsTo
    {
        return $this->belongsTo(User::class, 'korsal_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function sourceAddress(): BelongsTo
    {
        return $this->belongsTo(KonsumenAddress::class, 'source_address_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function additionalPayments(): HasMany
    {
        return $this->hasMany(OrderAdditionalPayment::class);
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function returnRequests(): HasMany
    {
        return $this->hasMany(ReturnRequest::class, 'order_id');
    }

    /**
     * "Satu order bisa beberapa kurir karena ada kemungkinan produk yang bisa
     * di reschedule" — a rescheduled item splits onto its own Shipment (see
     * OrderFulfillmentService::rescheduleItemDeliveryDate), so an order may
     * have more than one, each independently courier-assigned/progressed.
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    public function canTransitionTo(string $next): bool
    {
        return in_array($next, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * A transaction is LUNAS only when its outstanding balance has reached
     * zero — never merely because a DP (partial payment) was verified.
     * Refund / additional-payment financial adjustments are only valid on a
     * fully-paid transaction (Blueprint §Refund vs Normal Payment Flow).
     */
    public function isFullyPaid(): bool
    {
        return $this->payment_status === 'paid' && (float) $this->remaining_amount <= 0;
    }

    public function hasOutstanding(): bool
    {
        return (float) $this->remaining_amount > 0;
    }
}
