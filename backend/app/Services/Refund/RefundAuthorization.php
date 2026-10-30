<?php

namespace App\Services\Refund;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\User;

/**
 * Role gate for refund operations (design §7).
 *
 * Finance = `keuangan` (refund lifecycle, forward needs_verification Exit A, materialization
 * initiation). Owner = the branch owner (`agen` of the order's agent, or super_admin) for
 * historical-balance changes, Exit B, reinstatement and every deferred materialization (D2).
 */
class RefundAuthorization
{
    public function isFinance(User $actor): bool
    {
        return $actor->isRole('keuangan', 'super_admin');
    }

    public function isOwner(User $actor, ?Order $order = null): bool
    {
        if ($actor->isRole('super_admin')) {
            return true;
        }

        return $actor->isRole('agen')
            && $order !== null
            && (int) $actor->agent_id === (int) $order->agent_id;
    }

    public function assertFinance(User $actor): void
    {
        if (! $this->isFinance($actor)) {
            throw new ApiException('Hanya Keuangan yang dapat melakukan aksi ini.', 403, [
                'code' => 'refund_role_forbidden',
            ]);
        }
    }

    public function assertOwner(User $actor, Order $order): void
    {
        if (! $this->isOwner($actor, $order)) {
            throw new ApiException('Aksi ini memerlukan persetujuan Owner/Agent.', 403, [
                'code' => 'refund_owner_approval_required',
            ]);
        }
    }
}
