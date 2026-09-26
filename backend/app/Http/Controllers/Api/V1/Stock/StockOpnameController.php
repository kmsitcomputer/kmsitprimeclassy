<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Http\Controllers\Controller;
use App\Models\StockOpname;
use App\Services\Stock\StockOpnameService;
use Illuminate\Http\Request;

class StockOpnameController extends Controller
{
    public function __construct(private readonly StockOpnameService $opnames) {}

    public function index(Request $request)
    {
        $opnames = StockOpname::query()->with(['items.product', 'items.variation.compositions.option'])->latest()->paginate($request->integer('per_page', 15));

        return $this->ok($opnames->items(), meta: [
            'current_page' => $opnames->currentPage(), 'last_page' => $opnames->lastPage(),
            'per_page' => $opnames->perPage(), 'total' => $opnames->total(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', StockOpname::class);
        $data = $request->validate(['opname_type' => ['required', 'string'], 'stock_type' => ['nullable', 'string'], 'sub_location_id' => ['nullable', 'integer'], 'notes' => ['nullable', 'string', 'max:255'], 'items' => ['required', 'array', 'min:1'], 'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'], 'items.*.product_variation_id' => ['nullable', 'integer', 'exists:product_variations,id']]);

        return $this->created($this->opnames->create($request->user(), $data['opname_type'], $data['stock_type'] ?? null, $data['sub_location_id'] ?? null, $data['items'], $data['notes'] ?? null));
    }

    public function show(Request $request, StockOpname $opname)
    {
        $this->authorize('view', $opname);

        return $this->ok($opname->load(['items.product', 'items.variation.compositions.option']));
    }

    public function count(Request $request, StockOpname $opname)
    {
        $this->authorize('edit', $opname);
        $data = $request->validate(['counts' => ['required', 'array'], 'counts.*.item_id' => ['required', 'integer'], 'counts.*.counted_quantity' => ['required', 'integer', 'min:0']]);

        return $this->ok($this->opnames->count($request->user(), $opname, $data['counts'])->load(['items.product', 'items.variation.compositions.option']));
    }

    public function submit(Request $request, StockOpname $opname)
    {
        $this->authorize('edit', $opname);

        return $this->ok($this->opnames->submit($request->user(), $opname)->load(['items.product', 'items.variation.compositions.option']));
    }

    public function approve(Request $request, StockOpname $opname)
    {
        $this->authorize('approve', $opname);

        return $this->ok($this->opnames->approve($request->user(), $opname)->load(['items.product', 'items.variation.compositions.option']));
    }

    public function reject(Request $request, StockOpname $opname)
    {
        $this->authorize('reject', $opname);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->ok($this->opnames->reject($request->user(), $opname, $data['reason'])->load(['items.product', 'items.variation.compositions.option']));
    }

    public function cancel(Request $request, StockOpname $opname)
    {
        $this->authorize('cancel', $opname);

        return $this->ok($this->opnames->cancel($request->user(), $opname)->load(['items.product', 'items.variation.compositions.option']));
    }
}
