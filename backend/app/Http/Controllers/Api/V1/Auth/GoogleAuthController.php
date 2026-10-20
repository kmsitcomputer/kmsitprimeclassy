<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Auth\GoogleAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Google OAuth browser redirects. Routes must use the `web` middleware group
 * so the session can retain OAuth state between redirect and callback.
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

        $referral = is_string($request->query('referral_code'))
            ? $request->query('referral_code')
            : null;

        try {
            $url = $this->google->beginAuthorization($request, $mode, $referral);
        } catch (ApiException $e) {
            $errorCode = match (true) {
                $e->status() === 503 => 'not_configured',
                $e->status() === 401 => 'login_required',
                $e->status() === 422 && $mode === GoogleAuthService::MODE_REGISTER
                    && trim((string) $referral) !== '' => 'invalid_referral',
                $e->status() === 422 && $mode === GoogleAuthService::MODE_REGISTER
                    => 'default_agent_unavailable',
                default => 'google_auth_failed',
            };

            return $this->toSpa($this->pathFor($mode), ['google_error' => $errorCode]);
        }

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $result = $this->google->handleCallback($request);

        if ($result['status'] === 'error') {
            $registerErrors = [
                'registration_required',
                'invalid_referral',
                'default_agent_unavailable',
                'registration_unavailable',
            ];

            $path = in_array($result['code'], $registerErrors, true)
                ? '/register'
                : $this->pathFor($result['mode']);

            return $this->toSpa($path, ['google_error' => $result['code']]);
        }

        if ($result['status'] === 'linked') {
            // Linking an identity does not replace the current authenticated session.
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
