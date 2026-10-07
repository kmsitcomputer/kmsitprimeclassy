<?php

namespace App\Http\Controllers\Api\V1\Order;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\CancelOrderRequest;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Requests\Order\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Order\OrderFilterService;
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

        $query = $this->scopedQuery($request)->with(['items.shipment.courier.user', 'items.shipment.proof', 'konsumen', 'sales', 'korsal', 'paymentMethod', 'shipments.courier.user', 'shipments.proof'])->latest();

        $orders = $query->paginate($request->integer('per_page', 15));

        return $this->ok(OrderResource::collection($orders)->resolve(), meta: [
            'current_page' => $orders->currentPage(),
            'last_page' => $orders->lastPage(),
            'total' => $orders->total(),
        ]);
    }

    /**
     * The role-scoped order query. The role scope is wrapped in ONE nested group so any filter added
     * afterwards is ANDed with it — an `orWhere` scope branch can never be widened by a filter.
     */
    private function scopedQuery(Request $request)
    {
        $user = $request->user();
        $query = Order::query();

        if ($user->isRole('konsumen')) {
            $query->where('konsumen_id', $user->id);
        } elseif ($user->isRole('sales-kurir-sub')) {
            $query->where(fn ($scope) => $scope
                ->where('sales_id', $user->id)
                ->orWhere('konsumen_id', $user->id)
                // IMP-004 (mirrors OrderPolicy::view): current-scope konsumen after an audited reassignment.
                ->orWhereHas('konsumen', fn ($k) => $k->where('sales_id', $user->id)->where('agent_id', $user->agent_id)));
        } elseif ($user->isRole('sales')) {
            $query->where(fn ($scope) => $scope
                ->where('sales_id', $user->id)
                ->orWhereHas('konsumen', fn ($k) => $k->where('sales_id', $user->id)->where('agent_id', $user->agent_id)));
        } elseif ($user->isRole('korsal')) {
            $query->where(fn ($scope) => $scope
                ->where('korsal_id', $user->id)
                ->orWhereHas('konsumen', fn ($k) => $k->where('korsal_id', $user->id)->where('agent_id', $user->agent_id)));
        } elseif ($user->isRole('gudang')) {
            // IMP-001 Gap 2: Gudang sees only the warehouse work queue (diproses AND no courier assigned).
            $query->where('status', 'diproses')
                ->whereDoesntHave('shipments', fn ($shipment) => $shipment
                    ->whereNotNull('courier_id')
                    ->orWhereNotNull('self_delivered_by_user_id'));
        }
        // agen/admin/keuangan/koordinator: BelongsToAgentScope applies. super_admin: all, optional ?agent_id=.
        if ($user->isRole('super_admin') && $request->filled('agent_id')) {
            $query->where('agent_id', $request->integer('agent_id'));
        }

        $filters = app(OrderFilterService::class);
        $financial = $user->isRole('super_admin', 'agen', 'admin', 'keuangan', 'konsumen', 'sales', 'sales-kurir-sub', 'korsal');
        $filters->applyStatusFilters($query, $request, $financial);

        // Dispatch-equivalent filters (delivery date, region, paid) — office/branch roles and the
        // Sales/Korsal inside their own scope; operational-only roles never get the money bucket.
        if (! $financial) {
            $request->query->remove('paid');
        }
        $filters->applyDispatchFilters($query, $request);

        return $query;
    }

    /** Region options for the Order filters, derived only from the caller's own scoped orders. */
    public function regions(Request $request)
    {
        if ($request->user()->isRole('kurir')) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        $options = function (string $idColumn, string $nameColumn, array $parents) use ($request) {
            $query = $this->scopedQuery($request);
            foreach ($parents as $column => $value) {
                $query->where($column, $value);
            }

            return $query->whereNotNull($idColumn)
                ->selectRaw("{$idColumn} as id, MAX({$nameColumn}) as name")
                ->groupBy($idColumn)->orderBy('name')->get()
                ->map(fn ($row) => ['id' => (string) $row->id, 'name' => (string) $row->name])
                ->filter(fn ($o) => $o['name'] !== '')->values()->all();
        };

        $province = $request->filled('province_id') ? $request->string('province_id')->toString() : null;
        $regency = $request->filled('regency_id') ? $request->string('regency_id')->toString() : null;
        $district = $request->filled('district_id') ? $request->string('district_id')->toString() : null;

        return $this->ok([
            'provinces' => $options('province_id', 'province_snapshot', []),
            'regencies' => $province ? $options('regency_id', 'regency_snapshot', ['province_id' => $province]) : [],
            'districts' => $regency ? $options('district_id', 'district_snapshot', ['regency_id' => $regency]) : [],
            'villages' => $district ? $options('village_id', 'village_snapshot', ['district_id' => $district]) : [],
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
