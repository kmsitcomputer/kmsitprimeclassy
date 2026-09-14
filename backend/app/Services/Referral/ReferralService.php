<?php

namespace App\Services\Referral;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\SafeSchema;

/**
 * Resolves a Konsumen's referral chain (sales -> korsal -> agen) from a
 * referral_code at registration time. A code may belong to a sales, a
 * korsal, or an agen — a konsumen referred directly by a korsal or agen
 * simply has a null sales_id/korsal_id for the levels skipped (both are
 * nullable on the users table; commission logic already tolerates a null
 * sales_id). Once set, this chain is snapshotted onto the user row and is
 * not expected to silently change afterwards (Blueprint §Referral "Aturan
 * integritas") — reassigning it later is a deliberate, audited action, not
 * something this service does implicitly.
 */
class ReferralService
{
    /** @return array{parent_id:int, sales_id:?int, korsal_id:?int, agent_id:int} */
    public function resolveChainByCode(string $referralCode): array
    {
        $referrer = $this->findActiveReferrerByCode($referralCode);

        if (! $referrer->agent_id) {
            throw new ApiException(__('messages.referral.not_linked_to_agent'), 422);
        }

        $roleSlug = $referrer->role->slug;

        return [
            // The code owner is always the new konsumen's direct upline in
            // the user_closures adjacency list, regardless of which level
            // (sales/korsal/agen) they sit at.
            'parent_id' => $referrer->id,
            'sales_id' => $roleSlug === 'sales' ? $referrer->id : null,
            'korsal_id' => match ($roleSlug) {
                'sales' => $referrer->korsal_id,
                'korsal' => $referrer->id,
                default => null,
            },
            'agent_id' => $referrer->agent_id,
        ];
    }

    /**
     * Non-sensitive preview so the frontend can confirm "you were referred
     * by X" before submitting a full registration — never exposes anything
     * beyond first name / store name, and never accepts this as proof of
     * anything: the actual chain is still re-resolved server-side at
     * registration time regardless of what this returned.
     *
     * @return array{referrer_name:string, agent_store_name:?string}
     */
    public function previewByCode(string $referralCode): array
    {
        // Public — reachable on a completely fresh, unmigrated deployment
        // (installer wizard page, bots, monitoring) before `users` exists.
        // No referral code can ever be valid on such a database, so this is
        // the same clean "invalid code" 422 a real lookup miss would give,
        // rather than a raw QueryException (see SafeSchema's docblock).
        if (! SafeSchema::hasTable('users')) {
            throw new ApiException(__('messages.referral.invalid_code'), 422, ['referral_code' => __('messages.referral.code_not_found')]);
        }

        $referrer = $this->findActiveReferrerByCode($referralCode);

        return [
            'referrer_name' => $referrer->name,
            'agent_store_name' => $referrer->agent?->agentProfile?->store_name,
        ];
    }

    private function findActiveReferrerByCode(string $referralCode): User
    {
        // Codes are always generated/stored uppercase (see
        // UserManagementService::generateCandidateReferralCode and the
        // `regex:/^[A-Z0-9\-]+$/` rule on the self-service update endpoint),
        // so normalize input the same way rather than requiring an exact
        // case match on a link a visitor may have retyped by hand.
        $referralCode = strtoupper(trim($referralCode));

        $referrer = User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('slug', ['agen', 'korsal', 'sales']))
            ->where('status', 'active')
            ->where('referral_code', $referralCode)
            ->with('role')
            ->first();

        if (! $referrer) {
            throw new ApiException(__('messages.referral.invalid_code'), 422, ['referral_code' => __('messages.referral.code_not_found')]);
        }

        return $referrer;
    }
}
