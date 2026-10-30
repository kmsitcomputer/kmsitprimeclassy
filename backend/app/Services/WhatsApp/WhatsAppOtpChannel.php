<?php

namespace App\Services\WhatsApp;

use App\Exceptions\ApiException;
use App\Models\IdentityOtpChallenge;
use App\Models\User;
use App\Models\WhatsAppMessage;

/**
 * WhatsApp as a DELIVERY channel for the existing OTP challenge (OtpChallengeService). It adds no verification of its
 * own: a challenge is satisfied only by entering the code, a "sent" message proves nothing about identity, and if the
 * code cannot be delivered the challenge is invalidated and the dependent action stays blocked.
 *
 * Gates, in order: gateway configured + OTP switched on + table present -> a PROVEN number exists for the subject.
 * The OTP text is held encrypted only until the message reaches a final state and is never written to `body`/logs.
 */
class WhatsAppOtpChannel
{
    public function __construct(
        private readonly WhatsAppSettings $settings,
        private readonly WhatsAppDelivery $delivery,
        private readonly VerifiedWhatsAppNumberResolver $proof,
        private readonly AgentDeviceService $devices,
    ) {}

    /** The Agent whose device carries a user's codes: the user's own Agent (an Agen is its own). */
    private function agentOf(User $user): int
    {
        return (int) ($user->agent_id ?? $user->id);
    }

    /**
     * @return array{policy: array{ttl:int,max:int,limit:int,window:int,cooldown:int}, msisdn: string}
     *
     * @throws ApiException 409 when the channel cannot be used
     */
    public function prepare(User $subject): array
    {
        $this->settings->reload();
        $otp = $this->settings->otp();

        if (! WhatsAppMessage::tableReady() || ! $this->settings->isConfigured() || ! $otp['enabled']) {
            throw new ApiException(__('messages.whatsapp.otp_unavailable'), 409, ['code' => 'whatsapp_otp_unavailable']);
        }

        if (! $this->devices->canDeliverFor($this->agentOf($subject))) {
            throw new ApiException(__('messages.whatsapp.otp_unavailable'), 409, ['code' => 'whatsapp_otp_unavailable']);
        }

        $msisdn = $this->proof->msisdnFor($subject);
        if ($msisdn === null) {
            throw new ApiException(__('messages.whatsapp.number_not_verified'), 409, ['code' => 'whatsapp_number_not_verified']);
        }

        return [
            'policy' => [
                'ttl' => $otp['ttl_minutes'], 'max' => $otp['max_attempts'], 'limit' => $otp['limit'],
                'window' => $otp['limit_window_minutes'], 'cooldown' => $otp['cooldown_seconds'],
            ],
            'msisdn' => $msisdn,
        ];
    }

    /**
     * Policy for proving a number. Unlike prepare() there is no "already proven number" requirement — establishing that
     * proof is the whole point — but the gateway must be usable and WhatsApp OTP switched on.
     *
     * @return array{ttl:int,max:int,limit:int,window:int,cooldown:int}
     *
     * @throws ApiException 409 when the channel cannot be used
     */
    public function numberVerificationPolicy(User $user): array
    {
        $this->settings->reload();
        $otp = $this->settings->otp();

        if (! WhatsAppMessage::tableReady() || ! $this->settings->isConfigured() || ! $otp['enabled'] || ! $this->devices->canDeliverFor($this->agentOf($user))) {
            throw new ApiException(__('messages.whatsapp.otp_unavailable'), 409, ['code' => 'whatsapp_otp_unavailable']);
        }

        return [
            'ttl' => $otp['ttl_minutes'], 'max' => $otp['max_attempts'], 'limit' => $otp['limit'],
            'window' => $otp['limit_window_minutes'], 'cooldown' => $otp['cooldown_seconds'],
        ];
    }

    /** True when a number can be verified right now (used by the UI to show/hide the action). */
    public function available(User $user): bool
    {
        $this->settings->reload();

        return WhatsAppMessage::tableReady() && $this->settings->isConfigured() && $this->settings->otp()['enabled']
            && $this->devices->canDeliverFor($this->agentOf($user));
    }

    /** Sends the code. A message that is not provably SENT invalidates the challenge and blocks the action. */
    public function deliver(IdentityOtpChallenge $challenge, User $subject, string $msisdn, #[\SensitiveParameter] string $code, int $ttlMinutes): void
    {
        $text = __('messages.whatsapp.otp_text', ['code' => $code, 'minutes' => $ttlMinutes]);

        $message = $this->delivery->enqueue([
            'idempotency_key' => 'otp:challenge:'.$challenge->id,
            'purpose' => WhatsAppMessage::PURPOSE_OTP, 'event' => 'otp',
            'agent_id' => $challenge->agent_id, 'subject_type' => 'otp_challenge', 'subject_id' => $challenge->id,
            'recipient_user_id' => $subject->id, 'recipient_phone' => $msisdn,
            // The stored/visible body NEVER contains the code.
            'body' => __('messages.whatsapp.otp_text', ['code' => '••••••', 'minutes' => $ttlMinutes]),
            'created_by' => $challenge->created_by,
        ], secretBody: $text, dispatch: false);

        $sent = $message ? $this->delivery->deliver($message->id) : null;

        if (! $sent || $sent->status !== WhatsAppMessage::SENT) {
            $challenge->forceFill(['invalidated_at' => now()])->save();

            throw new ApiException(__('messages.whatsapp.otp_not_delivered'), 502, ['code' => 'whatsapp_otp_not_delivered']);
        }
    }
}
