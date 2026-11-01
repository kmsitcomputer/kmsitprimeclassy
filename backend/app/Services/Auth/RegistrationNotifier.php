<?php

namespace App\Services\Auth;

use App\Mail\AccountRegisteredMail;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\RestrictedDeliveryPolicy;
use App\Services\WhatsApp\WhatsAppDelivery;
use App\Services\WhatsApp\WhatsAppSettings;
use App\Support\WhatsAppNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * R-02 / HA-08 / CL-04 / CL-05 — account-created notification for self-registration, first Google registration and
 * Admin-created accounts.
 *
 *  - Recipient is the NEW account's own contacts (never the creating Admin). Email when one exists; WhatsApp when a phone exists,
 *    as a RESTRICTED notice (RestrictedDeliveryPolicy) — the number is NOT verified and is not marked verified by this.
 *  - Content is fixed, informational text: no password, OTP, reset link, role hierarchy or finance. Not verification/activation.
 *  - Runs after commit; each channel is isolated (try/catch) and a failure can never undo or fail the account creation.
 *  - Idempotent per user: the WA ledger key is unique, the Email is guarded by a one-shot cache key.
 *  - The WA sender is the new account's own Agent's paired device (WhatsAppDelivery::deliver) — never substituted.
 */
class RegistrationNotifier
{
    public function __construct(
        private readonly WhatsAppSettings $settings,
        private readonly WhatsAppDelivery $delivery,
    ) {}

    public function notify(User $user): void
    {
        $userId = $user->id;

        DB::afterCommit(function () use ($userId) {
            $fresh = User::query()->find($userId);
            if (! $fresh) {
                return;
            }

            $this->email($fresh);
            $this->whatsapp($fresh);
        });
    }

    private function email(User $user): void
    {
        try {
            $email = $user->email ? trim($user->email) : '';
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            if (! Cache::add('reg-notify:email:'.$user->id, 1, now()->addDays(30))) {
                return; // duplicate event for the same account
            }

            Mail::to($email)->send(new AccountRegisteredMail((string) $user->name));
        } catch (\Throwable $e) {
            Log::warning('registration.notify.email_failed', ['user_id' => $user->id, 'category' => class_basename($e)]);
        }
    }

    private function whatsapp(User $user): void
    {
        try {
            $msisdn = WhatsAppNumber::msisdn($user->phone);
            if ($msisdn === null || ! WhatsAppMessage::ledgerReady() || ! $this->settings->reload()->enabled()) {
                return;
            }

            $this->delivery->enqueue([
                'idempotency_key' => 'txn:'.RestrictedDeliveryPolicy::EVENT_ACCOUNT_REGISTERED.':user:'.$user->id,
                'purpose' => WhatsAppMessage::PURPOSE_TRANSACTION,
                'event' => RestrictedDeliveryPolicy::EVENT_ACCOUNT_REGISTERED,
                'template_class' => RestrictedDeliveryPolicy::CLASS_RESTRICTED,
                'recipient_role' => RestrictedDeliveryPolicy::ROLE_REGISTRANT,
                'agent_id' => $user->agent_id ?? $user->id,
                'subject_type' => 'user', 'subject_id' => $user->id,
                'recipient_user_id' => $user->id,
                'recipient_phone' => $msisdn,
                'body' => $this->whatsappBody(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('registration.notify.whatsapp_failed', ['user_id' => $user->id, 'category' => class_basename($e)]);
        }
    }

    private function whatsappBody(): string
    {
        return "Selamat datang di PrimeClassy.\n\nAkun Anda telah dibuat. Untuk melihat informasi lebih lengkap dan menggunakan fitur yang memerlukan bukti kepemilikan nomor, silakan verifikasi WhatsApp Anda melalui aplikasi resmi PrimeClassy.";
    }
}
