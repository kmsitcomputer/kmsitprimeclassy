<?php

namespace App\Http\Controllers\Api\V1\Consent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Consent\CaptureConsentRequest;
use App\Http\Requests\Consent\ConsentReasonRequest;
use App\Http\Requests\Consent\IssueOtpRequest;
use App\Http\Requests\Consent\VerifyOtpRequest;
use App\Http\Resources\ConsentEventResource;
use App\Models\ConsentEvent;
use App\Models\IdentityOtpChallenge;
use App\Models\Order;
use App\Models\User;
use App\Services\Consent\ConsentAccess;
use App\Services\Consent\ConsentService;
use App\Services\Consent\OtpChallengeService;
use Illuminate\Http\Request;

/** REM-005 / WS-9. Authority lives in ConsentAccess; this layer only maps HTTP to services. */
class ConsentController extends Controller
{
    public function __construct(
        private readonly OtpChallengeService $otp,
        private readonly ConsentService $consent,
    ) {}

    public function issueOtp(IssueOtpRequest $request)
    {
        $subject = User::query()->findOrFail($request->integer('subject_user_id'));
        $challenge = $this->otp->issue($request->user(), $subject, $request->string('purpose')->toString(), null, $request->input('channel') ?: 'email');

        // The code is emailed only — never returned.
        return $this->created([
            'challenge_id' => $challenge->id,
            'expires_at' => $challenge->expires_at,
            'max_attempts' => $challenge->max_attempts,
        ], __('messages.consent.otp_sent'));
    }

    public function verifyOtp(VerifyOtpRequest $request, IdentityOtpChallenge $challenge)
    {
        $verified = $this->otp->verify($request->user(), $challenge, $request->string('code')->toString());

        return $this->ok(['challenge_id' => $verified->id, 'verified_at' => $verified->verified_at], __('messages.consent.otp_verified'));
    }

    public function store(CaptureConsentRequest $request)
    {
        $subject = User::query()->findOrFail($request->integer('subject_user_id'));
        $order = Order::query()->findOrFail($request->integer('order_id'));
        $challenge = IdentityOtpChallenge::query()->findOrFail($request->integer('otp_challenge_id'));

        $event = $this->consent->capture(
            $request->user(), $subject, $order,
            $request->string('purpose')->toString(),
            $request->array('scope'),
            $request->file('evidence'),
            $request->string('note')->toString(),
            $challenge,
            $request->input('consent_key'),
        );

        return $this->created(new ConsentEventResource($event), __('messages.consent.captured'));
    }

    public function withdraw(ConsentReasonRequest $request, ConsentEvent $consentEvent)
    {
        $event = $this->consent->withdraw($request->user(), $consentEvent, $request->string('reason')->toString());

        return $this->created(new ConsentEventResource($event), __('messages.consent.withdrawn'));
    }

    public function dispute(ConsentReasonRequest $request, ConsentEvent $consentEvent)
    {
        $event = $this->consent->dispute($request->user(), $consentEvent, $request->string('reason')->toString());

        return $this->created(new ConsentEventResource($event), __('messages.consent.disputed'));
    }

    public function reinstate(ConsentReasonRequest $request, ConsentEvent $consentEvent)
    {
        $event = $this->consent->reinstate($request->user(), $consentEvent, $request->string('reason')->toString());

        return $this->created(new ConsentEventResource($event), __('messages.consent.reinstated'));
    }

    /**
     * UI context for the consent panel. The subject id is revealed only to an actor who may capture
     * consent for it (OrderResource deliberately never broadcasts ownership ids — A1-15).
     */
    public function context(Request $request, Order $order, ConsentAccess $access)
    {
        $this->authorize('view', $order);

        $actor = $request->user();

        // G17: a CRM-origin order's consent subject is the CRM customer, captured by its owning Sales/SKS.
        if ($order->isCrmOrigin()) {
            $customer = \App\Models\CrmCustomer::withoutGlobalScopes()->find($order->crm_customer_id);
            $canCapture = $customer !== null && $access->canCaptureCrm($actor, $customer);
            $payload = ['mode' => 'crm', 'can_capture' => $canCapture, 'purposes' => ConsentService::PURPOSES];

            if ($canCapture) {
                // The ONLY accepted channel: OTP to the verified email of the linked konsumen account.
                $linked = $customer->linked_konsumen_id ? User::query()->find($customer->linked_konsumen_id) : null;
                $payload['channel_available'] = $linked !== null && $linked->email !== null && $linked->email_verified_at !== null;
                $payload['crm_customer_id'] = $customer->id;
                $payload['items'] = $order->items()->get()->map(fn ($i) => [
                    'id' => $i->id,
                    'name' => $i->product_name_snapshot,
                    'variation' => $i->variation_label_snapshot,
                    'max_quantity' => max(0, (int) $i->fulfilled_quantity - (int) $i->returned_quantity),
                ])->values();
            }

            return $this->ok($payload);
        }

        $subject = User::query()->find($order->konsumen_id);
        $canCapture = $subject !== null && $access->canCapture($actor, $subject);

        $payload = ['can_capture' => $canCapture, 'purposes' => ConsentService::PURPOSES];

        if ($canCapture) {
            $payload['subject_user_id'] = $subject->id;
            $payload['subject_email_verified'] = $subject->email !== null && $subject->email_verified_at !== null;
            $payload['items'] = $order->items()->get()->map(fn ($i) => [
                'id' => $i->id,
                'name' => $i->product_name_snapshot,
                'variation' => $i->variation_label_snapshot,
                'max_quantity' => max(0, (int) $i->fulfilled_quantity - (int) $i->returned_quantity),
            ])->values();
        }

        return $this->ok($payload);
    }

    /** Consent history for one order; visibility follows the Order policy plus the Agent scope. */
    public function indexForOrder(Request $request, Order $order)
    {
        $this->authorize('view', $order);

        $events = ConsentEvent::query()->where('order_id', $order->id)->orderBy('id')->get();

        return $this->ok(ConsentEventResource::collection($events));
    }
}
