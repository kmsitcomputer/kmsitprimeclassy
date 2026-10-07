<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\AdjustStockRequest;
use App\Http\Resources\ProductStockResource;
use App\Http\Resources\ProductVariationStockResource;
use App\Http\Resources\WarehouseStockResource;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariation;
use App\Models\ProductVariationStock;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\Logging\ActivityLogger;
use App\Services\Stock\SellableStockService;
use App\Services\Stock\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Admin/Agen dapat mengatur stok sesuai permission" — every action here
 * resolves the target agent server-side (the actor's own agent_id, or an
 * explicit agent_id only super_admin may supply) and every mutation goes
 * through StockService, which row-locks and forbids negative stock.
 */
class StockController extends Controller
{
    public function __construct(private readonly StockService $stockService) {}

    /**
     * Stock for products WITHOUT variations, in the viewed agent branch.
     *
     * Canonical (SellableStockService): the target list is every product the
     * branch holds in legacy OR warehouse buckets, and ADA / DITAHAN /
     * TERSEDIA come from the same formula the catalog, storefront and checkout
     * use — so this page can never disagree with "Produk & Kategori".
     */
    public function products(Request $request)
    {
        $agentId = $this->resolveViewedAgentId($request);
        $page = $this->targetPage('product_stocks', 'product_id', $agentId, $request);
        $products = Product::query()->whereIn('id', $page->pluck('product_id'))
            ->where('has_variations', false)->get()->keyBy('id');
        $sellable = app(SellableStockService::class);

        $rows = $page->getCollection()->map(function ($target) use ($products, $sellable, $agentId) {
            $product = $products->get($target->product_id);
            if (! $product) {
                return null;
            }
            $s = $sellable->forProduct($agentId, $product->id);

            return [
                'id' => $product->id, 'agent_id' => $agentId, 'product_id' => $product->id,
                'product_name' => $product->name, 'sku' => $product->sku,
                'quantity_on_hand' => $s['sellable_base'], 'quantity_reserved' => $s['reserved'],
                'quantity_available' => $s['available'], 'source' => $s['source'], 'updated_at' => null,
            ];
        })->filter()->values();

        return $this->ok($rows, meta: [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]);
    }

    /** Stock for product VARIATIONS — same canonical source as products(). */
    public function variations(Request $request)
    {
        $agentId = $this->resolveViewedAgentId($request);
        $page = $this->targetPage('product_variation_stocks', 'product_variation_id', $agentId, $request);
        $variations = ProductVariation::query()->with('product')
            ->whereIn('id', $page->pluck('product_variation_id'))->get()->keyBy('id');
        $sellable = app(SellableStockService::class);

        $rows = $page->getCollection()->map(function ($target) use ($variations, $sellable, $agentId) {
            $variation = $variations->get($target->product_variation_id);
            if (! $variation || ! $variation->product) {
                return null;
            }
            $s = $sellable->forVariation($agentId, $variation->id);

            return [
                'id' => $variation->id, 'agent_id' => $agentId, 'product_variation_id' => $variation->id,
                'sku' => $variation->sku, 'variation_label' => $variation->label(),
                'quantity_on_hand' => $s['sellable_base'], 'quantity_reserved' => $s['reserved'],
                'quantity_available' => $s['available'], 'source' => $s['source'], 'updated_at' => null,
            ];
        })->filter()->values();

        return $this->ok($rows, meta: [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]);
    }

    /**
     * One paginated page of target ids held by the agent: legacy commitment
     * rows UNION warehouse buckets (never another agent's rows).
     */
    private function targetPage(string $legacyTable, string $column, int $agentId, Request $request)
    {
        $legacy = DB::table($legacyTable)->where('agent_id', $agentId)->select($column);
        $warehouse = DB::table('warehouse_stocks')->where('agent_id', $agentId)
            ->whereIn('stock_type', ['transit', 'factory_plan', 'shipping'])
            ->whereNotNull($column)->select($column);

        return DB::query()->fromSub($legacy->union($warehouse), 't')
            ->select($column)->orderBy($column)
            ->paginate($request->integer('per_page', 15));
    }

