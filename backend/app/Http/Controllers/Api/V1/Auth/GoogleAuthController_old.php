<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Auth\GoogleAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * IMP-001 Feature A. Registered under the `web` middleware group (session + cookies) because both
 * legs are top-level browser navigations: the callback comes from accounts.google.com and must see
 * the server-side OAuth state stored by the redirect leg. Every outcome ends in a browser redirect to
 * the SPA — never JSON, never a token in a URL.
 */
class GoogleAuthController extends Controller
{
    public function __construct(private readonly GoogleAuthService $google) {}

    public function redirect(Request $request): RedirectResponse
    {
        $mode = match ($request->query('mode')) {
            GoogleAuthService::MODE_REGISTER => GoogleAuthService::MODE_REGISTER,
            GoogleAuthService::MODE_LINK => GoogleAuthService::MODE_LINK,
            default => GoogleAuthService::MODE_LOGIN,
        };
        $referral = is_string($request->query('referral_code')) ? $request->query('referral_code') : null;

        try {
            $url = $this->google->beginAuthorization($request, $mode, $referral);
        } catch (ApiException $e) {
            return $this->toSpa($this->pathFor($mode), [
                'google_error' => match ($e->status()) {
                    503 => 'not_configured',
                    401 => 'login_required',
                    default => 'referral_required',
                },
            ]);
        }

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $result = $this->google->handleCallback($request);

        if ($result['status'] === 'error') {
            $path = in_array($result['code'], ['registration_required', 'invalid_referral'], true)
                ? '/register' : $this->pathFor($result['mode']);

            return $this->toSpa($path, ['google_error' => $result['code']]);
        }

        if ($result['status'] === 'linked') {
            // Already authenticated as this user — no login/session change.
            return $this->toSpa('/profile', ['google' => 'linked']);
        }

        Auth::login($result['user'], true);
        $request->session()->regenerate();

        return $this->toSpa('/', ['google' => $result['created'] ? 'registered' : 'ok']);
    }

    private function pathFor(string $mode): string
    {
        return match ($mode) {
            GoogleAuthService::MODE_REGISTER => '/register',
            GoogleAuthService::MODE_LINK => '/profile',
            default => '/login',
        };
    }

    private function toSpa(string $path, array $query): RedirectResponse
    {
        $base = rtrim((string) config('services.google.frontend_url'), '/');

        return redirect()->away($base.$path.'?'.http_build_query($query));
    }
}
