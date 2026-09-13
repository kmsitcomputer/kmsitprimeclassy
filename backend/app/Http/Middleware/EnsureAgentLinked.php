<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defensive guard for roles that must always belong to an agent (korsal,
 * sales, konsumen, admin, keuangan, kurir — see HierarchyRules::ROLES_REQUIRING_AGENT_LINK).
 * UserManagementService already refuses to create such an account without
 * one; this exists in case a row is ever left inconsistent (manual DB edit,
 * a future migration bug) — the account is blocked from acting instead of
 * silently operating with a null branch.
 */
class EnsureAgentLinked
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isRole('super_admin') && ! $user->agent_id) {
            return ApiResponse::error(__('messages.system.agent_not_linked'), null, 403);
        }

        return $next($request);
    }
}
