<?php

namespace App\Services\Catalog;

use App\Services\Hierarchy\ValidAgentResolver;
use App\Services\Referral\ReferralService;
use Illuminate\Http\Request;

/** Shared fail-closed Agent scope for the homepage and standalone ranking feed. */
class BestsellerAgentResolver
{
    public function __construct(private readonly ValidAgentResolver $agents, private readonly ReferralService $referrals) {}

    public function resolve(Request $request, array $data): ?int
    {
        $user = $request->user() ?? $request->user('sanctum');
        try {
            if ($user) {
                if ($user->isRole('super_admin')) {
                    return isset($data['agent_id']) ? $this->agents->assertValid((int) $data['agent_id'])->id : null;
                }

                return $user->agent_id ? $this->agents->assertValid((int) $user->agent_id)->id : null;
            }
            if (! empty($data['ref'])) {
                $chain = $this->referrals->resolveChainByCode($data['ref']);

                return $this->agents->assertValid((int) $chain['agent_id'])->id;
            }
        } catch (\App\Exceptions\ApiException) {
            return null; // fail closed to "hide", not to a global ranking
        }

        return null;
    }
}
