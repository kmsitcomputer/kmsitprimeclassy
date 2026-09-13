<?php

namespace App\Http\Controllers\Api\V1\Checkout;

use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\QuoteCheckoutRequest;
use App\Models\Order;
use App\Models\User;
use App\Services\Checkout\CheckoutStepResolver;
use App\Services\Order\OrderService;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutStepResolver $stepResolver,
        private readonly OrderService $orderService,
    ) {}

    /** Dynamic step list the wizard renders from — never hard-coded on the frontend. */
    public function steps(Request $request)
    {
        return $this->ok($this->stepResolver->resolve($request->user()));
    }

    /** Server-computed total preview for the Review step — never trusted from the client afterwards. */
    public function quote(QuoteCheckoutRequest $request)
    {
        $actor = $request->user();
        $konsumen = $actor->isRole('konsumen')
            ? $actor
            : User::query()->where('agent_id', $actor->agent_id)->findOrFail($request->integer('konsumen_id'));

        $this->authorize('create', [Order::class, $konsumen]);

        $quote = $this->orderService->quote(
            $konsumen, $request->array('items'), $request->destinationInput(), $request->input('shipping_method')
        );

        return $this->ok($quote);
    }
}
