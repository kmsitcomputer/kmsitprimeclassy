<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every API/auth response — especially /sanctum/csrf-cookie's Set-Cookie
 * header — must never be cached by an edge/page cache sitting in front of
 * PHP (LiteSpeed's own LSCache is enabled by default on a lot of shared
 * hosting, often with no automatic exclusion for API routes). A cached
 * csrf-cookie response means a stale XSRF-TOKEN/session cookie pair gets
 * served to every later visitor/request indefinitely — the request itself
 * looks completely normal (200 OK, a Set-Cookie header is present), but the
 * token it hands out no longer matches the CURRENT server-side session,
 * producing a "CSRF token mismatch" that persists no matter how many times
 * the request is retried, since retrying just receives the SAME cached
 * response again. This can't be fixed from application code alone (a proxy
 * cache in front of PHP can ignore anything Laravel sends), which is why
 * .htaccess ALSO explicitly disables LSCache for these paths — this header
 * is the defense-in-depth half of that fix, for any cache that does honor it.
 */
class PreventApiResponseCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');

        // LiteSpeed's LSCache module has its own override header, checked
        // ahead of (and sometimes instead of) the standard Cache-Control —
        // set explicitly since a standard header alone has proven
        // insufficient on at least one LiteSpeed shared-hosting deployment
        // of this app.
        $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');

        return $response;
    }
}
