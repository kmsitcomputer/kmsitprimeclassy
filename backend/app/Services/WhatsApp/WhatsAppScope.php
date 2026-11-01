<?php

namespace App\Services\WhatsApp;

use App\Exceptions\ApiException;
use App\Models\User;

/**
 * Resolves WHICH Agen a WhatsApp management call acts on — server-side only (WA-DEC-01).
 *
 *  - agen  → always itself.
 *  - admin → the one Agen whose branch it belongs to (users.agent_id). The org model gives an Admin exactly one branch.
 *  - anyone else (incl. super_admin: WA-DEC-04) → 403.
 *
 * A client-supplied agent_id is only ever a *selection among the actor's authorised set*; anything else is a 403, never a
 * silent fallback to the actor's own scope.
 */
class WhatsAppScope
{
    /** @return array<int, array{id:int, name:string}> Agen the actor may manage. */
    public function authorised(User $actor): array
    {
        $agent = match (true) {
            $actor->isRole('agen') => $actor,
            $actor->isRole('admin') && $actor->agent_id => User::query()->find($actor->agent_id),
            default => null,
        };

        if (! $agent || ! $agent->isRole('agen')) {
            return [];
        }

        return [['id' => $agent->id, 'name' => (string) $agent->name]];
    }

    public function resolve(User $actor, mixed $requestedAgentId = null): User
    {
        $allowed = $this->authorised($actor);
        if ($allowed === []) {
            throw new ApiException(__('messages.whatsapp.scope_forbidden'), 403);
        }

        if ($requestedAgentId !== null && $requestedAgentId !== '') {
            if (! is_numeric($requestedAgentId) || ! in_array((int) $requestedAgentId, array_column($allowed, 'id'), true)) {
                throw new ApiException(__('messages.whatsapp.scope_forbidden'), 403);
            }
        }

        return User::query()->findOrFail($allowed[0]['id']);
    }
}
