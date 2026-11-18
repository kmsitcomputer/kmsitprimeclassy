<?php

namespace App\Http\Controllers\Api\V1\Catalog\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Requires a `warehouseStockService` property (WarehouseStockService). */
trait AttachesAgentAvailability
{
    /**
     * Mutates the given Product models in place, attaching a plain,
     * non-persisted `agent_available_quantity` attribute (and the same on
     * each loaded variation) resolved strictly from the viewer's own
     * agent_id — read via a single batched query per level, not N+1.
     */
    protected function attachAgentAvailability(Request $request, Collection $products): void
    {
        $source = $request->validate(['stock_source' => ['nullable', 'in:agent,sub'], 'sub_location_id' => ['nullable', 'integer']]);
        $actor = $request->user() ?? $request->user('sanctum');
        $subLocation = null;
        if (($source['stock_source'] ?? 'agent') === 'sub' || isset($source['sub_location_id'])) {
            if (! $actor) {
                throw new \App\Exceptions\ApiException('Login diperlukan untuk memilih sumber stok.', 403);
            }
            $resolved = app(\App\Services\Stock\StockSourceResolver::class)->resolve($actor, $actor, $source['stock_source'] ?? null, $source['sub_location_id'] ?? null);
            $subLocation = $resolved['sub_location'];
        }
        $agentId = $actor?->agent_id;

        if (! $agentId) {
            return;
        }

        $simpleProductIds = $products->where('has_variations', false)->pluck('id');
        $variationIds = $products->flatMap(fn ($p) => $p->has_variations ? $p->variations->pluck('id') : collect());
        $stock = $this->warehouseStockService->sellableForTargets($agentId, $simpleProductIds, $variationIds);
        foreach ($products as $product) {
            if (! $product->has_variations) {
                $product->sellable_stock = $stock['p:'.$product->id] ?? ['available' => 0];
                $product->agent_available_quantity = $product->sellable_stock['available'];
            } else {
                foreach ($product->variations as $variation) {
                    $variation->sellable_stock = $stock['v:'.$variation->id] ?? ['available' => 0];
                    $variation->agent_available_quantity = $variation->sellable_stock['available'];
                }
            }
        }
        if ($subLocation !== null) {
            $subStock = app(\App\Services\Stock\SubStockService::class);
            foreach ($products as $product) {
                if (! $product->has_variations) {
                    $product->sellable_stock = ['available' => $subStock->sellable($subLocation->id, $product->id, null)['sellable']];
                } else {
                    foreach ($product->variations as $variation) {
                        $variation->sellable_stock = ['available' => $subStock->sellable($subLocation->id, null, $variation->id)['sellable']];
                    }
                }
            }
        }
    }
}
