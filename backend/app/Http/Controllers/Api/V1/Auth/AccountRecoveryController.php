<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\AccountRecoveryService;
use Illuminate\Http\Request;

/**
 * Unauthenticated password recovery (enumeration-resistant):
 *   1. POST /auth/forgot-password {identifier, channel?} — ALWAYS the same generic answer; the code (when the
 *      account exists and the channel is reachable) travels by email or proven-number WhatsApp only.
 *   2. POST /auth/reset-password {identifier, code, password, password_confirmation} — one code = one reset;
 *      old tokens are revoked.
 * Neither endpoint reveals whether the identifier exists, has a verified channel, or was rate-limited.
 */
class AccountRecoveryController extends Controller
{
    public function __construct(private readonly AccountRecoveryService $recovery) {}

    public function forgot(Request $request)
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'channel' => ['nullable', 'string', 'in:email,whatsapp'],
        ]);

        $this->recovery->request($data['identifier'], $data['channel'] ?? null);

        return $this->ok(null, __('messages.auth.account_recovery_sent'));
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $this->recovery->reset($data['identifier'], $data['code'], $data['password']);

        return $this->ok(null, __('messages.auth.password_reset_success'));
    }
}
