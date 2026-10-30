<?php

namespace App\Services\Refund;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\RefundRecipientEvidence;

/**
 * G17-D4: a refund on a CRM-origin order has no buyer account to pay back to, so no money may move
 * until the recipient (identity + method) and the authorization evidence are on record. Account-origin
 * orders are never affected. This only READS evidence; it never writes the refund ledger.
 */
class RefundRecipientGuard
{
    public static function assertReady(Order $order): void
    {
        if (! $order->isCrmOrigin()) {
            return;
        }

        $latest = RefundRecipientEvidence::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->orderByDesc('id')
            ->first();

        if (! $latest) {
            throw new ApiException(__('messages.crm.recipient_evidence_required'), 422, [
                'code' => 'refund_recipient_evidence_required',
            ]);
        }
    }
}
