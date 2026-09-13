<?php

namespace App\Exceptions;

/** Thrown by a ShippingCostProviderInterface implementation — always caught by ShippingQuoteService, never surfaced raw to the client. */
class ShippingQuoteException extends \RuntimeException {}
