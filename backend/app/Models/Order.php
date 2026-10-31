<?php

namespace App\Models;

use App\Models\Scopes\BelongsToAgentScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    /** G17: buyer origin / placer / buyer snapshot are write-once once they hold a value. */
    private const WRITE_ONCE = ['crm_customer_id', 'placed_by_user_id', 'buyer_name_snapshot', 'buyer_phone_snapshot'];

    protected static function booted(): void
    {
        static::addGlobalScope(new BelongsToAgentScope);

        static::saving(function (Order $order): void {
            // Exactly one authoritative origin — never an account AND a CRM customer at once.
            if ($order->konsumen_id !== null && $order->crm_customer_id !== null) {
                throw new \LogicException('An order has exactly one buyer origin (konsumen_id XOR crm_customer_id).');
            }

            if ($order->exists) {
                foreach (self::WRITE_ONCE as $field) {
                    if ($order->isDirty($field) && $order->getOriginal($field) !== null) {
                        throw new \LogicException("orders.{$field} is write-once and cannot be changed after placement.");
                    }
                }
            }
        });
    }

    protected $fillable = [
        'order_no', 'idempotency_key', 'konsumen_id', 'sales_id', 'korsal_id', 'agent_id',
        'crm_customer_id', 'placed_by_user_id', 'buyer_name_snapshot', 'buyer_phone_snapshot',
        'payment_method_id', 'source_address_id',
        'status', 'payment_status',
        'subtotal_amount', 'discount_amount', 'shipping_fee_amount', 'admin_fee_amount',
        'dp_amount', 'paid_amount', 'gross_received_amount', 'remaining_amount', 'total_amount',
        'recipient_name_snapshot', 'recipient_phone_snapshot', 'address_snapshot',
        'village_snapshot', 'district_snapshot', 'regency_snapshot', 'province_snapshot',
        'latitude_snapshot', 'longitude_snapshot',
        // IMP-002: canonical region ids + postal code + voucher attribution.
        'province_id', 'regency_id', 'district_id', 'village_id', 'postal_code', 'voucher_id',
        'delivery_date_estimate', 'delivery_date_actual',
        'cancelled_at', 'cancelled_by', 'cancellation_reason', 'cancellation_financial_status',
        'cancellation_finalized_at', 'notes',
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
            'gross_received_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'latitude_snapshot' => 'decimal:7',
            'longitude_snapshot' => 'decimal:7',
            'delivery_date_estimate' => 'date',
            'delivery_date_actual' => 'datetime',
            'due_date' => 'date',
            'invoice_issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancellation_finalized_at' => 'datetime',
        ];
    }

    public function effectiveDiscountAmount(?float $subtotal = null): float
    {
        return round(min((float) $this->discount_amount, max(0.0, $subtotal ?? (float) $this->subtotal_amount)), 2);
    }

    public function konsumen(): BelongsTo
    {
        return $this->belongsTo(User::class, 'konsumen_id');
    }

    /** G17: CRM-origin buyer (mutually exclusive with `konsumen_id` for every new order). */
    public function crmCustomer(): BelongsTo
    {
        return $this->belongsTo(CrmCustomer::class, 'crm_customer_id');
    }

    /** G17-D2: who actually submitted the order. Never implies ownership or commission. */
    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by_user_id');
    }

    public function isCrmOrigin(): bool
    {
        return $this->crm_customer_id !== null;
    }

    /** Buyer's display name for either origin (account name, else the immutable CRM snapshot). */
    public function buyerName(): ?string
    {
        return $this->konsumen?->name ?? $this->buyer_name_snapshot;
    }

    /**
     * Transitional guard: the buyer-origin columns arrive with migration 2026_10_30_100000. Until it is
     * applied on a given database (e.g. DEV before the Owner approves it) code must keep working
     * without writing them. Cached per process; remove together with the migration rollout.
     */
    public static function supportsBuyerOrigin(): bool
    {
        static $supported = null;

        return $supported ??= \Illuminate\Support\Facades\Schema::hasColumn('orders', 'placed_by_user_id');
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

    /** R-03: Admin delivery-verification history across every shipment of this order. */
    public function deliveryVerifications(): HasManyThrough
    {
        return $this->hasManyThrough(DeliveryVerification::class, Shipment::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    public function stockRequest(): HasOne
    {
        return $this->hasOne(StockRequest::class);
    }

    public function financialSnapshot(): HasOne
    {
        return $this->hasOne(OrderFinancialSnapshot::class);
    }

    public function refundObligations(): HasMany
    {
        return $this->hasMany(RefundObligation::class);
    }

    public function refundPayouts(): HasMany
    {
        return $this->hasMany(RefundPayout::class);
    }

    public function salesReductions(): HasMany
    {
        return $this->hasMany(OrderSalesReduction::class);
    }

    public function ledgerEvents(): HasMany
    {
        return $this->hasMany(RefundLedgerEvent::class);
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
        return \App\Support\CollectibleReceivable::forOrder($this) > 0;
    }
}
