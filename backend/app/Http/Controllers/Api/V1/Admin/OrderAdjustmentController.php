<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderAdditionalPaymentResource;
use App\Http\Resources\OrderItemAdjustmentResource;
use App\Models\Order;
use App\Models\OrderAdditionalPayment;
use App\Models\OrderItem;
use App\Models\OrderItemAdjustment;
use App\Services\Order\OrderFulfillmentService;
use Illuminate\Http\Request;

/**
 * Admin dashboard menus for "refund" (fulfillment-shortfall adjustments) and
 * "additional payment" (fulfillment-increase) records — Blueprint: "Dashboard
 * Admin harus memiliki menu khusus untuk refund/additional payment."
 * Scoped per branch exactly like order visibility (super_admin: all,
 * agen/admin: their own branch only).
 */
class OrderAdjustmentController extends Controller
{
    public function __construct(private readonly OrderFulfillmentService $fulfillmentService) {}

    public function listRefunds(Request $request)
    {
        $actor = $request->user();

        $query = OrderItemAdjustment::query()
            ->with(['orderItem.order.konsumen', 'adjustedBy'])
            ->whereHas('orderItem.order', fn ($q) => $this->scopeToBranch($q, $actor, $request->integer('agent_id') ?: null))
            ->latest();

        $adjustments = $query->paginate($request->integer('per_page', 15));

        return $this->ok(OrderItemAdjustmentResource::collection($adjustments)->resolve(), meta: [
            'current_page' => $adjustments->currentPage(), 'last_page' => $adjustments->lastPage(), 'total' => $adjustments->total(),
        ]);
    }

    public function markRefundStatus(Request $request, OrderItemAdjustment $adjustment)
    {
        $this->authorizeBranch($request, $this->orderAgentIdForItem($adjustment->order_item_id));

        $validated = $request->validate(['refund_status' => ['required', 'in:pending,processed,failed']]);

        $adjustment = $this->fulfillmentService->markAdjustmentRefundStatus($adjustment, $request->user(), $validated['refund_status']);

        return $this->ok(new OrderItemAdjustmentResource($adjustment->load(['orderItem.order.konsumen', 'adjustedBy'])), __('messages.return.refund_marked'));
    }

    public function listAdditionalPayments(Request $request)
    {
        $actor = $request->user();

        $query = OrderAdditionalPayment::query()
            ->with(['order.konsumen', 'requestedBy', 'paymentTransaction'])
            ->whereHas('order', fn ($q) => $this->scopeToBranch($q, $actor, $request->integer('agent_id') ?: null))
            ->latest();

        $payments = $query->paginate($request->integer('per_page', 15));

        return $this->ok(OrderAdditionalPaymentResource::collection($payments)->resolve(), meta: [
            'current_page' => $payments->currentPage(), 'last_page' => $payments->lastPage(), 'total' => $payments->total(),
        ]);
    }

    public function markAdditionalPaymentStatus(Request $request, OrderAdditionalPayment $payment)
    {
        $this->authorizeBranch($request, Order::withoutGlobalScopes()->where('id', $payment->order_id)->value('agent_id'));

        $validated = $request->validate(['paid' => ['required', 'boolean']]);

        $payment = $this->fulfillmentService->markAdditionalPaymentPaid($payment, $request->user(), $validated['paid']);

        return $this->ok(new OrderAdditionalPaymentResource($payment->load(['order.konsumen', 'requestedBy', 'paymentTransaction'])), __('messages.payment.cod_status_updated'));
    }

    private function scopeToBranch($query, $actor, ?int $agentIdFilter = null)
    {
        if (! $actor->isRole('super_admin')) {
            $query->where('agent_id', $actor->agent_id);
        } elseif ($agentIdFilter) {
            $query->where('agent_id', $agentIdFilter);
        }

        return $query;
    }

    private function authorizeBranch(Request $request, ?int $orderAgentId): void
    {
        $actor = $request->user();

        if (! $actor->isRole('super_admin') && $orderAgentId !== $actor->agent_id) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }
    }

    /**
     * OrderItem carries no agent_id of its own — resolved via its order,
     * bypassing BelongsToAgentScope (which filters by the ACTING user's own
     * agent_id and would otherwise silently hide a cross-branch order,
     * turning a 403 into a null-property error instead).
     */
    private function orderAgentIdForItem(int $orderItemId): ?int
    {
        $orderId = OrderItem::query()->where('id', $orderItemId)->value('order_id');

        return Order::withoutGlobalScopes()->where('id', $orderId)->value('agent_id');
    }
}
