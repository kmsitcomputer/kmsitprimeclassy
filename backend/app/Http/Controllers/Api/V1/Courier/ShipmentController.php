<?php

namespace App\Http\Controllers\Api\V1\Courier;

use App\Http\Controllers\Controller;
use App\Http\Requests\Courier\AssignCourierRequest;
use App\Http\Requests\Fulfillment\UpdateShipmentStatusRequest;
use App\Http\Resources\CourierOrderResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\ShipmentReceiptResource;
use App\Models\ActivityLog;
use App\Models\Courier;
use App\Models\Shipment;
use App\Services\Logging\ActivityLogger;
use App\Services\Order\CourierService;
use Illuminate\Http\Request;

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

        // R-04 / §C: a Kurir / Sales-Kurir-Sub must receive the courier projection (their own items
        // only, no recipient/sibling/financial leakage) — never the generic OrderResource.
        $order = $shipment->order->load(['items.shipment.courier.user', 'items.shipment.proof', 'shipments.courier.user']);

        $payload = $request->user()->isRole('kurir', 'sales-kurir-sub')
            ? new CourierOrderResource($order)
            : new OrderResource($order);

        return $this->ok($payload, __('messages.order.status_updated'));
    }

    /**
     * Thermal shipping-receipt — a READ operation. Never changes order/item/
     * shipment status, picked_up_at (Shipment.shipped_at), courier
     * assignment, shipping fee, or payment/commission state; reprinting is
     * simply calling this again (ShipmentPolicy::printReceipt gates WHO,
     * ShipmentReceiptResource derives WHICH mode from actual shipment state).
     */
    public function receipt(Request $request, Shipment $shipment)
    {
        $this->authorize('printReceipt', $shipment);

        $shipment->load(['order.paymentMethod', 'order.items', 'courier', 'selfDeliveredBy', 'orderItems']);
        $shipmentDates = $shipment->orderItems
            ->where('status', '!=', 'dibatalkan')->where('fulfilled_quantity', '>', 0)
            ->map(fn ($item) => $item->requested_delivery_date?->toDateString() ?? $shipment->order->delivery_date_estimate?->toDateString() ?? '')
            ->unique();
        if ($shipmentDates->count() > 1) {
            abort(422, 'Shipment spans multiple delivery-date groups.');
        }

        $isReprint = ActivityLog::query()
            ->where('subject_type', Shipment::class)
            ->where('subject_id', $shipment->id)
            ->where('event', 'shipment.receipt_printed')
            ->exists();

        $mode = $shipment->shipped_at !== null ? 'post_pickup' : 'pre_pickup';

        ActivityLogger::log($request->user()->id, $shipment, 'shipment.receipt_printed', null, [
            'order_id' => $shipment->order_id, 'mode' => $mode,
            'is_reprint' => $isReprint, 'actor_role' => $request->user()->role?->slug,
        ]);

        return $this->ok(new ShipmentReceiptResource($shipment));
    }
}
