<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Http\Request;

/**
 * Self-service email verification for the signed-in holder only (own address, never another account's):
 *   1. POST /profile/email/verification  -> code to the CURRENT address (rate-limited, HMAC, TTL, single-use).
 *   2. POST /profile/email/verification/{challenge}/confirm {code} -> marks it verified, consumed once.
 * The code path never returns the code and never logs the address; responses carry a masked marker only.
 */
class EmailVerificationController extends Controller
{
    public function __construct(private readonly EmailVerificationService $verification) {}

    /** Current verified/pending state for the signed-in holder (masked address only). */
    public function show(Request $request)
    {
        return $this->ok($this->verification->state($request->user()));
    }

    public function request(Request $request)
    {
        $challenge = $this->verification->request($request->user());

        if ($challenge === null) {
            return $this->ok($this->verification->state($request->user()), __('messages.auth.email_already_verified'));
        }

        return $this->created([
            'challenge_id' => $challenge->id,
            'expires_at' => $challenge->expires_at,
            'max_attempts' => $challenge->max_attempts,
        ], __('messages.auth.email_verification_sent'));
    }

    public function confirm(Request $request, int $challenge)
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);

        return $this->ok(
            $this->verification->confirm($request->user(), $challenge, $data['code']),
            __('messages.consent.otp_verified'),
        );
    }
}
