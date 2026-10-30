<?php

namespace App\Services\Crm;

use App\Exceptions\ApiException;
use App\Models\ConsentEvent;
use App\Models\Order;
use App\Models\RefundRecipientEvidence;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * G17-D4: records WHO receives a CRM-origin refund, by which method, with what authorization evidence.
 * Append-only (a correction supersedes the previous row). It does not create, approve or settle any refund
 * and never touches the ledger — it only unlocks RefundRecipientGuard for a later, separately authorized step.
 */
class RefundRecipientService
{
    private const DISK = 'local';

    private const DIRECTORY = 'refund/recipient-evidence';

    /**
     * @param  array{recipient_name:string, recipient_phone?:?string, recipient_relation?:?string, method:string,
     *     destination?:?array<string,string>, authorization_note:string, consent_event_id?:?int, return_id?:?int}  $data
     */
    public function record(User $actor, Order $order, array $data, ?UploadedFile $evidence): RefundRecipientEvidence
    {
        if (! $order->isCrmOrigin()) {
            throw new ApiException(__('messages.crm.not_crm_order'), 422);
        }

        if (! $this->canRecord($actor, $order)) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        if (in_array($data['method'], ['bank_transfer', 'ewallet'], true)
            && (empty($data['destination']['account_number']) || empty($data['destination']['account_holder']))) {
            throw new ApiException(__('messages.crm.recipient_destination_required'), 422, [
                'destination' => [__('messages.crm.recipient_destination_required')],
            ]);
        }

        $consentId = $data['consent_event_id'] ?? null;
        if ($consentId !== null) {
            $consent = ConsentEvent::withoutGlobalScopes()->find($consentId);
            if (! $consent || $consent->order_id !== $order->id || $consent->crm_customer_id !== $order->crm_customer_id) {
                throw new ApiException(__('messages.crm.consent_mismatch'), 422);
            }
        }

        // Authorization evidence is mandatory: a consent record and/or an uploaded document/photo.
        if ($consentId === null && $evidence === null) {
            throw new ApiException(__('messages.crm.recipient_authorization_required'), 422, [
                'authorization' => [__('messages.crm.recipient_authorization_required')],
            ]);
        }

        if (! empty($data['return_id']) && ! ReturnRequest::query()->where('order_id', $order->id)->whereKey($data['return_id'])->exists()) {
            throw new ApiException(__('messages.crm.consent_mismatch'), 422);
        }

        return DB::transaction(function () use ($actor, $order, $data, $evidence, $consentId) {
            // Serialize corrections per order so the supersede chain stays linear.
            Order::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->first();
            $previous = RefundRecipientEvidence::withoutGlobalScopes()->where('order_id', $order->id)->orderByDesc('id')->first();

            $path = $evidence?->store(self::DIRECTORY, self::DISK);

            $row = RefundRecipientEvidence::create([
                'agent_id' => $order->agent_id,
                'order_id' => $order->id,
                'crm_customer_id' => $order->crm_customer_id,
                'return_id' => $data['return_id'] ?? null,
                'recipient_name' => $data['recipient_name'],
                'recipient_phone' => $data['recipient_phone'] ?? null,
                'recipient_relation' => $data['recipient_relation'] ?? null,
                'method' => $data['method'],
                'destination_details' => $data['destination'] ?? null,
                'consent_event_id' => $consentId,
                'authorization_note' => $data['authorization_note'],
                'evidence_path' => $path,
                'evidence_sha256' => $evidence ? hash_file('sha256', $evidence->getRealPath()) : null,
                'supersedes_id' => $previous?->id,
                'captured_by' => $actor->id,
                'occurred_at' => now(),
            ]);

            ActivityLogger::log($actor->id, $order, 'refund.recipient_evidence.recorded', null, [
                'evidence_id' => $row->id, 'supersedes_id' => $previous?->id, 'method' => $row->method,
            ]);

            return $row;
        });
    }

    /** Owning seller of the order's commission snapshot, or branch Agen/Admin/Keuangan, or super_admin. */
    public function canRecord(User $actor, Order $order): bool
    {
        if ($actor->isRole('super_admin')) {
            return true;
        }

        if ($actor->agent_id === null || $actor->agent_id !== $order->agent_id) {
            return false;
        }

        if ($actor->isRole('sales', 'sales-kurir-sub')) {
            return $order->sales_id === $actor->id;
        }

        // Korsal acting as Sales: only orders whose Sales-fee recipient is the Korsal itself (its own purchase, or a konsumen
        // referred directly to it with no Sales in between) — never a downline Sales' order.
        if ($actor->isRole('korsal')) {
            $buyer = User::query()->with('role:id,slug')->find($order->konsumen_id);
            if ($buyer !== null && $buyer->isRole('agen', 'korsal', 'sales', 'sales-kurir-sub')) {
                return $buyer->id === $actor->id;
            }

            return $order->sales_id === $actor->id || ($order->sales_id === null && $order->korsal_id === $actor->id);
        }

        return $actor->isRole('agen', 'admin', 'keuangan');
    }

    /** Full bank/e-wallet details only for those who actually pay out; everyone else sees a masked tail. */
    public function canSeeDestination(User $actor): bool
    {
        return $actor->isRole('super_admin', 'keuangan');
    }
}
