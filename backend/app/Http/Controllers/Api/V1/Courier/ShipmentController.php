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
use App\Models\OrderItem;
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

        // A1-01: courier_id=0 is the Koordinator self-executor sentinel — resolve
        // the actor's own Courier profile (created on first self-assignment) so
        // the assignment records THEM as the executor. A genuine Courier row is
        // used for every other call; the request validation guarantees existence.
        if (! $request->wantsCourier()) {
            $actor = $request->user();
            if (! $actor->isRole('koordinator-kurir')) {
                abort(403);
            }
            $courier = $this->courierService->ensureSelfExecutorProfile($actor);
        } else {
            $courier = Courier::query()->findOrFail($request->integer('courier_id'));
        }

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

        // R-04 / §C: a Kurir / Sales-Kurir-Sub / Koordinator-Kurir executor must receive the courier projection (their own items
        // only, no recipient/sibling/financial leakage) — never the generic OrderResource.
        $order = $shipment->order->load(['items.shipment.courier.user', 'items.shipment.proof', 'shipments.courier.user']);

        $payload = $request->user()->isRole('kurir', 'sales-kurir-sub', 'koordinator-kurir')
            ? new CourierOrderResource($order)
            : new OrderResource($order);

        return $this->ok($payload, __('messages.order.status_updated'));
    }

    /**
     * LOCKED courier work-unit model (Human UAT): courier progress is PER OrderItem.
     * Transitions exactly ONE item; its shipment siblings keep their own state even though
     * they share the order, the shipment, the date and the courier. Authorization is the
     * same shipment-level executor rule as the bulk path (plus the service re-verifies the
     * item still belongs to this order and shipment under lock), so a forged item id can
     * never move another item or order.
     */
    public function updateItemStatus(UpdateShipmentStatusRequest $request, Shipment $shipment, OrderItem $orderItem)
    {
        $this->authorize('updateStatus', $shipment);

        if ((int) $orderItem->order_id !== (int) $shipment->order_id
            || (int) $orderItem->shipment_id !== (int) $shipment->id) {
            abort(404);
        }

        $shipment = $shipment->fresh();
        $this->courierService->updateOrderItemStatus(
            $orderItem, $request->string('status')->toString(), $request->user(), $request->file('proof')
        );

        // Same projection contract as the bulk path: executors get the courier projection.
        $order = $shipment->order->load(['items.shipment.courier.user', 'items.shipment.proof', 'shipments.courier.user']);

        $payload = $request->user()->isRole('kurir', 'sales-kurir-sub', 'koordinator-kurir')
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

        $shipment->load(['order.paymentMethod', 'courier', 'selfDeliveredBy', 'orderItems']);

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
