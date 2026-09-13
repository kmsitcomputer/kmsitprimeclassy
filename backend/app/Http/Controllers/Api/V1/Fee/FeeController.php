<?php

namespace App\Http\Controllers\Api\V1\Fee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fee\SetFeeRequest;
use App\Http\Resources\FeeResource;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Services\Fee\FeeService;
use Illuminate\Support\Facades\Log;

/**
 * "Fee hanya boleh diberikan kepada role yang mempunyai permission untuk
 * melihatnya" — every action here is gated by ProductPolicy::viewFees
 * (read) or ::manage (write, super_admin only). Never reachable from a
 * public route (see routes/api_v1.php) and never referenced by
 * ProductResource/ProductVariationResource.
 */
class FeeController extends Controller
{
    public function __construct(private readonly FeeService $feeService) {}

    public function showForProduct(Product $product)
    {
        $this->authorize('viewFees', Product::class);

        return $this->ok(new FeeResource($this->feeService->resolveForProduct($product)));
    }

    public function setForProduct(SetFeeRequest $request, Product $product)
    {
        $this->authorize('manage', Product::class);

        $this->feeService->setForProduct(
            $product, (float) $request->input('agent_fee'), (float) $request->input('sales_fee'), (float) $request->input('courier_fee')
        );

        Log::info('fee.updated', ['product_id' => $product->id, 'by' => $request->user()->id]);

        return $this->ok(new FeeResource($this->feeService->resolveForProduct($product)), __('messages.fee.updated_product'));
    }

    public function showForVariation(Product $product, ProductVariation $variation)
    {
        $this->authorize('viewFees', Product::class);

        return $this->ok(new FeeResource($this->feeService->resolveForVariation($variation)));
    }

    public function setForVariation(SetFeeRequest $request, Product $product, ProductVariation $variation)
    {
        $this->authorize('manage', Product::class);

        $this->feeService->setForVariation(
            $variation, (float) $request->input('agent_fee'), (float) $request->input('sales_fee'), (float) $request->input('courier_fee')
        );

        Log::info('fee.updated', ['product_variation_id' => $variation->id, 'by' => $request->user()->id]);

        return $this->ok(new FeeResource($this->feeService->resolveForVariation($variation)), __('messages.fee.updated_variation'));
    }
}
