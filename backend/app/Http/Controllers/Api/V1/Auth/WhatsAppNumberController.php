<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppNumberVerification;
use App\Support\WhatsAppNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Self-service: the authenticated account holder proves control of THEIR OWN WhatsApp number. The caller can only ever act on
 * themselves (no id parameter selects another account), so these endpoints cannot be used to probe whether an account or a
 * number exists. This is channel proof only — never consent, never CRM linking.
 */
class WhatsAppNumberController extends Controller
{
    public function __construct(private readonly WhatsAppNumberVerification $verification) {}

    public function show(Request $request)
    {
        return $this->ok($this->verification->state($request->user()));
    }

    public function request(Request $request)
    {
        $data = $request->validate(['phone' => ['nullable', 'string', 'max:20']]);

        // Per-SOURCE limit on top of the per-account and per-number limits enforced with the challenge itself.
        $key = 'wa-number-verify:ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 12)) {
            throw new ApiException(__('messages.whatsapp.verification_rate_limited'), 429);
        }
        RateLimiter::hit($key, 3600);

        $challenge = $this->verification->request($request->user(), $data['phone'] ?? null);

        if ($challenge === null) {
            return $this->ok($this->verification->state($request->user()), __('messages.whatsapp.number_already_verified'));
        }

        return $this->created([
            'challenge_id' => $challenge->id,
            'expires_at' => $challenge->expires_at,
            'max_attempts' => $challenge->max_attempts,
            'target' => WhatsAppNumber::mask(substr((string) $challenge->sent_to_email, 3)),
        ], __('messages.whatsapp.verification_sent'));
    }

    public function confirm(Request $request, int $challenge)
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);

        return $this->ok($this->verification->confirm($request->user(), $challenge, $data['code']), __('messages.whatsapp.number_verified'));
    }
}
