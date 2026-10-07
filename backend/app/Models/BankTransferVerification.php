<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankTransferVerification extends Model
{
    protected $fillable = [
        'payment_transaction_id', 'bank_name', 'account_name', 'account_number',
        'proof_image_path', 'verified_by', 'verified_at', 'status', 'rejection_reason',
        'submitted_by_user_id', 'submitted_on_behalf',
    ];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'submitted_on_behalf' => 'boolean'];
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }
}
