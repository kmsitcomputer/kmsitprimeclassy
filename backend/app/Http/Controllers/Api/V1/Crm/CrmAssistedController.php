<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\AssistedOrderRequest;
use App\Http\Resources\ConsentEventResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\ReturnRequestResource;
use App\Models\ConsentEvent;
use App\Models\CrmCustomer;
use App\Models\Order;
use App\Models\RefundRecipientEvidence;
use App\Models\User;
use App\Services\Consent\ConsentService;
use App\Services\Consent\OtpChallengeService;
use App\Services\Crm\AssistedOrderService;
use App\Services\Crm\AssistedReturnService;
use App\Services\Crm\CrmLinkService;
use App\Services\Crm\RefundRecipientService;
use App\Models\IdentityOtpChallenge;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;

/**
 * Phase 2 / G17 endpoints: Assisted Order, customer linking, CRM-origin consent, assisted return initiation
 * and refund-recipient evidence. Authority lives in the services (CrmAccess / ConsentAccess); every
 * Agent boundary is additionally enforced by the global scopes on the bound models.
 */
class CrmAssistedController extends Controller
{
    public function __construct(
        private readonly AssistedOrderService $assistedOrders,
        private readonly CrmLinkService $links,
        private readonly OtpChallengeService $otp,
        private readonly ConsentService $consent,
        private readonly AssistedReturnService $assistedReturns,
        private readonly RefundRecipientService $recipients,
    ) {}

    /* ---------------- Assisted Order ---------------- */

