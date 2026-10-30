<?php

namespace App\Services\Consent;

use App\Exceptions\ApiException;
use App\Models\ConsentEvent;
use App\Models\CrmCustomer;
use App\Models\IdentityOtpChallenge;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * REM-005 / CI-02..CI-04: append-only digital consent evidence. The konsumen stays the consent
 * giver; Sales/SKS/Agen only capture, and only inside their own Agent/referral chain (CI-03).
 * Capturing requires a verified single-use OTP challenge plus photo/screenshot evidence and a
 * text note. Withdrawal/dispute/reinstatement are further rows — never updates (CI-04), and they
 * only gate FUTURE consent-dependent actions; completed financial events are untouched.
 *
 * Assisted return initiation does not exist yet. When it is built it MUST call
 * {@see self::hasValidConsent()} and refuse without it (WS-9 gate 7).
 */
class ConsentService
{
    public const POLICY_VERSION = 'consent-v1';

    public const PURPOSES = ['assisted_return'];

    private const EVIDENCE_DISK = 'local';

    private const EVIDENCE_DIRECTORY = 'consent/evidence';

    private const VERIFIED_WINDOW_MINUTES = 15;

    public function __construct(private readonly ConsentAccess $access) {}

    /**
     * @param  array<int, array{order_item_id:int, quantity:int}>  $scope
     */
    public function capture(
        User $actor,
        User $subject,
        Order $order,
        string $purpose,
        array $scope,
        UploadedFile $evidence,
        string $note,
        IdentityOtpChallenge $challenge,
        ?string $consentKey,
    ): ConsentEvent {
        if (! $this->access->canCapture($actor, $subject)) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        if ($order->konsumen_id !== $subject->id || $order->agent_id !== $subject->agent_id) {
            throw new ApiException(__('messages.consent.order_mismatch'), 422);
        }

        if ($consentKey !== null) {
            $existing = ConsentEvent::withoutGlobalScopes()->where('consent_key', $consentKey)->first();
            if ($existing) {
                if ($existing->actor_id !== $actor->id || $existing->subject_user_id !== $subject->id) {
                    throw new ApiException(__('messages.consent.key_conflict'), 409);
                }

                return $existing;
            }
        }

        $normalizedScope = $this->validateScope($order, $scope);
        $hash = hash_file('sha256', $evidence->getRealPath());

        return DB::transaction(function () use ($actor, $subject, $order, $purpose, $normalizedScope, $evidence, $note, $challenge, $consentKey, $hash) {
            $locked = IdentityOtpChallenge::query()->whereKey($challenge->id)->lockForUpdate()->firstOrFail();

            $valid = $locked->subject_user_id === $subject->id
                && $locked->created_by === $actor->id
                && $locked->purpose === $purpose
                && $locked->verified_at !== null
                && $locked->consumed_at === null
                && $locked->invalidated_at === null
                && \App\Services\WhatsApp\WhatsAppNumberProof::channelStillValid($locked, $subject)
                && $locked->verified_at->gte(now()->subMinutes(self::VERIFIED_WINDOW_MINUTES));

            if (! $valid) {
                throw new ApiException(__('messages.consent.challenge_not_usable'), 422);
            }

            $locked->forceFill(['consumed_at' => now()])->save();

            $path = $evidence->store(self::EVIDENCE_DIRECTORY, self::EVIDENCE_DISK);

            return ConsentEvent::create([
                'agent_id' => $subject->agent_id,
                'subject_user_id' => $subject->id,
                'order_id' => $order->id,
                'event_type' => ConsentEvent::CAPTURED,
                'purpose' => $purpose,
                'scope' => $normalizedScope,
                'evidence_path' => $path,
                'evidence_sha256' => $hash,
                'note' => $note,
                'otp_challenge_id' => $locked->id,
                'actor_id' => $actor->id,
                'consent_key' => $consentKey,
                'policy_version' => self::POLICY_VERSION,
                'occurred_at' => now(),
            ]);
        });
    }

