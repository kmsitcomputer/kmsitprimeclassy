<?php

namespace App\Http\Controllers\Api\V1\Checkout;

use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\CourierOptionsRequest;
use App\Http\Requests\Checkout\QuoteCheckoutRequest;
use App\Models\Order;
use App\Services\Checkout\CheckoutStepResolver;
use App\Services\Order\OrderService;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutStepResolver $stepResolver,
        private readonly OrderService $orderService,
    ) {}

    /**
     * Dynamic step list the wizard renders from — never hard-coded on the
     * frontend. Accepts an optional `shipping_method` query param (set once
     * the konsumen has chosen one) so `payment_methods` narrows accordingly
     * (e.g. Ekspedisi -> Manual Transfer only) — an invalid/unrecognized
     * value is just ignored (treated as "not yet chosen"), never a hard
     * error, since this is a display hint, not something the frontend must
     * get exactly right before this endpoint will respond.
     */
    public function steps(Request $request)
    {
        $shippingMethod = in_array($request->query('shipping_method'), ['rajaongkir', 'openroute'], true)
            ? $request->query('shipping_method')
            : null;

        return $this->ok($this->stepResolver->resolve($request->user(), $shippingMethod));
    }

    /** Server-computed total preview for the Review step — never trusted from the client afterwards. */
    public function quote(QuoteCheckoutRequest $request)
    {
        $actor = $request->user();
        $konsumen = $this->resolveKonsumen($actor, $request->integer('konsumen_id') ?: null);

        $this->authorize('create', [Order::class, $konsumen]);

        $quote = $this->orderService->quote(
            $konsumen, $request->array('items'), $request->destinationInput(),
            $request->input('shipping_method'), $request->selectedCourierOption(),
        );

        return $this->ok($quote);
    }

    /** Courier/service options under "Ekspedisi" (RajaOngkir) for the checkout's courier-picker sub-step. */
    public function courierOptions(CourierOptionsRequest $request)
    {
        $actor = $request->user();
        $konsumen = $this->resolveKonsumen($actor, $request->integer('konsumen_id') ?: null);

        $this->authorize('create', [Order::class, $konsumen]);

        $options = $this->orderService->courierOptions($konsumen, $request->array('items'), $request->destinationInput());

        return $this->ok($options);
    }
}
