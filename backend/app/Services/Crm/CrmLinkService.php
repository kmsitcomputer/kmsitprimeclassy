<?php

namespace App\Services\Crm;

use App\Exceptions\ApiException;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerLinkEvent;
use App\Models\IdentityOtpChallenge;
use App\Models\User;
use App\Services\Consent\ConsentAccess;
use App\Services\Consent\OtpChallengeService;
use App\Services\Logging\ActivityLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * OD-03 / OD-04 / CI-05: link a CRM customer to a konsumen ACCOUNT only after the OTP emailed to that
 * account's verified address was entered (proof of account control), inside one Agent, never by matching
 * contact details. Link/unlink change FUTURE authorization only: no order, payment, refund, commission
 * or snapshot row is read-modified here, and the order's buyer origin stays whatever it was at placement.
 * The DB enforces "one account per CRM record and vice versa" (unique `linked_konsumen_id`).
 */
class CrmLinkService
{
    private const VERIFIED_WINDOW_MINUTES = 15;

    public function __construct(
        private readonly CrmAccess $crmAccess,
        private readonly ConsentAccess $consentAccess,
    ) {}

    public function link(User $actor, CrmCustomer $customer, User $konsumen, IdentityOtpChallenge $challenge): CrmCustomer
    {
        if (! $this->crmAccess->canLink($actor, $customer)) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        // CI-05: cross-Agent linking is rejected; reaching the account also needs the referral scope.
        if (! $konsumen->isRole('konsumen')
            || $konsumen->agent_id !== $customer->agent_id
            || ! $this->consentAccess->canCapture($actor, $konsumen)) {
            throw new ApiException(__('messages.crm.link_not_allowed'), 403);
        }

        if ($customer->archived_at !== null) {
            throw new ApiException(__('messages.crm.customer_archived'), 422);
        }

        try {
            return DB::transaction(function () use ($actor, $customer, $konsumen, $challenge) {
                $locked = CrmCustomer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

                if ($locked->linked_konsumen_id !== null) {
                    throw new ApiException(__('messages.crm.already_linked'), 409);
                }

                if (CrmCustomer::withoutGlobalScopes()->where('linked_konsumen_id', $konsumen->id)->exists()) {
                    throw new ApiException(__('messages.crm.account_already_linked'), 409);
                }

                $otp = IdentityOtpChallenge::query()->whereKey($challenge->id)->lockForUpdate()->firstOrFail();
                $usable = $otp->purpose === OtpChallengeService::PURPOSE_CRM_LINK
                    && $otp->crm_customer_id === $locked->id
                    && $otp->subject_user_id === $konsumen->id
                    && $otp->created_by === $actor->id
                    && $otp->verified_at !== null
                    && $otp->consumed_at === null
                    && $otp->invalidated_at === null
                    && \App\Services\WhatsApp\WhatsAppNumberProof::channelStillValid($otp, $konsumen)
                    && $otp->verified_at->gte(now()->subMinutes(self::VERIFIED_WINDOW_MINUTES));

                if (! $usable) {
                    throw new ApiException(__('messages.consent.challenge_not_usable'), 422);
                }

                $otp->forceFill(['consumed_at' => now()])->save();
                $locked->forceFill(['linked_konsumen_id' => $konsumen->id])->save();

                $event = CrmCustomerLinkEvent::create([
                    'agent_id' => $locked->agent_id,
                    'crm_customer_id' => $locked->id,
                    'konsumen_user_id' => $konsumen->id,
                    'event_type' => CrmCustomerLinkEvent::LINKED,
                    'actor_id' => $actor->id,
                    'otp_challenge_id' => $otp->id,
                    'occurred_at' => now(),
                ]);
                ActivityLogger::log($actor->id, $locked, 'crm.customer.linked', null, ['konsumen_user_id' => $konsumen->id, 'event_id' => $event->id]);

                return $locked->fresh();
            });
        } catch (QueryException $e) {
            // Two racing links: the unique indexes are the final arbiter.
            if (str_contains($e->getMessage(), 'linked_konsumen_id') || str_contains($e->getMessage(), 'otp_challenge_id')) {
                throw new ApiException(__('messages.crm.account_already_linked'), 409);
            }

            throw $e;
        }
    }

    public function unlink(User $actor, CrmCustomer $customer, string $reason): CrmCustomer
    {
        if (! $this->crmAccess->canUnlink($actor, $customer)) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        return DB::transaction(function () use ($actor, $customer, $reason) {
            $locked = CrmCustomer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            if ($locked->linked_konsumen_id === null) {
                throw new ApiException(__('messages.crm.not_linked'), 422);
            }

            $konsumenId = $locked->linked_konsumen_id;
            $locked->forceFill(['linked_konsumen_id' => null])->save();

            $event = CrmCustomerLinkEvent::create([
                'agent_id' => $locked->agent_id,
                'crm_customer_id' => $locked->id,
                'konsumen_user_id' => $konsumenId,
                'event_type' => CrmCustomerLinkEvent::UNLINKED,
                'actor_id' => $actor->id,
                'reason' => $reason,
                'occurred_at' => now(),
            ]);
            ActivityLogger::log($actor->id, $locked, 'crm.customer.unlinked', $reason, ['konsumen_user_id' => $konsumenId, 'event_id' => $event->id]);

            return $locked->fresh();
        });
    }
}
