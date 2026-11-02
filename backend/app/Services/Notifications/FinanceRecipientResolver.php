<?php

namespace App\Services\Notifications;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Recipients of "bukti pembayaran baru" (payment_proof_uploaded): ACTIVE Keuangan accounts of the order's own branch.
 *
 * Owner rule (2026-11-02): ONLY role `keuangan`. No fallback to Super Admin, Admin, Agen, the creator, the checkout
 * recipient or the consumer. An account that is also Keuangan keeps the right only through its own role and branch.
 * Channel gates (verified e-mail, WhatsApp proof) are applied by each channel, not here.
 */
final class FinanceRecipientResolver
{
    /** @return Collection<int, User> */
    public function for(Order $order): Collection
    {
        if ($order->agent_id === null) {
            return collect();
        }

        return User::query()
            ->whereHas('role', fn ($q) => $q->where('slug', 'keuangan'))
            ->where('agent_id', $order->agent_id)
            ->where('status', 'active')
            ->orderBy('id')
            ->get();
    }
}