    public function warehouse(Request $request)
    {
        $agentId = $this->resolveViewedAgentId($request);

        $stocks = WarehouseStock::withoutGlobalScopes()
            ->where('agent_id', $agentId)
            ->with(['product.images', 'variation.compositions.option', 'subLocation'])
            ->when($request->filled('stock_type'), fn ($q) => $q->where('stock_type', $request->string('stock_type')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%'.$request->string('search')->toString().'%';
                $q->where(function ($qq) use ($search) {
                    $qq->whereHas('product', fn ($p) => $p->where('name', 'like', $search)->orWhere('sku', 'like', $search))
                        ->orWhereHas('variation', fn ($v) => $v->where('sku', 'like', $search));
                });
            })
            ->paginate($request->integer('per_page', 30));

        return $this->ok(WarehouseStockResource::collection($stocks)->resolve(), meta: [
            'current_page' => $stocks->currentPage(), 'last_page' => $stocks->lastPage(), 'total' => $stocks->total(),
        ]);
    }

    public function adjust(AdjustStockRequest $request)
    {
        $actor = $request->user();
        // AdjustStockRequest::authorize() already confines this action to
        // agen/admin only — both always have their own real agent_id.
        $agentId = $actor->agent_id;

        $delta = $request->integer('delta');
        $reason = $request->string('reason')->toString();

        if ($request->filled('product_id')) {
            $product = Product::query()->findOrFail($request->integer('product_id'));

            if ($product->has_variations) {
                throw new ApiException(
                    __('messages.product.stock_uses_variation', ['name' => $product->name]),
                    422
                );
            }

            $stock = $this->stockService->adjustProduct($agentId, $product->id, $delta, $reason, $actor->id);

            Log::info('stock.adjusted', ['agent_id' => $agentId, 'product_id' => $product->id, 'delta' => $delta, 'by' => $actor->id]);
            ActivityLogger::log($actor->id, $stock, 'stock.changed', $reason, [
                'actor_role' => $actor->role?->slug, 'agent_id' => $agentId, 'delta' => $delta, 'new_quantity' => $stock->quantity_on_hand,
            ]);

            return $this->ok(new ProductStockResource($stock->load('product')), __('messages.stock.adjusted'));
        }

        $variation = ProductVariation::query()->findOrFail($request->integer('product_variation_id'));
        $stock = $this->stockService->adjustVariation($agentId, $variation->id, $delta, $reason, $actor->id);

        Log::info('stock.adjusted', ['agent_id' => $agentId, 'product_variation_id' => $variation->id, 'delta' => $delta, 'by' => $actor->id]);
        ActivityLogger::log($actor->id, $stock, 'stock.changed', $reason, [
            'actor_role' => $actor->role?->slug, 'agent_id' => $agentId, 'delta' => $delta, 'new_quantity' => $stock->quantity_on_hand,
        ]);

        return $this->ok(new ProductVariationStockResource($stock->load('variation')), __('messages.stock.adjusted'));
    }

    private function resolveViewedAgentId(Request $request): int
    {
        $actor = $request->user();

        if ($actor->isRole('super_admin')) {
            return $this->resolveAndValidateAgent($request->integer('agent_id') ?: null);
        }

        return $actor->agent_id;
    }

    private function resolveAndValidateAgent(?int $agentUserId): int
    {
        if (! $agentUserId) {
            throw new ApiException(__('messages.stock.agent_id_required'), 422, ['agent_id' => __('messages.system.field_required')]);
        }

        $isAgent = User::query()->whereHas('role', fn ($q) => $q->where('slug', 'agen'))
            ->where('id', $agentUserId)->exists();

        if (! $isAgent) {
            throw new ApiException(__('messages.stock.invalid_agent'), 422, ['agent_id' => __('messages.system.field_invalid')]);
        }

        return $agentUserId;
    }
}
