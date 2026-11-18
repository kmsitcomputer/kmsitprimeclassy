<?php

namespace App\Services\Hierarchy;

use App\Exceptions\ApiException;
use App\Models\User;

/**
 * Group 1 foundation (ODR-01): the single, fail-closed answer to "which Agent
 * may this actor act for?".
 *
 *  - Agen         → themselves (an explicit different id is refused).
 *  - Admin        → the Agent they are linked to; a stale link (Agent deleted,
 *                   inactive, no longer an Agent) FAILS CLOSED — never repaired
 *                   or guessed.
 *  - Super Admin  → must name a valid Agent explicitly; there is no default.
 *  - everyone else → refused.
 *
 * "Valid" = exists, not soft-deleted, active status, Agen role. It is meant to
 * be re-run at every commit point (admission/approval/replay), not once.
 */
class ValidAgentResolver
{
    public function forActor(User $actor, ?int $explicitAgentId = null): User
    {
        if ($actor->isRole('super_admin')) {
            if ($explicitAgentId === null) {
                throw new ApiException('Super Admin harus memilih Agen secara eksplisit.', 422, ['agent_id' => 'Agen wajib dipilih.']);
            }

            return $this->assertValid($explicitAgentId);
        }

        if ($actor->isRole('agen')) {
            if ($explicitAgentId !== null && $explicitAgentId !== $actor->id) {
                throw new ApiException('Agen hanya dapat bertindak untuk dirinya sendiri.', 403);
            }

            return $this->assertValid($actor->id);
        }

        if ($actor->isRole('admin')) {
            if (! $actor->agent_id) {
                throw new ApiException('Admin belum terhubung ke Agen.', 403);
            }
            if ($explicitAgentId !== null && $explicitAgentId !== $actor->agent_id) {
                throw new ApiException('Admin hanya dapat bertindak untuk Agen yang terhubung.', 403);
            }

            return $this->assertValid($actor->agent_id);
        }

        throw new ApiException('Peran ini tidak boleh bertindak untuk Agen.', 403);
    }

    public function assertValid(int $agentId): User
    {
        // SoftDeletes global scope already excludes deleted Agents.
        $agent = User::query()->with('role')->whereKey($agentId)->first();
        if (! $agent || $agent->status !== 'active' || ! $agent->isRole('agen')) {
            throw new ApiException('Agen tidak valid atau tidak aktif.', 403, ['agent_id' => 'Agen tidak valid.']);
        }

        return $agent;
    }

    public function isValid(int $agentId): bool
    {
        try {
            $this->assertValid($agentId);

            return true;
        } catch (ApiException) {
            return false;
        }
    }
}
