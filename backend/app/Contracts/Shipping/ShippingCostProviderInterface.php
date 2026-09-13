<?php

namespace App\Contracts\Shipping;

use App\DataTransferObjects\ShippingQuoteContext;
use App\DataTransferObjects\ShippingQuoteResult;
use App\Exceptions\ShippingQuoteException;
use App\Models\ShippingProvider;

/**
 * The contract RajaOngkir and OpenRoute both satisfy so ShippingQuoteService
 * never branches on which provider it's asking. Implementations must throw
 * ShippingQuoteException (never let a raw HTTP/timeout exception escape) so
 * the caller can log it and fall back safely — never block checkout because
 * a shipping API is down.
 */
interface ShippingCostProviderInterface
{
    /** @throws ShippingQuoteException */
    public function quote(ShippingQuoteContext $context, ShippingProvider $providerRow): ShippingQuoteResult;
}
