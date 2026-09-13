<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsureAgentLinked;
use App\Http\Middleware\EnsureNotInstalled;
use App\Http\Middleware\EnsurePreInstallSafeDrivers;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\PreventApiResponseCaching;
use App\Http\Middleware\ReflectOriginBeforeInstall;
use App\Http\Middleware\SetLocale;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Must win the race against Laravel's own global HandleCors middleware,
        // which reads config('cors.*') once per request — see the docblock on
        // ReflectOriginBeforeInstall for why this can't just be prepended onto
        // the 'api'/'web' groups the way EnsurePreInstallSafeDrivers is below.
        $middleware->prepend(ReflectOriginBeforeInstall::class);

        // Every response, API or web (covers /sanctum/csrf-cookie) — see
        // PreventApiResponseCaching's docblock for why an edge/page cache
        // (LiteSpeed's LSCache, common on shared hosting) serving a stale
        // cached copy of the csrf-cookie response is otherwise
        // indistinguishable from a genuine, unfixable-by-retrying CSRF bug.
        $middleware->append(PreventApiResponseCaching::class);

        // SetLocale runs before everything else in the group so validation
        // messages and every ApiResponse below already render in the
        // requester's active language (see App\Http\Middleware\SetLocale).
        $middleware->api(prepend: [
            EnsurePreInstallSafeDrivers::class,
            SetLocale::class,
            EnsureFrontendRequestsAreStateful::class,
        ]);

        // Sanctum's own /sanctum/csrf-cookie route runs under 'web', not
        // 'api' — it needs the same pre-install session-driver safety net,
        // since the frontend calls it before every stateful write.
        $middleware->web(prepend: [
            EnsurePreInstallSafeDrivers::class,
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
            'agent.linked' => EnsureAgentLinked::class,
            'not.installed' => EnsureNotInstalled::class,
        ]);

        // The installer wizard is a bootstrap-time-only flow on a deployment
        // that, by definition, has no real users/data yet — whoever can reach
        // it is already about to set the DB credentials and create the one
        // Super Admin account, so there's nothing a forged cross-site request
        // could gain here that legitimate use doesn't already hand them. CSRF
        // depends on a same-key, same-session cookie round-trip that's
        // needlessly fragile across a fresh cross-subdomain deployment (mid-
        // wizard APP_KEY/session-driver transitions, SameSite/Secure cookie
        // quirks on hosts that don't have HTTPS fully sorted yet on both
        // domains) — exactly the kind of thing EnsurePreInstallSafeDrivers and
        // ReflectOriginBeforeInstall already relax for this same route group.
        // Permanently safe to exempt outright (not just pre-install): every
        // write endpoint under here is ALSO sealed by 'not.installed' the
        // moment InstallLock::markInstalled() runs, regardless of CSRF.
        $middleware->validateCsrfTokens(except: [
            'api/v1/install/*',
        ]);

        // This is a pure JSON API with no "login" web route — Laravel's
        // default guest-redirect otherwise calls route('login') and crashes
        // with RouteNotFoundException on any unauthenticated request that
        // doesn't explicitly send Accept: application/json (e.g. a bare
        // curl call, a health-checker, a browser hitting the URL directly),
        // masking the intended clean 401 JSON response with a 500.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (ApiException $e, Request $request) {
            return ApiResponse::error($e->getMessage(), $e->errors() ?: null, $e->status());
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            return ApiResponse::error(
                __('messages.system.validation_failed'),
                $e->errors(),
                422
            );
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return ApiResponse::error(__('messages.system.please_login'), null, 401);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            return ApiResponse::error(__('messages.system.unauthorized_action'), null, 403);
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            return ApiResponse::error(__('messages.system.not_found'), null, 404);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(__('messages.system.endpoint_not_found'), null, 404);
            }
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage() ?: __('messages.system.generic_error'), null, $e->getStatusCode());
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if (config('app.debug')) {
                return ApiResponse::error($e->getMessage(), [
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ], 500);
            }

            return ApiResponse::error(__('messages.system.server_error'), null, 500);
        });
    })->create();

// Single-domain shared-hosting layout: if this backend folder has a sibling
// `public_html/` containing `laravel.php` (see CARA_DEPLOY.md in the
// single-domain deployment package), that folder — shared with the built
// frontend's own static files — is the real web-facing directory instead of
// the usual backend/public/, and every public_path() resolution
// (storage:link's target, asset URLs) must target it instead. Purely
// structural detection based on the filesystem, not a .env value — Dotenv
// hasn't loaded yet at this point in the boot sequence, so env() isn't
// reliable here yet. Completely inert for the standard separate-domain
// deployment (this file's normal dev/production layout), which has no such
// sibling.
$singleRootPublicPath = dirname(__DIR__, 2).'/public_html';
if (is_file($singleRootPublicPath.'/laravel.php')) {
    $app->usePublicPath($singleRootPublicPath);
}

return $app;
