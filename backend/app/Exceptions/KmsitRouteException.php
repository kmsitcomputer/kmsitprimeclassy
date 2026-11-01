<?php

namespace App\Exceptions;

/**
 * A KMSIT routing failure carrying a SAFE diagnostic: a short machine reason (never a URL, token or response body) and the HTTP
 * status when there was one. Still a ShippingQuoteException, so every existing caller (failover, fail-closed) behaves the same.
 */
class KmsitRouteException extends ShippingQuoteException
{
    public function __construct(string $message, public readonly string $reason, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }
}
