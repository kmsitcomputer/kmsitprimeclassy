<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use App\Support\InstallLock;
use Closure;
use Illuminate\Http\Request;

/**
 * Guards every /install/* write endpoint (migrate, create-admin) — once
 * InstallLock::isInstalled() is true, these can never run again, on
 * purpose: they can run arbitrary migrations/seeders and create a
 * super_admin account, so they must be permanently inert after first use.
 * The read-only /install/status endpoint is NOT behind this middleware —
 * the frontend needs to be able to ask "am I installed?" forever.
 */
class EnsureNotInstalled
{
    public function handle(Request $request, Closure $next)
    {
        if (InstallLock::isInstalled()) {
            return ApiResponse::error(__('messages.install.already_installed'), null, 403);
        }

        return $next($request);
    }
}
