<?php

namespace App\Http\Middleware;

use App\Support\EnvFileWriter;
use App\Support\InstallLock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs before Sanctum's EnsureFrontendRequestsAreStateful, which appends
 * session-starting middleware to the pipeline for any request from a
 * configured stateful domain — exactly what the real frontend always sends
 * — and before the `throttle:` rate limiter, which reads/writes the cache
 * store on every request it guards (including the installer wizard's own
 * routes). With SESSION_DRIVER=database/CACHE_STORE=database and a
 * completely empty (or entirely unconfigured) pre-install database,
 * either would otherwise crash before the request ever reaches /install/*.
 *
 * Falls back to the 'file' driver for both (storage/framework/sessions and
 * storage/framework/cache/data, both already present and writable) rather
 * than 'array', since 'array' never persists across requests at all and
 * would break both CSRF and rate limiting (both need to survive between
 * requests, and the installer wizard is many separate requests sharing one
 * session).
 *
 * Keyed on InstallLock::isInstalled() rather than "does the sessions/cache
 * table exist yet" — the wizard's own "Install Database" step creates
 * those tables mid-flow (via migrate), and switching the session driver
 * out from under an already-established session cookie between two
 * requests in the SAME wizard would silently orphan it (browser holds a
 * 'file'-backed session ID, server starts looking in the now-existing but
 * empty `sessions` table instead) — surfacing as a confusing CSRF
 * mismatch on the wizard's later steps. Locking is the one moment this
 * safely flips back to the real configured driver for good.
 *
 * Also guarantees APP_KEY exists before anything downstream needs it. The
 * 'web' middleware group (used by Sanctum's own /sanctum/csrf-cookie
 * route — the very first request the frontend ever makes, before every
 * state-changing call including the installer's own steps) unconditionally
 * runs EncryptCookies, which throws MissingAppKeyException the instant
 * .env has no key yet. Since the installer's own "Database" step is what
 * used to generate that key, a brand new deployment could never even
 * reach it — the csrf-cookie fetch that has to happen first would already
 * be crashing. Generating a key here, on literally the first request,
 * breaks that chicken-and-egg deadlock.
 *
 * The generation itself is guarded by an exclusive file lock. A real
 * browser fires several requests to a freshly-loaded page in parallel
 * (the router's install-status check, requirements, etc. can genuinely
 * overlap) — without a lock, two of those could each see no key yet, each
 * generate a DIFFERENT random one, and each write it to .env; whichever
 * write lands last silently invalidates every cookie already encrypted
 * with the other request's key, including one already sent back to the
 * browser — surfacing much later, confusingly, as "CSRF token mismatch"
 * on an unrelated step. The lock plus a re-check of the actual file
 * content (not just this request's already-booted config) after
 * acquiring it closes that race.
 */
class EnsurePreInstallSafeDrivers
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! InstallLock::isInstalled()) {
            if (config('session.driver') === 'database') {
                config(['session.driver' => 'file']);
            }

            if (config('cache.default') === 'database') {
                config(['cache.default' => 'file']);
            }

            if (! config('app.key')) {
                $this->ensureAppKeyExists();
            }
        }

        return $next($request);
    }

    private function ensureAppKeyExists(): void
    {
        $lockPath = storage_path('framework/appkey.lock');
        $lock = fopen($lockPath, 'c');

        if (! $lock) {
            // Can't lock — fall back to the old best-effort behavior rather
            // than fail the request outright.
            $key = 'base64:'.base64_encode(random_bytes(32));
            EnvFileWriter::set(['APP_KEY' => $key]);
            config(['app.key' => $key]);

            return;
        }

        flock($lock, LOCK_EX);

        // Re-check against the actual file, not config() — another request
        // may have already generated and written a key while we waited for
        // the lock, and config() still reflects THIS request's own boot.
        $envContent = @file_get_contents(EnvFileWriter::path()) ?: '';
        $alreadySet = (bool) preg_match('/^APP_KEY=(?!\s*$).+/m', $envContent);

        if (! $alreadySet) {
            $key = 'base64:'.base64_encode(random_bytes(32));
            EnvFileWriter::set(['APP_KEY' => $key]);
            config(['app.key' => $key]);
        }

        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
