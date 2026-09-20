<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Http\Controllers\Controller;
use App\Services\Stock\WarehouseStockService;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    public function __construct(private readonly WarehouseStockService $stocks) {}

    public function receive(Request $request)
    {
        $data = $request->validate(['product_id' => ['nullable', 'integer', 'exists:products,id'], 'variation_id' => ['nullable', 'integer', 'exists:product_variations,id'], 'quantity' => ['required', 'integer', 'min:1'], 'reference' => ['required', 'string', 'max:255'], 'note' => ['nullable', 'string', 'max:255']]);
        $stock = $this->stocks->receiveFactoryStock($request->user(), (int) ($data['product_id'] ?? 0), $data['variation_id'] ?? null, $data['quantity'], $data['reference'], $data['note'] ?? null);

        return $this->created($stock);
    }

    public function adjustPlan(Request $request)
    {
        $data = $request->validate(['product_id' => ['nullable', 'integer', 'exists:products,id'], 'variation_id' => ['nullable', 'integer', 'exists:product_variations,id'], 'delta' => ['required', 'integer', 'not_in:0'], 'reference' => ['required', 'string', 'max:255']]);

        return $this->ok($this->stocks->adjustFactoryPlan($request->user(), (int) ($data['product_id'] ?? 0), $data['variation_id'] ?? null, $data['delta'], $data['reference']));
    }

    public function sellable(Request $request)
    {
        $data = $request->validate(['product_id' => ['nullable', 'integer'], 'variation_id' => ['nullable', 'integer']]);
        $agentId = $request->user()->agent_id;
        $result = isset($data['variation_id']) ? $this->stocks->sellableForVariation($agentId, $data['variation_id']) : $this->stocks->sellableForProduct($agentId, $data['product_id']);

        return $this->ok($result);
    }
}
