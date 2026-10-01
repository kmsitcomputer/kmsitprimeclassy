<?php

namespace App\Http\Controllers\Api\V1\Order;

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

        $verification = $this->service->record(
            $shipment,
            $request->user(),
            $request->string('outcome')->toString(),
            $request->input('note'),
            $request->header('Idempotency-Key'),
        );

        return $this->created(
            new DeliveryVerificationResource($verification->load('verifiedBy')),
            __('messages.delivery_verification.recorded')
        );
    }
}
