<?php

namespace App\Services\User;

use App\Exceptions\ApiException;
use App\Models\Courier;
use App\Models\Role;
use App\Models\User;
use App\Support\HierarchyRules;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only place internal-role accounts (agen/korsal/sales/admin/keuangan/kurir)
 * get created. Enforces, server-side and independent of anything the client
 * sends: who is allowed to create which role (HierarchyRules), that
 * admin/keuangan/kurir are always linked to a real agent, and that referral
 * codes exist only for the roles that own one. Konsumen self-registration is a
 * separate path (see ReferralService + AuthController::register) and never
 * goes through here.
 */
class UserManagementService
{
    /**
     * @param  array{name:string, email:string, phone:string, password:string, agent_id?:int, korsal_id?:int}  $data
     */
    public function create(User $creator, string $targetRoleSlug, array $data): User
    {
        $creatorRoleSlug = $creator->role?->slug;

        if (! $creatorRoleSlug || ! HierarchyRules::canCreate($creatorRoleSlug, $targetRoleSlug)) {
            throw new ApiException(__('messages.user.role_not_authorized', ['role' => $creatorRoleSlug, 'target' => $targetRoleSlug]), 403);
        }

        if ($creator->isRole('agen', 'korsal')) {
            if (! $creator->agent_id || ($creator->isRole('agen') && $creator->agent_id !== $creator->id)) {
                throw new ApiException('Network Agen tidak valid.', 422);
            }
            if (isset($data['agent_id']) && (int) $data['agent_id'] !== $creator->agent_id) {
                throw new ApiException('Tidak dapat membuat user pada network Agen lain.', 422);
            }
            if ($creator->isRole('korsal') && isset($data['korsal_id']) && (int) $data['korsal_id'] !== $creator->id) {
                throw new ApiException('Korsal hanya dapat membuat Sales di bawah dirinya.', 422);
            }
        }

        return DB::transaction(function () use ($creator, $creatorRoleSlug, $targetRoleSlug, $data) {
            [$parentId, $agentId, $korsalId] = match (true) {
                $targetRoleSlug === 'agen' => [null, null, null],

                $targetRoleSlug === 'korsal' && $creatorRoleSlug === 'agen' => [$creator->id, $creator->agent_id, null],

                $targetRoleSlug === 'sales' && $creatorRoleSlug === 'korsal' => [$creator->id, $creator->agent_id, $creator->id],

                $targetRoleSlug === 'sales' && $creatorRoleSlug === 'agen' => [
                    $korsalId = $this->resolveRequiredKorsalUnderAgent($data['korsal_id'] ?? null, $creator->id),
                    $creator->id,
                    $korsalId,
                ],

                // An agen-created admin/keuangan/kurir always belongs to the
                // creator's own branch — never trust a client-supplied agent_id
                // here, or an agen could spoof another agent's id (same reasoning
                // as the sales+agen branch above).
                in_array($targetRoleSlug, ['admin', 'keuangan', 'kurir'], true) && $creatorRoleSlug === 'agen' => [$creator->id, $creator->agent_id, null],

                default => throw new ApiException(__('messages.user.unsupported_role_combination'), 422),
            };

            $roleId = Role::query()->where('slug', $targetRoleSlug)->value('id');
            $ownsReferralCode = HierarchyRules::ownsReferralCode($targetRoleSlug);

            $baseAttributes = [
                'role_id' => $roleId,
                'parent_id' => $parentId,
                'agent_id' => $agentId,
                'korsal_id' => $korsalId,
                'sales_id' => null,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password' => $data['password'],
                'status' => 'active',
            ];

            $user = $this->createWithUniqueReferralCode($baseAttributes, $ownsReferralCode ? $targetRoleSlug : null);

            if ($targetRoleSlug === 'agen') {
                // An agen is the root of its own branch — self-reference so every
                // agent-scoped query (BelongsToAgentScope) naturally includes them.
                $user->update(['agent_id' => $user->id]);
            }

            if ($targetRoleSlug === 'kurir') {
                // "Kurir wajib berada di bawah Agen" — the Courier profile row
                // (assignable to shipments, carries is_active) is created
                // alongside the account itself, never as a separate step an
                // admin could forget.
                Courier::create([
                    'type' => 'internal', 'user_id' => $user->id, 'agent_id' => $agentId,
                    'name' => $user->name, 'is_active' => true,
                ]);
            }

            return $user->fresh();
        });
    }

    private function resolveRequiredKorsalUnderAgent(?int $korsalId, ?int $agentId): ?int
    {
        if (! $korsalId) {
            throw new ApiException('Korsal wajib dipilih.', 422, ['korsal_id' => 'Korsal wajib dipilih.']);
        }

        $belongsToAgent = User::query()->whereHas('role', fn ($q) => $q->where('slug', 'korsal'))
            ->where('id', $korsalId)->where('agent_id', $agentId)->exists();

        if (! $belongsToAgent) {
            throw new ApiException(__('messages.user.invalid_korsal'), 422, ['korsal_id' => __('messages.system.field_invalid')]);
        }

        return $korsalId;
    }

    /**
     * A pre-check (Str::random collision odds are astronomically low, but
     * not zero) is not race-proof by itself under concurrent requests — the
     * real guarantee is the `users.referral_code` unique index. If two
     * requests somehow generate the same code and both pass the pre-check,
     * the second INSERT is rejected by the database and retried with a
     * fresh code, rather than silently succeeding with a collided code.
     */
    private function createWithUniqueReferralCode(array $baseAttributes, ?string $referralRoleSlug, int $attemptsLeft = 5): User
    {
        $attributes = $baseAttributes;
        $attributes['referral_code'] = $referralRoleSlug ? $this->generateCandidateReferralCode($referralRoleSlug) : null;

        try {
            return User::create($attributes);
        } catch (QueryException $e) {
            $isDuplicateReferralCode = $referralRoleSlug
                && str_contains($e->getMessage(), 'referral_code');

            if ($isDuplicateReferralCode && $attemptsLeft > 0) {
                return $this->createWithUniqueReferralCode($baseAttributes, $referralRoleSlug, $attemptsLeft - 1);
            }

            throw $e;
        }
    }

    /** Public — also reused by ProfileController::regenerateReferralCode for a role's own self-service regenerate action. */
    public function generateCandidateReferralCode(string $roleSlug): string
    {
        $prefix = strtoupper(substr($roleSlug, 0, 2));

        do {
            $code = $prefix.'-'.strtoupper(Str::random(6));
        } while (User::query()->where('referral_code', $code)->exists());

        return $code;
    }
}
