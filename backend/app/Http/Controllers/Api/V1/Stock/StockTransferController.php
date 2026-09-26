<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\StockHandover;
use App\Models\StockTransfer;
use App\Services\Stock\StockTransferService;
use Illuminate\Http\Request;

class StockTransferController extends Controller
{
    public function __construct(private readonly StockTransferService $transfers) {}

    public function index(Request $request)
    {
        $query = StockTransfer::query()->with(['items.product.images', 'items.variation.compositions.option', 'handover', 'sourceSubLocation', 'destinationSubLocation', 'creator'])->latest();
        if ($request->user()->isRole('super_admin')) {
            $query->withoutGlobalScopes();
        }
        $status = $request->string('status')->toString();
        if ($status !== '') {
            if (! in_array($status, ['pending', 'completed', 'rejected', 'cancelled'], true)) {
                throw new ApiException('Status transfer tidak valid.', 422);
            }
            $query->where('status', $status);
        }

        $transfers = $query->paginate($request->integer('per_page', 15));

        return $this->ok($transfers->items(), meta: [
            'current_page' => $transfers->currentPage(), 'last_page' => $transfers->lastPage(), 'total' => $transfers->total(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', StockTransfer::class);
        $data = $request->validate([
            'source_stock_type' => ['required', 'string'], 'source_sub_location_id' => ['nullable', 'integer'], 'destination_stock_type' => ['required', 'string'], 'destination_sub_location_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'], 'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.product_variation_id' => ['nullable', 'integer', 'exists:product_variations,id'], 'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        return $this->created($this->transfers->create($request->user(), $data['source_stock_type'], $data['source_sub_location_id'] ?? null, $data['destination_stock_type'], $data['destination_sub_location_id'] ?? null, $data['items'], $data['reference'] ?? null, $data['note'] ?? null)->load(['items.product', 'items.variation.compositions.option']));
    }

    public function show(Request $request, StockTransfer $transfer)
    {
        $this->authorize('view', $transfer);

        return $this->ok($transfer->load(['items.product', 'items.variation.compositions.option', 'handover', 'sourceSubLocation', 'destinationSubLocation']));
    }

    public function storePlanTransfer(Request $request)
    {
        $this->authorize('create', StockTransfer::class);
        $data = $request->validate([
            'reference' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'], 'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.product_variation_id' => ['nullable', 'integer', 'exists:product_variations,id'], 'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        return $this->created($this->transfers->createPlanTransfer($request->user(), $data['items'], $data['reference'] ?? null, $data['note'] ?? null));
    }

    public function approve(Request $request, StockTransfer $transfer)
    {
        $this->authorize('approve', $transfer);

        return $this->ok($this->transfers->approve($request->user(), $transfer));
    }

    public function reject(Request $request, StockTransfer $transfer)
    {
        $this->authorize('reject', $transfer);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->ok($this->transfers->reject($request->user(), $transfer, $data['reason']));
    }

    public function cancel(Request $request, StockTransfer $transfer)
    {
        $this->authorize('cancel', $transfer);

        return $this->ok($this->transfers->cancel($request->user(), $transfer)->load(['items.product', 'items.variation.compositions.option']));
    }

    public function handover(Request $request, StockHandover $handover)
    {
        $this->authorize('view', $handover);

        return $this->ok($handover->load(['transfer.items.product', 'transfer.items.variation', 'handedOverBy', 'receivedBy']));
    }

    public function printHandover(Request $request, StockHandover $handover)
    {
        $this->authorize('print', $handover);
        $handover->load(['transfer.items.product', 'transfer.items.variation', 'transfer.sourceSubLocation', 'transfer.destinationSubLocation', 'agent', 'handedOverBy', 'receivedBy']);

        return view('warehouse.handover-print', compact('handover'));
    }
}
