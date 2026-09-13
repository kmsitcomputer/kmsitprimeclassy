<?php

namespace App\Http\Middleware;

use App\Models\Language;
use App\Support\SafeSchema;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves which language every backend-generated string in this response
 * (validation errors, ApiException messages, ApiResponse "message" field)
 * should be in. The frontend sends its active language explicitly via
 * X-Locale (see frontend src/api/client.ts) rather than relying solely on
 * the browser's Accept-Language, so the dashboard and the public site can
 * never disagree with what the user picked in the language switcher.
 *
 * The registered `languages` table (not a hard-coded list here) is the
 * single source of truth for which codes are valid — adding a 5th language
 * later is a seeded row, not a code change to this middleware.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $requested = $request->header('X-Locale') ?? $request->getPreferredLanguage($this->activeCodes());

        $locale = in_array($requested, $this->activeCodes(), true)
            ? $requested
            : config('app.locale');

        App::setLocale($locale);

        return $next($request);
    }

    /**
     * Before the installer has run, `languages` doesn't exist yet (or the
     * database isn't even configured yet) — fall back to the app's
     * configured default rather than throwing, so /install/* stays
     * reachable on a completely empty/unconfigured database.
     */
    private function activeCodes(): array
    {
        if (! SafeSchema::hasTable('languages')) {
            return [config('app.locale')];
        }

        return Language::query()->where('is_active', true)->pluck('code')->all();
    }
}
