<?php

namespace App\Http\Controllers\Api\V1\Courier;

use App\Http\Controllers\Controller;
use App\Http\Requests\Courier\AssignCourierRequest;
use App\Http\Requests\Fulfillment\UpdateShipmentStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Courier;
use App\Models\Shipment;
use App\Services\Order\CourierService;

/**
 * Per-shipment courier assignment + delivery progress — the counterpart of
 * OrderController::updateStatus for the diproses->dikirim->terkirim leg,
 * scoped to a single Shipment rather than the whole order (Blueprint
 * §Courier: "satu order bisa beberapa kurir").
 */
class ShipmentController extends Controller
{
    public function __construct(private readonly CourierService $courierService) {}

    public function assign(AssignCourierRequest $request, Shipment $shipment)
    {
        $this->authorize('assignCourier', $shipment);

        $courier = Courier::query()->findOrFail($request->integer('courier_id'));

        $shipment = $this->courierService->assignCourier($shipment, $courier, $request->user());

        return $this->ok(
            new OrderResource($shipment->order->load(['items', 'shipments.courier.user'])),
            __('messages.courier.assigned')
        );
    }

    public function updateStatus(UpdateShipmentStatusRequest $request, Shipment $shipment)
    {
        $this->authorize('updateStatus', $shipment);

        $shipment = $this->courierService->updateShipmentStatus(
            $shipment, $request->string('status')->toString(), $request->user(), $request->file('proof')
        );

        return $this->ok(
            new OrderResource($shipment->order->load(['items', 'shipments.courier.user'])),
            __('messages.order.status_updated')
        );
    }
}
