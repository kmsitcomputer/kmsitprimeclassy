<?php

namespace App\Http\Middleware;

use App\Support\InstallLock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Before the app is installed, the operator's real frontend domain isn't
 * known yet — FRONTEND_URLS/SANCTUM_STATEFUL_DOMAINS (and therefore
 * config('cors.allowed_origins')/config('sanctum.stateful')) are still
 * whatever placeholder shipped in the package, or entirely unset if no .env
 * exists yet at all (see EnvFileWriter — a fresh, zero-config deployment has
 * no .env until the very first request creates one). Without this, the
 * installer wizard could never even load, for two separate reasons:
 *
 *   - CORS: the SPA's first request (checking install status) would already
 *     be rejected by the static allow-list.
 *   - Sanctum: even once CORS lets a request through, EnsureFrontendRequests
 *     AreStateful only wraps a request with session/CSRF handling when its
 *     Origin/Referer host matches config('sanctum.stateful') — an early
 *     request from an as-yet-unrecognized frontend domain is treated as
 *     stateless (no session, no CSRF cookie pair actually established), so a
 *     LATER request from that same domain that suddenly IS recognized as
 *     stateful (e.g. right after the "Application" wizard step writes the
 *     real SANCTUM_STATEFUL_DOMAINS) fails CSRF validation against a session
 *     that was never properly started to begin with.
 *
 * Both would leave the "Application" step — which is supposed to let the
 * operator TYPE IN their real frontend URL — unreachable or broken, a
 * chicken-and-egg deadlock the same shape EnsurePreInstallSafeDrivers already
 * solves for the session/cache driver and APP_KEY.
 *
 * Reflects the actual requesting Origin back as the sole allowed CORS origin
 * AND the sole recognized Sanctum stateful domain, for THIS request only —
 * safe pre-install (no real users, no sensitive data, nothing yet worth a
 * cross-origin attacker targeting) — and has zero effect the instant install
 * locks, at which point both are governed purely by whatever FRONTEND_URLS/
 * SANCTUM_STATEFUL_DOMAINS the operator's own "Application" wizard step wrote.
 *
 * Registered via ->prepend() in bootstrap/app.php specifically so it runs
 * before Laravel's own global HandleCors middleware (which reads
 * config('cors.*') once per request) and before EnsureFrontendRequestsAre
 * Stateful (which reads config('sanctum.stateful') once per request) —
 * this must win both races.
 */
class ReflectOriginBeforeInstall
{
    public function handle(Request $request, Closure $next): Response
    {
        if (InstallLock::isInstalled()) {
            return $next($request);
        }

        $origin = $request->headers->get('Origin');

        if ($origin) {
            config(['cors.allowed_origins' => [$origin]]);
        }

        $host = $this->hostFromOriginOrReferer($request);

        if ($host) {
            config(['sanctum.stateful' => [$host]]);
        }

        return $next($request);
    }

    private function hostFromOriginOrReferer(Request $request): ?string
    {
        // Matches EnsureFrontendRequestsAreStateful::fromFrontend()'s own
        // precedence exactly (referer checked first) — this must derive the
        // same host Sanctum itself will check against.
        $domain = $request->headers->get('referer') ?: $request->headers->get('origin');

        if (! $domain) {
            return null;
        }

        return parse_url($domain, PHP_URL_HOST)
            .(($port = parse_url($domain, PHP_URL_PORT)) ? ":{$port}" : '');
    }
}
