<?php

namespace App\Services\WhatsApp;

use App\Models\Order;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Support\WhatsAppNumber;

/**
 * R-01 / CL-05 — the ONLY exception to "a transactional WhatsApp reaches a number the account has PROVEN".
 *
 * Owner-approved: limited, non-sensitive notifications may go to an UNVERIFIED number. This is a closed allow-list, never a
 * general `if (!$recipient) allow`. A row qualifies only when EVERY condition below holds at SEND time:
 *
 *   A. order status notice to the checkout recipient
 *      role=`recipient`, class=`restricted`, event in ORDER_EVENTS, no account bound, the order exists in the SAME Agent as the
 *      row, and the destination is still the order's CURRENT recipient number (a changed number blocks the stale row).
 *   B. registration notice to the new account
 *      role=`registrant`, class=`restricted`, event=`account_registered`, bound to a real user of the row's Agent whose CURRENT
 *      phone is the destination.
 *
 * Both require: no secret body (nothing OTP-like can ride this lane) and a purpose of `transaction`. The restricted bodies are
 * rendered server-side from fixed text (OrderNotifier / RegistrationNotifier), never from a configurable template. OTP, recovery, consent proof, financial events and arbitrary templates are never eligible — they
 * fall through to the proof check in WhatsAppDelivery (and fail there without a proof).
 */
final class RestrictedDeliveryPolicy
{
    public const CLASS_RESTRICTED = 'restricted';

    public const ROLE_REGISTRANT = 'registrant';

    public const EVENT_ACCOUNT_REGISTERED = 'account_registered';

    /** Status-change notices only. Financial events (payment_*, refund_*) are deliberately absent. */
    public const ORDER_EVENTS = [
        WhatsAppEvents::ORDER_CREATED, WhatsAppEvents::ORDER_PROCESSED, WhatsAppEvents::ORDER_SHIPPED,
        WhatsAppEvents::ORDER_DELIVERED, WhatsAppEvents::ORDER_CANCELLED,
    ];

    public static function isRestricted(WhatsAppMessage $m): bool
    {
        return $m->template_class === self::CLASS_RESTRICTED;
    }

    /** Is this event one a restricted (unproven-number) row may ever be created for? */
    public static function eventEligible(string $role, string $event): bool
    {
        return match ($role) {
            OrderRecipientResolver::ROLE_RECIPIENT => in_array($event, self::ORDER_EVENTS, true),
            self::ROLE_REGISTRANT => $event === self::EVENT_ACCOUNT_REGISTERED,
            default => false,
        };
    }

    /**
     * Send-time authorization of a restricted row.
     *
     * @return string|null null = deliverable; otherwise the `skip_reason` to record
     */
    public function check(WhatsAppMessage $m): ?string
    {
        if ($m->purpose !== WhatsAppMessage::PURPOSE_TRANSACTION || $m->secret_body !== null || ! self::isRestricted($m)) {
            return 'restricted_not_allowed';
        }
        if (! self::eventEligible((string) $m->recipient_role, (string) $m->event)) {
            return 'restricted_not_allowed';
        }

        return $m->recipient_role === OrderRecipientResolver::ROLE_RECIPIENT
            ? $this->checkCheckoutRecipient($m)
            : $this->checkRegistrant($m);
    }

    private function checkCheckoutRecipient(WhatsAppMessage $m): ?string
    {
        // A checkout recipient is NEVER an account: binding one here would be an identity claim nobody verified.
        if ($m->recipient_user_id !== null || $m->order_id === null) {
            return 'restricted_not_allowed';
        }

        $order = Order::withoutGlobalScopes()->find($m->order_id);
        if (! $order || $order->agent_id === null || (int) $order->agent_id !== (int) $m->agent_id) {
            return 'recipient_order_mismatch';
        }

        $current = WhatsAppNumber::msisdn($order->recipient_phone_snapshot);
        if ($current === null || ! hash_equals($current, (string) $m->recipient_phone)) {
            return 'recipient_changed';
        }

        return null;
    }

    private function checkRegistrant(WhatsAppMessage $m): ?string
    {
        if ($m->recipient_user_id === null || $m->order_id !== null) {
            return 'restricted_not_allowed';
        }

        $user = User::query()->find($m->recipient_user_id);
        if (! $user) {
            return 'recipient_order_mismatch';
        }
        $agentId = $user->agent_id ?? $user->id;
        if ((int) $agentId !== (int) $m->agent_id) {
            return 'recipient_order_mismatch';
        }

        $current = WhatsAppNumber::msisdn($user->phone);
        if ($current === null || ! hash_equals($current, (string) $m->recipient_phone)) {
            return 'recipient_changed';
        }

        return null;
    }
}