    public function placeOrder(AssistedOrderRequest $request, CrmCustomer $customer)
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! $idempotencyKey) {
            throw new ApiException(__('messages.payment.idempotency_key_required'), 422, ['idempotency_key' => __('messages.system.field_required')]);
        }

        $wasExisting = Order::query()->where('crm_customer_id', $customer->id)->where('idempotency_key', $idempotencyKey)->exists();

        $order = $this->assistedOrders->place(
            $request->user(),
            $customer,
            $request->array('items'),
            $request->destinationInput(),
            $idempotencyKey,
            $request->string('payment_method_code')->toString(),
            $request->input('delivery_date'),
            $request->input('shipping_method'),
            $request->filled('dp_amount') ? (float) $request->input('dp_amount') : null,
            $request->selectedCourierOption(),
            $request->filled('voucher_code') ? $request->string('voucher_code')->toString() : null,
        );

        Log::info('order.created.assisted', [
            'order_id' => $order->id, 'crm_customer_id' => $customer->id, 'placed_by' => $request->user()->id, 'idempotent_replay' => $wasExisting,
        ]);

        return $this->created(new OrderResource($order), __('messages.order.created'));
    }

    /* ---------------- Linking ---------------- */

    /**
     * Accounts the owning seller may link: ONLY konsumen this seller referred (the same reach as ConsentAccess::canCapture),
     * same Agent, active, and not already linked to a CRM customer. Contact data is masked — enough to pick the right person,
     * never enough to enumerate or harvest accounts. (The orders-based "Konsumen Saya" report cannot serve this: a referred
     * konsumen with no order yet would never appear.)
     */
    public function linkCandidates(Request $request)
    {
        $actor = $request->user();
        $linked = CrmCustomer::withoutGlobalScopes()->whereNotNull('linked_konsumen_id')->select('linked_konsumen_id');

        $rows = User::query()
            ->whereHas('role', fn ($q) => $q->where('slug', 'konsumen'))
            ->where('agent_id', $actor->agent_id)
            // Same reach as ConsentAccess::canCapture: Sales/SKS = sales_id; Korsal = its own direct referrals only.
            ->where(fn ($q) => $actor->isRole('korsal')
                ? $q->where('sales_id', $actor->id)->orWhere(fn ($qq) => $qq->whereNull('sales_id')->where('korsal_id', $actor->id))
                : $q->where('sales_id', $actor->id))
            ->where('status', 'active')
            ->whereNotIn('id', $linked)
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'phone', 'email', 'email_verified_at'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'phone_tail' => $u->phone ? '…'.substr($u->phone, -3) : null,
                'email_masked' => $u->email ? substr($u->email, 0, 1).'***@'.substr(strrchr($u->email, '@') ?: '@', 1) : null,
                'email_verified' => $u->email !== null && $u->email_verified_at !== null,
            ]);

        return $this->ok($rows);
    }

    public function linkOtp(Request $request, CrmCustomer $customer)
    {
        $data = $request->validate(['konsumen_id' => ['required', 'integer'], 'channel' => ['nullable', 'string', Rule::in(['email', 'whatsapp'])]]);
        $konsumen = User::query()->find($data['konsumen_id']);
        // Same answer for "no such account" and "not yours to reach": never disclose account existence.
        if (! $konsumen) {
            throw new ApiException(__('messages.crm.link_not_allowed'), 403);
        }

        $challenge = $this->otp->issue($request->user(), $konsumen, OtpChallengeService::PURPOSE_CRM_LINK, $customer, $data['channel'] ?? 'email');

        return $this->created([
            'challenge_id' => $challenge->id,
            'expires_at' => $challenge->expires_at,
            'max_attempts' => $challenge->max_attempts,
        ], __('messages.consent.otp_sent'));
    }

    public function link(Request $request, CrmCustomer $customer)
    {
        $data = $request->validate([
            'konsumen_id' => ['required', 'integer'],
            'otp_challenge_id' => ['required', 'integer', 'exists:identity_otp_challenges,id'],
        ]);
        $konsumen = User::query()->find($data['konsumen_id']);
        if (! $konsumen) {
            throw new ApiException(__('messages.crm.link_not_allowed'), 403);
        }

        $linked = $this->links->link($request->user(), $customer, $konsumen, IdentityOtpChallenge::query()->findOrFail($data['otp_challenge_id']));

        return $this->ok($linked, __('messages.crm.linked'));
    }

    public function unlink(Request $request, CrmCustomer $customer)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->ok($this->links->unlink($request->user(), $customer, $data['reason']), __('messages.crm.unlinked'));
    }

    /* ---------------- Consent for a CRM-only customer ---------------- */

    /**
     * Issues the consent OTP to the VERIFIED email of the konsumen account this CRM customer is linked to.
     * No linked account / unverified email => 409 / 422; there is no other channel and no bypass.
     */
    public function consentOtp(Request $request, CrmCustomer $customer)
    {
        $data = $request->validate([
            'purpose' => ['required', 'string', Rule::in(ConsentService::PURPOSES)],
            'channel' => ['nullable', 'string', Rule::in(['email', 'whatsapp'])],
        ]);

        $konsumen = $customer->linked_konsumen_id ? User::query()->find($customer->linked_konsumen_id) : null;
        if (! $konsumen) {
            throw new ApiException(__('messages.crm.consent_channel_unavailable'), 409);
        }

        $challenge = $this->otp->issue($request->user(), $konsumen, $data['purpose'], $customer, $data['channel'] ?? 'email');

        return $this->created([
            'challenge_id' => $challenge->id,
            'expires_at' => $challenge->expires_at,
            'max_attempts' => $challenge->max_attempts,
        ], __('messages.consent.otp_sent'));
    }

    public function captureConsent(Request $request, CrmCustomer $customer)
    {
        $data = $request->validate([
            'otp_challenge_id' => ['required', 'integer', 'exists:identity_otp_challenges,id'],
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'purpose' => ['required', 'string', Rule::in(ConsentService::PURPOSES)],
            'evidence' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'note' => ['required', 'string', 'max:1000'],
            'scope' => ['required', 'array', 'min:1'],
            'scope.*.order_item_id' => ['required', 'integer'],
            'scope.*.quantity' => ['required', 'integer', 'min:1'],
            'consent_key' => ['nullable', 'string', 'max:100'],
        ]);

        $event = $this->consent->captureForCrm(
            $request->user(), $customer, Order::query()->findOrFail($data['order_id']), $data['purpose'],
            $data['scope'], $request->file('evidence'), $data['note'],
            IdentityOtpChallenge::query()->findOrFail($data['otp_challenge_id']), $data['consent_key'] ?? null,
        );

        return $this->created(new ConsentEventResource($event), __('messages.consent.captured'));
    }

    /* ---------------- Assisted return initiation ---------------- */

    public function initiateReturn(Request $request, Order $order)
    {
        $data = $request->validate([
            'consent_event_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:1000'],
            'evidence' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer', 'exists:order_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.restock' => ['sometimes', 'boolean'],
        ]);

        $root = ConsentEvent::query()->findOrFail($data['consent_event_id']);

        $return = $this->assistedReturns->initiate(
            $request->user(), $order, $root, $data['items'], $data['reason'], $request->file('evidence'),
        );

        return $this->created(new ReturnRequestResource($return->load(['order', 'items.orderItem', 'requestedBy'])), __('messages.return.requested'));
    }

    /* ---------------- Refund recipient evidence ---------------- */

    public function storeRecipient(Request $request, Order $order)
    {
        $data = $request->validate([
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_phone' => ['nullable', 'string', 'max:30'],
            'recipient_relation' => ['nullable', 'string', 'max:100'],
            'method' => ['required', 'string', Rule::in(RefundRecipientEvidence::METHODS)],
            'destination' => ['nullable', 'array'],
            'destination.bank_or_provider' => ['nullable', 'string', 'max:100'],
            'destination.account_number' => ['nullable', 'string', 'max:50'],
            'destination.account_holder' => ['nullable', 'string', 'max:150'],
            'authorization_note' => ['required', 'string', 'max:2000'],
            'consent_event_id' => ['nullable', 'integer'],
            'return_id' => ['nullable', 'integer'],
            'evidence' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $row = $this->recipients->record($request->user(), $order, $data, $request->file('evidence'));

        return $this->created($this->recipientPayload($row, $request->user()), __('messages.crm.recipient_recorded'));
    }

    public function indexRecipient(Request $request, Order $order)
    {
        $this->authorize('view', $order);

        $rows = RefundRecipientEvidence::query()->where('order_id', $order->id)->orderBy('id')->get()
            ->map(fn (RefundRecipientEvidence $r) => $this->recipientPayload($r, $request->user()));

        return $this->ok($rows);
    }

    /** @return array<string, mixed> */
    private function recipientPayload(RefundRecipientEvidence $row, User $viewer): array
    {
        $details = $row->destination_details ?? [];
        $number = (string) ($details['account_number'] ?? '');

        return [
            'id' => $row->id,
            'order_id' => $row->order_id,
            'return_id' => $row->return_id,
            'recipient_name' => $row->recipient_name,
            'recipient_phone' => $row->recipient_phone,
            'recipient_relation' => $row->recipient_relation,
            'method' => $row->method,
            'destination' => $this->recipients->canSeeDestination($viewer)
                ? $details
                : ($number !== '' ? ['account_number_tail' => substr($number, -4)] : null),
            'consent_event_id' => $row->consent_event_id,
            'has_evidence_file' => $row->evidence_path !== null,
            'evidence_sha256' => $row->evidence_sha256,
            'authorization_note' => $row->authorization_note,
            'supersedes_id' => $row->supersedes_id,
            'captured_by' => $row->captured_by,
            'occurred_at' => $row->occurred_at,
        ];
    }
}
