<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Http\Controllers\Controller;
use App\Services\Stock\WarehouseStockService;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    public function __construct(private readonly WarehouseStockService $stocks) {}

    public function sellable(Request $request)
    {
        $data = $request->validate(['product_id' => ['nullable', 'integer'], 'variation_id' => ['nullable', 'integer']]);
        $agentId = $request->user()->agent_id;
        $result = isset($data['variation_id']) ? $this->stocks->sellableForVariation($agentId, $data['variation_id']) : $this->stocks->sellableForProduct($agentId, $data['product_id']);

        return $this->ok($result);
    }
}
