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

        $query = Order::query()->with(['items.shipment.courier.user', 'items.shipment.proof', 'konsumen', 'sales', 'korsal', 'paymentMethod', 'shipments.courier.user', 'shipments.proof'])->latest();

        if ($user->isRole('konsumen')) {
            $query->where('konsumen_id', $user->id);
        } elseif ($user->isRole('sales')) {
            $query->where('sales_id', $user->id);
        } elseif ($user->isRole('korsal')) {
            $query->where('korsal_id', $user->id);
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

        $order->load(['items.shipment.courier.user', 'items.shipment.proof', 'konsumen', 'sales', 'korsal', 'paymentMethod', 'paymentTransactions.bankTransferVerification', 'paymentTransactions.codPaymentProof.proof', 'shipments.courier.user', 'shipments.proof', 'returnRequests.items']);

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
