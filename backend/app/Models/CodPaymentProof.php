<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CodPaymentProof extends Model
{
    protected $fillable = [
        'payment_transaction_id', 'proof_media_id', 'status', 'confirmed_by', 'confirmed_at', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime'];
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }

    public function proof(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'proof_media_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