    /**
     * CI-02/CI-03 for a CRM customer. Owner decision 2026-10-31: photo + note alone is NEVER sufficient identity
     * verification. The consent must be backed by a single-use OTP delivered on a VERIFIED customer channel — the
     * verified email of the konsumen account this CRM customer is linked to. With no such channel the action is
     * BLOCKED (409); there is deliberately no manual or automatic bypass until the Owner ratifies a procedure,
     * its evidence and its authorization. Photo/screenshot + note remain REQUIRED additional evidence.
     *
     * @param  array<int, array{order_item_id:int, quantity:int}>  $scope
     */
    public function captureForCrm(
        User $actor,
        CrmCustomer $customer,
        Order $order,
        string $purpose,
        array $scope,
        UploadedFile $evidence,
        string $note,
        IdentityOtpChallenge $challenge,
        ?string $consentKey,
    ): ConsentEvent {
        if (! $this->access->canCaptureCrm($actor, $customer)) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        if ($order->crm_customer_id !== $customer->id || $order->agent_id !== $customer->agent_id) {
            throw new ApiException(__('messages.consent.order_mismatch'), 422);
        }

        if ($consentKey !== null) {
            $existing = ConsentEvent::withoutGlobalScopes()->where('consent_key', $consentKey)->first();
            if ($existing) {
                if ($existing->actor_id !== $actor->id || $existing->crm_customer_id !== $customer->id) {
                    throw new ApiException(__('messages.consent.key_conflict'), 409);
                }

                return $existing;
            }
        }

        $normalizedScope = $this->validateScope($order, $scope);
        $hash = hash_file('sha256', $evidence->getRealPath());

        return DB::transaction(function () use ($actor, $customer, $order, $purpose, $normalizedScope, $evidence, $note, $challenge, $consentKey, $hash) {
            // Re-read the customer under lock: the channel exists only while the link does.
            $locked = CrmCustomer::withoutGlobalScopes()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $konsumen = $locked->linked_konsumen_id ? User::query()->find($locked->linked_konsumen_id) : null;

            $otp = IdentityOtpChallenge::query()->whereKey($challenge->id)->lockForUpdate()->firstOrFail();
            $viaWhatsApp = str_starts_with((string) $otp->sent_to_email, 'whatsapp:');

            if (! $konsumen || (! $viaWhatsApp && (! $konsumen->email || ! $konsumen->email_verified_at))) {
                throw new ApiException(__('messages.crm.consent_channel_unavailable'), 409);
            }

            $valid = $otp->purpose === $purpose
                && $otp->crm_customer_id === $locked->id
                && $otp->subject_user_id === $konsumen->id
                && $otp->created_by === $actor->id
                && $otp->verified_at !== null
                && $otp->consumed_at === null
                && $otp->invalidated_at === null
                && \App\Services\WhatsApp\WhatsAppNumberProof::channelStillValid($otp, $konsumen)
                && $otp->verified_at->gte(now()->subMinutes(self::VERIFIED_WINDOW_MINUTES));

            if (! $valid) {
                throw new ApiException(__('messages.consent.challenge_not_usable'), 422);
            }

            $otp->forceFill(['consumed_at' => now()])->save();
            $path = $evidence->store(self::EVIDENCE_DIRECTORY, self::EVIDENCE_DISK);

            return ConsentEvent::create([
                'agent_id' => $locked->agent_id,
                // The verified identity that answered the OTP, plus the CRM customer the consent is about.
                'subject_user_id' => $konsumen->id,
                'crm_customer_id' => $locked->id,
                'order_id' => $order->id,
                'event_type' => ConsentEvent::CAPTURED,
                'purpose' => $purpose,
                'scope' => $normalizedScope,
                'evidence_path' => $path,
                'evidence_sha256' => $hash,
                'note' => $note,
                'otp_challenge_id' => $otp->id,
                'actor_id' => $actor->id,
                'consent_key' => $consentKey,
                'policy_version' => self::POLICY_VERSION,
                'occurred_at' => now(),
            ]);
        });
    }

    /** WS-9 gate 7 for CRM-origin orders: assisted return initiation needs a currently valid consent. */
    public function hasValidConsentForCrm(CrmCustomer $customer, Order $order, string $purpose): bool
    {
        return $this->validRootsForCrm($customer, $order, $purpose)->isNotEmpty();
    }

    /** @return \Illuminate\Support\Collection<int, ConsentEvent> roots whose latest event is captured/reinstated */
    public function validRootsForCrm(CrmCustomer $customer, Order $order, string $purpose)
    {
        return ConsentEvent::withoutGlobalScopes()
            ->where('crm_customer_id', $customer->id)
            ->where('order_id', $order->id)
            ->where('purpose', $purpose)
            ->where('event_type', ConsentEvent::CAPTURED)
            ->whereNull('root_event_id')
            ->get()
            ->filter(fn (ConsentEvent $root) => in_array($this->currentState($root), [ConsentEvent::CAPTURED, ConsentEvent::REINSTATED], true))
            ->values();
    }

    public function withdraw(User $actor, ConsentEvent $root, string $reason): ConsentEvent
    {
        return $this->append($actor, $root, ConsentEvent::WITHDRAWN, $reason, [ConsentEvent::CAPTURED, ConsentEvent::REINSTATED]);
    }

    public function dispute(User $actor, ConsentEvent $root, string $reason): ConsentEvent
    {
        return $this->append($actor, $root, ConsentEvent::DISPUTED, $reason, [ConsentEvent::CAPTURED, ConsentEvent::REINSTATED, ConsentEvent::WITHDRAWN]);
    }

