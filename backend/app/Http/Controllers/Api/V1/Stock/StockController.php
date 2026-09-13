<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\AdjustStockRequest;
use App\Http\Resources\ProductStockResource;
use App\Http\Resources\ProductVariationStockResource;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariation;
use App\Models\ProductVariationStock;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use App\Services\Stock\StockService;
use Illuminate\Http\Request;
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

    /** Stock for products WITHOUT variations, in the caller's own agent branch. */
    public function products(Request $request)
    {
        $agentId = $this->resolveViewedAgentId($request);

        $stocks = ProductStock::query()->withoutGlobalScopes()
            ->where('agent_id', $agentId)
            ->with('product')
            ->paginate($request->integer('per_page', 15));

        return $this->ok(ProductStockResource::collection($stocks)->resolve(), meta: [
            'current_page' => $stocks->currentPage(), 'last_page' => $stocks->lastPage(), 'total' => $stocks->total(),
        ]);
    }

    /** Stock for product VARIATIONS, in the caller's own agent branch. */
    public function variations(Request $request)
    {
        $agentId = $this->resolveViewedAgentId($request);

        $stocks = ProductVariationStock::query()->withoutGlobalScopes()
            ->where('agent_id', $agentId)
            ->with('variation')
            ->paginate($request->integer('per_page', 15));

        return $this->ok(ProductVariationStockResource::collection($stocks)->resolve(), meta: [
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
