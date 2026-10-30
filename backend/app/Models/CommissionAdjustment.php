<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WS-8 (Owner 2026-10-30, FINAL): signed commission-reversal ledger. The original commission row
 * stays immutable; a refund-driven fee cancellation is a NEGATIVE row here, so
 * SUM(commission.amount) + SUM(adjustment.amount) is the net entitlement for reconciliation and
 * period-end payout. Generation happens ONLY in `SalesFeeRefundAdjuster` (refund proven = the
 * return line's `refund_status = processed`), never by hand and never pro-rata on the refund
 * nominal.
 */
class CommissionAdjustment extends Model
{
    protected $fillable = [
        'commission_id', 'order_id', 'order_item_id', 'beneficiary_user_id',
        'beneficiary_role', 'amount', 'reason', 'refund_obligation_id', 'return_item_id', 'policy_version',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function commission(): BelongsTo
    {
        return $this->belongsTo(Commission::class);
    }

    public function returnItem(): BelongsTo
    {
        return $this->belongsTo(ReturnItem::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
