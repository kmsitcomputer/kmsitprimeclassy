<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankTransferVerification extends Model
{
    protected $fillable = [
        'payment_transaction_id', 'bank_name', 'account_name', 'account_number',
        'proof_image_path', 'verified_by', 'verified_at', 'status', 'rejection_reason',
        'submitted_by_user_id', 'submitted_on_behalf', 'paid_by_role', 'submitted_at',
        'channel', 'submitted_amount', 'verified_amount', 'amount_adjustment_reason',
        'cash_received_by_user_id', 'cash_received_at', 'receipt_reference', 'proof_disk',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime', 'submitted_on_behalf' => 'boolean', 'submitted_at' => 'datetime',
            'submitted_amount' => 'decimal:2', 'verified_amount' => 'decimal:2', 'cash_received_at' => 'datetime',
        ];
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function cashReceivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cash_received_by_user_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }
}
