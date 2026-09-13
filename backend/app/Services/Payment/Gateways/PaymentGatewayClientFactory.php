<?php

namespace App\Services\Payment\Gateways;

use App\Contracts\Payment\PaymentGatewayClientInterface;
use App\Exceptions\ApiException;

/**
 * Resolves a PaymentMethod's code to its concrete gateway client. Adding a
 * fourth gateway later is one new entry here (plus the client class) — never
 * a change to PaymentService, which only ever talks to the interface.
 */
class PaymentGatewayClientFactory
{
    private const MAP = [
        'xendit' => XenditGatewayClient::class,
        'tripay' => TripayGatewayClient::class,
        'stripe' => StripeGatewayClient::class,
    ];

    public function make(string $methodCode): PaymentGatewayClientInterface
    {
        $class = self::MAP[$methodCode] ?? null;

        if (! $class) {
            throw new ApiException(__('messages.payment.unsupported_method_type', ['type' => $methodCode]), 422);
        }

        return app($class);
    }

    public static function supportedCodes(): array
    {
        return array_keys(self::MAP);
    }
}
