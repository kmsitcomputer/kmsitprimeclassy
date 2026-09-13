<?php

namespace App\Http\Controllers\Api\V1\Fulfillment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fulfillment\AdjustOrderItemQuantityRequest;
use App\Http\Requests\Fulfillment\RescheduleOrderItemRequest;
use App\Http\Resources\OrderItemResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Order\OrderFulfillmentService;

/**
 * "Admin dapat mengubah jumlah item yang dapat dipenuhi sebelum order
 * berubah dari diproses -> dikirim" — same authorization as changing the
 * order's own status (OrderPolicy::updateStatus): agen/admin of the order's
 * branch, or super_admin.
 */
class OrderFulfillmentController extends Controller
{
    public function __construct(private readonly OrderFulfillmentService $fulfillmentService) {}

    public function adjust(AdjustOrderItemQuantityRequest $request, Order $order, OrderItem $item)
    {
        $this->authorize('manageFulfillment', $order);

        if ($item->order_id !== $order->id) {
            abort(404);
        }

        $item = $this->fulfillmentService->adjustItemQuantity(
            $item,
            $request->integer('fulfilled_quantity'),
            $request->user(),
            $request->string('reason')->toString(),
            $request->input('additional_payment_method', 'transfer'),
        );

        return $this->ok(new OrderItemResource($item), __('messages.fulfillment.adjusted'));
    }

    /** Alternative to cancelling: keep the order, just push this item's delivery to a later date. */
    public function reschedule(RescheduleOrderItemRequest $request, Order $order, OrderItem $item)
    {
        $this->authorize('manageFulfillment', $order);

        if ($item->order_id !== $order->id) {
            abort(404);
        }

        $item = $this->fulfillmentService->rescheduleItemDeliveryDate(
            $item,
            $request->string('requested_delivery_date')->toString(),
            $request->user(),
            $request->string('reason')->toString(),
            $request->filled('quantity') ? $request->integer('quantity') : null,
        );

        return $this->ok(new OrderItemResource($item), __('messages.fulfillment.rescheduled'));
    }
}
