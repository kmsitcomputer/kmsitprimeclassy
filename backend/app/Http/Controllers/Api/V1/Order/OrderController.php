<?php

namespace App\Http\Controllers\Api\V1\Order;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\CancelOrderRequest;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Requests\Order\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Order\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orderService) {}

    /** Listing is already confined to the caller's own branch by BelongsToAgentScope + role narrowing. */
    public function index(Request $request)
    {
        $user = $request->user();

        // R-04 / §C: a normal Kurir must not use the generic order list — /kurir/orders is the only
        // authorized (minimized) discovery surface, and generic OrderResource would leak
        // recipient/contact/address + sibling items.
        if ($user->isRole('kurir')) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        $query = Order::query()->with(['items.shipment.courier.user', 'items.shipment.proof', 'konsumen', 'sales', 'korsal', 'paymentMethod', 'shipments.courier.user', 'shipments.proof'])->latest();

        if ($user->isRole('konsumen')) {
            $query->where('konsumen_id', $user->id);
        } elseif ($user->isRole('sales-kurir-sub')) {
            $query->where(fn ($scope) => $scope
                ->where('sales_id', $user->id)
                ->orWhere('konsumen_id', $user->id))
                // IMP-004 (mirrors OrderPolicy::view): after an audited
                // konsumen reassignment the new sales-kurir-sub may also see
                // orders whose konsumen is CURRENTLY inside its scope (the
                // order's referral snapshot is historical).
                ->orWhereHas('konsumen', fn ($k) => $k
                    ->where('sales_id', $user->id)
                    ->where('agent_id', $user->agent_id));
        } elseif ($user->isRole('sales')) {
            $query->where('sales_id', $user->id)
                // IMP-004: same current-scope addition for the plain sales role.
                ->orWhereHas('konsumen', fn ($k) => $k
                    ->where('sales_id', $user->id)
                    ->where('agent_id', $user->agent_id));
        } elseif ($user->isRole('korsal')) {
            $query->where('korsal_id', $user->id)
                // IMP-004: korsal current-chain mirror (sales reassigned to
                // this korsal → their konsumen's current korsal_id is ours).
                ->orWhereHas('konsumen', fn ($k) => $k
                    ->where('korsal_id', $user->id)
                    ->where('agent_id', $user->agent_id));
        }
        // R-04 / §C: a normal Kurir must NOT use the generic order list — /kurir/orders is the only
        // authorized (minimized) discovery surface (throws above).
        //
        // IMP-001 UAT remediation (Gap 2): Gudang may see an order ONLY while it is in the warehouse
        // work queue — status exactly 'diproses' AND no courier assigned yet (no shipment carries
        // courier_id, and no self_sub shipment has a self-delivering Sales-Kurir-Sub). This is
        // enforced here too — NOT just in OrderPolicy::view — so a direct /orders API call can never
        // bypass the scope rule. The moment status moves on or any courier is assigned, the order
        // disappears from this list (server-side; frontend filtering alone would be insufficient).
        elseif ($user->isRole('gudang')) {
            $query->where('status', 'diproses')
                ->whereDoesntHave('shipments', fn ($shipment) => $shipment
                    ->whereNotNull('courier_id')
                    ->orWhereNotNull('self_delivered_by_user_id'));
        }
        // agen/admin: BelongsToAgentScope already applies. super_admin sees
        // every branch by default, optionally narrowed to one via ?agent_id=.
        if ($user->isRole('super_admin') && $request->filled('agent_id')) {
            $query->where('agent_id', $request->integer('agent_id'));
        }

        $orders = $query->paginate($request->integer('per_page', 15));

        return $this->ok(OrderResource::collection($orders)->resolve(), meta: [
            'current_page' => $orders->currentPage(),
            'last_page' => $orders->lastPage(),
            'total' => $orders->total(),
        ]);
    }

    public function store(StoreOrderRequest $request)
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! $idempotencyKey) {
            throw new ApiException(
                __('messages.payment.idempotency_key_required'), 422, ['idempotency_key' => __('messages.system.field_required')]
            );
        }

        $actor = $request->user();
        $konsumen = $this->resolveKonsumen($actor, $request->integer('konsumen_id') ?: null);

        $this->authorize('create', [Order::class, $konsumen]);

        $wasExisting = Order::query()
            ->where('konsumen_id', $konsumen->id)->where('idempotency_key', $idempotencyKey)->exists();

        $order = $this->orderService->createOrder(
            $konsumen,
            $request->array('items'),
            $request->destinationInput(),
            $actor,
            $request->string('payment_method_code')->toString(),
            $request->input('delivery_date'),
            $idempotencyKey,
            $request->input('shipping_method'),
            $request->filled('dp_amount') ? (float) $request->input('dp_amount') : null,
            $request->selectedCourierOption(),
            $request->input('stock_source'),
            $request->filled('sub_location_id') ? $request->integer('sub_location_id') : null,
            $request->filled('voucher_code') ? $request->string('voucher_code')->toString() : null,
        );

        Log::info('order.created', [
            'order_id' => $order->id, 'order_no' => $order->order_no,
            'konsumen_id' => $konsumen->id, 'created_by' => $actor->id, 'idempotent_replay' => $wasExisting,
        ]);

        return $this->created(new OrderResource($order), __('messages.order.created'));
    }

    public function show(Request $request, Order $order)
    {
        $this->authorize('view', $order);

        $order->load(['items.shipment.courier.user', 'items.shipment.proof', 'konsumen', 'sales', 'korsal', 'paymentMethod', 'paymentTransactions.bankTransferVerification', 'paymentTransactions.codPaymentProof.proof', 'shipments.courier.user', 'shipments.proof', 'shipments.selfDeliveredBy', 'shipments.deliveryVerifications.verifiedBy', 'deliveryVerifications.verifiedBy', 'returnRequests.items']);

        return $this->ok(new OrderResource($order));
    }

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order)
    {
        $this->authorize('updateStatus', $order);

        $order = $this->orderService->updateStatus($order, $request->string('status')->toString(), $request->user());

        Log::info('order.status_changed', ['order_id' => $order->id, 'status' => $order->status, 'by' => $request->user()->id]);

        $order->load(['items.shipment.courier.user', 'items.shipment.proof', 'konsumen', 'sales', 'korsal', 'shipments.courier.user', 'shipments.proof']);

        return $this->ok(new OrderResource($order), __('messages.order.status_updated'));
    }

    public function cancel(CancelOrderRequest $request, Order $order)
    {
        $this->authorize('cancel', $order);

        $order = $this->orderService->cancel($order, $request->user(), $request->string('reason')->toString());

        Log::info('order.cancelled', ['order_id' => $order->id, 'by' => $request->user()->id]);

        $order->load(['items.shipment.courier.user', 'items.shipment.proof', 'konsumen', 'sales', 'korsal', 'paymentMethod', 'shipments.courier.user', 'shipments.proof']);

        return $this->ok(new OrderResource($order), __('messages.order.cancelled'));
    }
}
