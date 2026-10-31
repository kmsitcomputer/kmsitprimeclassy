<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable, versioned record of an order's invoice balance state (version 1 = issuance). */
class OrderInvoiceSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'order_id', 'version', 'trigger', 'invoice_number', 'total_amount',
        'verified_paid_amount', 'outstanding_amount', 'payment_status', 'due_date',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2', 'verified_paid_amount' => 'decimal:2',
            'outstanding_amount' => 'decimal:2', 'due_date' => 'date',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
