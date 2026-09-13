<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Coarse, route-level gate: "is this role even allowed on this endpoint?"
 * This is a UX/routing convenience only — it never replaces the fine-grained
 * ownership checks done in Policies (see app/Policies). Never trust the
 * client's claimed role; this always re-reads it from the authenticated
 * user's row in the database via $request->user()->role.
 *
 * Usage: ->middleware('role:agen,korsal')
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponse::error(__('messages.system.please_login'), null, 401);
        }

        if (! $user->isRole(...$roles)) {
            return ApiResponse::error(__('messages.system.unauthorized_action'), null, 403);
        }

        return $next($request);
    }
}
