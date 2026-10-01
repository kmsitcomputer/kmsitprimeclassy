<?php

namespace App\Http\Controllers\Api\V1\Order;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Delivery\StoreDeliveryVerificationRequest;
use App\Http\Resources\DeliveryVerificationResource;
use App\Models\DeliveryVerification;
use App\Models\Shipment;
use App\Services\Order\DeliveryVerificationService;
use Illuminate\Http\Request;

/**
 * R-03 / decision B: Admin final delivery verification. Records an append-only operational outcome
 * (received / not_received / return) with actor + timestamp + note. Idempotent by Idempotency-Key.
 */
class DeliveryVerificationController extends Controller
{
    public function __construct(private readonly DeliveryVerificationService $service) {}

    public function index(Request $request, Shipment $shipment)
    {
        $this->authorize('view', [DeliveryVerification::class, $shipment]);

        return $this->ok(
            DeliveryVerificationResource::collection($this->service->history($shipment))->resolve()
        );
    }

    public function store(StoreDeliveryVerificationRequest $request, Shipment $shipment)
    {
        $this->authorize('create', [DeliveryVerification::class, $shipment]);

        // R-03: the Idempotency-Key is mandatory — a retried submission must be replayable, and a
        // reused key for a different request must never silently return an unrelated record.
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || trim($key) === '') {
            throw new ApiException(
                __('messages.delivery_verification.idempotency_key_required'), 422,
                ['idempotency_key' => __('messages.system.field_required')],
            );
        }

        $key = trim($key);

        if (mb_strlen($key) > 100) {
            throw new ApiException(
                __('messages.delivery_verification.idempotency_key_invalid'), 422,
                ['idempotency_key' => __('messages.system.field_invalid')],
            );
        }

        $verification = $this->service->record(
            $shipment,
            $request->user(),
            $request->string('outcome')->toString(),
            $request->input('note'),
            $key,
        );

        return $this->created(
            new DeliveryVerificationResource($verification->load('verifiedBy')),
            __('messages.delivery_verification.recorded')
        );
    }
}
