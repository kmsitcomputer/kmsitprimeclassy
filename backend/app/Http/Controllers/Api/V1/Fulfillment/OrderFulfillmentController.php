<?php

namespace App\Http\Controllers\Api\V1\Fulfillment;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fulfillment\AddOrderItemRequest;
use App\Http\Requests\Fulfillment\AdjustOrderItemQuantityRequest;
use App\Http\Requests\Fulfillment\RescheduleOrderItemRequest;
use App\Http\Resources\OrderItemResource;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Order\OrderFulfillmentService;
use App\Services\Order\OrderLineAdditionService;

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

    /**
     * Package C / SC-03: add a NEW product/variation line to an existing order. Admin only
     * (OrderPolicy::addLine + the dedicated role:admin route group), same-Agent. Distinct from
     * adjust(), which only changes the quantity of a line that is already present.
     *
     * A required Idempotency-Key makes a retried submission safe; a replay returns the
     * already-created line without repeating any side effect.
     */
    public function addItem(AddOrderItemRequest $request, Order $order, OrderLineAdditionService $lineAdditionService)
    {
        $this->authorize('addLine', $order);

        $idempotencyKey = $request->header('Idempotency-Key');

        if (! $idempotencyKey) {
            throw new ApiException(
                __('messages.payment.idempotency_key_required'), 422, ['idempotency_key' => __('messages.system.field_required')]
            );
        }

        [, $wasReplay] = $lineAdditionService->addLine(
            $order,
            [
                'product_id' => $request->integer('product_id'),
                'product_variation_id' => $request->filled('product_variation_id') ? $request->integer('product_variation_id') : null,
                'quantity' => $request->integer('quantity'),
            ],
            $request->user(),
            $request->filled('requested_delivery_date') ? $request->string('requested_delivery_date')->toString() : null,
            $request->string('reason')->toString(),
            $request->input('additional_payment_method', 'transfer'),
            $idempotencyKey,
        );

        $order->load(['items.shipment.courier.user', 'items.shipment.proof', 'konsumen', 'sales', 'korsal', 'paymentMethod', 'paymentTransactions.bankTransferVerification', 'shipments.courier.user', 'shipments.proof']);

        return $wasReplay
            ? $this->ok(new OrderResource($order), __('messages.order.line_added'))
            : $this->created(new OrderResource($order), __('messages.order.line_added'));
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
