<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PaymentTransaction extends Model
{
    protected $fillable = [
        'order_id', 'payment_method_id', 'type', 'amount', 'status',
        'gateway_reference', 'raw_payload', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'raw_payload' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function bankTransferVerification(): HasOne
    {
        return $this->hasOne(BankTransferVerification::class);
    }

    public function codPaymentProof(): HasOne
    {
        return $this->hasOne(CodPaymentProof::class);
    }
}