    /** Resuming after a dispute requires authorized review (CI-04). */
    public function reinstate(User $actor, ConsentEvent $root, string $reason): ConsentEvent
    {
        $allowed = $root->crm_customer_id !== null
            ? $this->access->canReviewCrm($actor, CrmCustomer::withoutGlobalScopes()->findOrFail($root->crm_customer_id))
            : $this->access->canReview($actor, User::query()->findOrFail($root->subject_user_id));

        if (! $allowed) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        return $this->append($actor, $root, ConsentEvent::REINSTATED, $reason, [ConsentEvent::DISPUTED], reviewed: true);
    }

    /** Effective, prospective state: the latest event in the chain decides. */
    public function currentState(ConsentEvent $root): string
    {
        return $this->latestEvent($root)->event_type;
    }

    public function hasValidConsent(User $subject, Order $order, string $purpose): bool
    {
        $roots = ConsentEvent::query()
            ->where('subject_user_id', $subject->id)
            ->where('order_id', $order->id)
            ->where('purpose', $purpose)
            ->where('event_type', ConsentEvent::CAPTURED)
            ->whereNull('root_event_id')
            ->get();

        foreach ($roots as $root) {
            if (in_array($this->currentState($root), [ConsentEvent::CAPTURED, ConsentEvent::REINSTATED], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<int, string>  $allowedFrom */
    private function append(User $actor, ConsentEvent $root, string $type, string $reason, array $allowedFrom, bool $reviewed = false): ConsentEvent
    {
        if ($root->root_event_id !== null || $root->event_type !== ConsentEvent::CAPTURED) {
            throw new ApiException(__('messages.consent.not_a_root'), 422);
        }

        if ($root->crm_customer_id !== null) {
            // CRM-only subject: the owning seller or an Agent-scoped reviewer acts on the customer's behalf.
            $customer = CrmCustomer::withoutGlobalScopes()->findOrFail($root->crm_customer_id);
            $allowed = $this->access->canCaptureCrm($actor, $customer) || $this->access->canReviewCrm($actor, $customer);
        } else {
            $subject = User::query()->findOrFail($root->subject_user_id);
            $allowed = $this->access->canWithdrawOrDispute($actor, $subject);
        }

        if (! $reviewed && ! $allowed) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        return DB::transaction(function () use ($actor, $root, $type, $reason, $allowedFrom) {
            // Serialize the chain through its root row so two writers cannot both append.
            DB::table('consent_events')->where('id', $root->id)->lockForUpdate()->first();

            if (! in_array($this->latestEvent($root)->event_type, $allowedFrom, true)) {
                throw new ApiException(__('messages.consent.invalid_transition'), 422);
            }

            return ConsentEvent::create([
                'agent_id' => $root->agent_id,
                'subject_user_id' => $root->subject_user_id,
                // Only present once the CRM-subject migration exists AND the chain is CRM (keeps old schemas working).
                ...($root->crm_customer_id !== null ? ['crm_customer_id' => $root->crm_customer_id] : []),
                'order_id' => $root->order_id,
                'return_id' => $root->return_id,
                'root_event_id' => $root->id,
                'event_type' => $type,
                'purpose' => $root->purpose,
                'scope' => $root->scope,
                'note' => $reason,
                'actor_id' => $actor->id,
                'policy_version' => self::POLICY_VERSION,
                'occurred_at' => now(),
            ]);
        });
    }

    private function latestEvent(ConsentEvent $root): ConsentEvent
    {
        return ConsentEvent::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('id', $root->id)->orWhere('root_event_id', $root->id))
            ->orderByDesc('id')
            ->firstOrFail();
    }

    /**
     * @param  array<int, array{order_item_id:int, quantity:int}>  $scope
     * @return array<int, array{order_item_id:int, quantity:int}>
     */
    private function validateScope(Order $order, array $scope): array
    {
        if ($scope === []) {
            throw new ApiException(__('messages.consent.scope_required'), 422);
        }

        $normalized = [];
        foreach ($scope as $line) {
            $item = OrderItem::query()->where('order_id', $order->id)->find($line['order_item_id'] ?? null);
            $qty = (int) ($line['quantity'] ?? 0);

            // Same bound ReturnService uses: the active (billed) quantity not yet returned.
            $maxScope = $item ? (int) $item->fulfilled_quantity - (int) $item->returned_quantity : 0;

            if (! $item || $qty < 1 || $qty > $maxScope || isset($normalized[$item->id])) {
                throw new ApiException(__('messages.consent.scope_invalid'), 422);
            }

            $normalized[$item->id] = ['order_item_id' => $item->id, 'quantity' => $qty];
        }

        return array_values($normalized);
    }
}
