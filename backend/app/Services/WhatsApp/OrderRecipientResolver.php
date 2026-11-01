<?php

namespace App\Services\WhatsApp;

use App\Models\Order;
use App\Models\User;
use App\Support\WhatsAppNumber;

/**
 * AUD-07 / HA-02 / HA-03 / CL-01 / CL-02 / CL-10 — three-recipient resolution for one order event.
 *
 * Roles (verified source evidence only, never substituted):
 *  - `admin`    every admin of the order's Agent branch (role `admin`, same `agent_id` as the order) with a proven WA number.
 *  - `creator`  the account buyer (`konsumen_id`) AND, when different, the recorded submitter (`placed_by_user_id`).
 *               Both keep role `creator`: the buyer preserves the exact pre-existing buyer-only behaviour (including the
 *               legacy idempotency key), the submitter covers agent-assisted checkouts. CRM-origin orders resolve no
 *               creator (no account buyer; skipped upstream, never messaged).
 *  - `recipient` the goods recipient: the checkout `recipient_phone_snapshot` msisdn when it is a *different* number
 *               from every already-covered number; the checkout number is never treated as an account identity.
 *
 * Privacy (HA-06 / CL-02 / CL-03): financial detail belongs to admin + creator only when proven; the checkout
 * recipient gets delivery details only (restricted template). Deduplication: same msisdn in two roles sends the
 * HIGHER-privilege variant once (admin > creator > recipient) — never two copies of the same fact, never a merge
 * that leaks privilege.
 *
 * @return array<int, array{role:string, msisdn:string, user:?User, template_class:string, primary:bool}>
 */
final class OrderRecipientResolver
{
    public const ROLE_ADMIN = 'admin';

    public const ROLE_CREATOR = 'creator';

    public const ROLE_RECIPIENT = 'recipient';

    private const PRECEDENCE = [self::ROLE_ADMIN => 3, self::ROLE_CREATOR => 2, self::ROLE_RECIPIENT => 1];

    /**
     * @return array<int, array{role:string, msisdn:string, user:?User, template_class:string, primary:bool}>
     */
    public function resolve(Order $order): array
    {
        $candidates = [];

        foreach ($this->adminsFor($order) as $admin) {
            $msisdn = WhatsAppNumberProof::msisdnFor($admin);
            if ($msisdn !== null) {
                $candidates[] = ['role' => self::ROLE_ADMIN, 'msisdn' => $msisdn, 'user' => $admin, 'template_class' => 'full', 'primary' => false];
            }
        }

        // The account buyer is the PRIMARY recipient: it keeps the legacy single-recipient behaviour byte-for-byte
        // (same full template, same idempotency key — see OrderNotifier::sendTo).
        $buyer = $order->relationLoaded('konsumen') ? $order->konsumen : $order->konsumen()->first();
        $buyerMsisdn = $buyer ? WhatsAppNumberProof::msisdnFor($buyer) : null;
        if ($buyer !== null && $buyerMsisdn !== null) {
            $candidates[] = ['role' => self::ROLE_CREATOR, 'msisdn' => $buyerMsisdn, 'user' => $buyer, 'template_class' => 'full', 'primary' => true];
        }

        // Agent-assisted checkout: the recorded submitter is a second creator when it is someone else with a proof.
        if ($order->placed_by_user_id !== null && ($buyer === null || (int) $order->placed_by_user_id !== (int) $buyer->id)) {
            $actor = User::query()->find($order->placed_by_user_id);
            $actorMsisdn = $actor ? WhatsAppNumberProof::msisdnFor($actor) : null;
            if ($actor !== null && $actorMsisdn !== null) {
                $candidates[] = ['role' => self::ROLE_CREATOR, 'msisdn' => $actorMsisdn, 'user' => $actor, 'template_class' => 'full', 'primary' => false];
            }
        }

        $checkoutMsisdn = WhatsAppNumber::msisdn($order->recipient_phone_snapshot);
        if ($checkoutMsisdn !== null && ! $this->alreadyCovered($candidates, $checkoutMsisdn)) {
            $candidates[] = ['role' => self::ROLE_RECIPIENT, 'msisdn' => $checkoutMsisdn, 'user' => null, 'template_class' => 'restricted', 'primary' => false];
        }

        // Same number in several roles: keep only the highest-privilege row (never merge bodies across roles).
        $byNumber = [];
        foreach ($candidates as $c) {
            $prev = $byNumber[$c['msisdn']] ?? null;
            if ($prev === null || self::PRECEDENCE[$c['role']] > self::PRECEDENCE[$prev['role']]) {
                $byNumber[$c['msisdn']] = $c;
            }
        }

        return array_values($byNumber);
    }

    /** @return array<int, User> admins of the order's own Agent branch (same agent_id), never cross-Agent. */
    private function adminsFor(Order $order): array
    {
        if ($order->agent_id === null) {
            return [];
        }

        return User::query()->whereHas('role', fn ($q) => $q->where('slug', 'admin'))
            ->where('agent_id', $order->agent_id)
            ->get()->all();
    }

    /** The recorded creator: who actually submitted the order, else the account buyer. Never the seller/Agen/buyer guess. */
    private function creatorOf(Order $order): ?User
    {
        if ($order->relationLoaded('placedBy') && $order->placedBy !== null) {
            return $order->placedBy;
        }
        if (! $order->relationLoaded('placedBy') && $order->placed_by_user_id !== null) {
            $placed = User::query()->find($order->placed_by_user_id);
            if ($placed) {
                return $placed;
            }
        }

        return $order->relationLoaded('konsumen') ? $order->konsumen : $order->konsumen()->first();
    }

    /** @param  array<int, array{msisdn:string}>  $candidates */
    private function alreadyCovered(array $candidates, string $msisdn): bool
    {
        foreach ($candidates as $c) {
            if (hash_equals($c['msisdn'], $msisdn)) {
                return true;
            }
        }

        return false;
    }
}
