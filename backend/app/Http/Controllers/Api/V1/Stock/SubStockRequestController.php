<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\SubStockRequest;
use App\Models\WarehouseStock;
use App\Services\Stock\SubLocationOwnershipService;
use App\Services\Stock\SubStockRequestService;
use App\Services\Stock\SubStockService;
use Illuminate\Http\Request;

/**
 * R-02: Sales-Kurir-Sub stock requests (Transit <-> Sub) and the Sub's own stock view. Authority is enforced
 * in SubStockRequestService (role + same-Agent lock); routes only give the coarse role gate.
 */
class SubStockRequestController extends Controller
{
    private const WITH = ['items.product:id,name,sku', 'items.variation', 'subLocation:id,code,name', 'requester:id,name', 'transfer.handover'];

    public function __construct(private readonly SubStockRequestService $requests) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $query = SubStockRequest::query()->with(self::WITH)->latest();
        if ($user->isRole('sales-kurir-sub')) {
            $query->where('requested_by', $user->id);
        }
        foreach (['status', 'direction'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->string($filter)->toString());
            }
        }
        $page = $query->paginate($request->integer('per_page', 15));

        return $this->ok($page->items(), meta: ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }

    public function show(Request $request, SubStockRequest $subStockRequest)
    {
        $this->authorize('view', $subStockRequest);

        return $this->ok($subStockRequest->load(self::WITH));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'direction' => ['required', 'in:replenish,return'],
            'note' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.product_variation_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        $created = $this->requests->create($request->user(), $data['direction'], $data['items'], $data['note'] ?? null, $request->header('Idempotency-Key'));

        return $this->created($created->load(self::WITH));
    }

    public function approve(Request $request, SubStockRequest $subStockRequest)
    {
        return $this->ok($this->requests->approve($request->user(), $subStockRequest)->load(self::WITH));
    }

    public function reject(Request $request, SubStockRequest $subStockRequest)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->ok($this->requests->reject($request->user(), $subStockRequest, $data['reason'])->load(self::WITH));
    }

    public function cancel(Request $request, SubStockRequest $subStockRequest)
    {
        return $this->ok($this->requests->cancel($request->user(), $subStockRequest)->load(self::WITH));
    }

    public function execute(Request $request, SubStockRequest $subStockRequest)
    {
        return $this->ok($this->requests->execute($request->user(), $subStockRequest)->load(self::WITH));
    }

    public function receive(Request $request, SubStockRequest $subStockRequest)
    {
        return $this->ok($this->requests->receive($request->user(), $subStockRequest)->load(self::WITH));
    }

    /** Valid catalog targets for replenishment; independent of current Transit or Sub rows. */
    public function replenishmentTargets(Request $request)
    {
        return $this->ok($this->requests->replenishmentTargets($request->user()));
    }

    /** The Sales-Kurir-Sub's own Sub Location with physical / reserved / sellable per target. */
    public function myStock(Request $request, SubLocationOwnershipService $ownership, SubStockService $subStock)
    {
        $location = $ownership->locationOf($request->user());
        if (! $location) {
            throw new ApiException('Anda belum memiliki Sub Location aktif.', 404);
        }
        $rows = WarehouseStock::withoutGlobalScopes()->where('stock_type', 'sub')->where('sub_location_id', $location->id)->with(['product:id,name,sku', 'variation'])->get()
            ->map(fn ($row) => [
                'product_id' => $row->product_id, 'product_variation_id' => $row->product_variation_id,
                'product' => $row->product, 'variation' => $row->variation,
            ] + $subStock->sellable($location->id, $row->product_variation_id ? null : $row->product_id, $row->product_variation_id))
            ->values();

        return $this->ok(['sub_location' => $location->only(['id', 'code', 'name', 'address', 'contact_number']), 'stocks' => $rows]);
    }
}
