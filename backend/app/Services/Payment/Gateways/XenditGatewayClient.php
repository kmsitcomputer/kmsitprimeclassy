<?php

namespace App\Services\Payment\Gateways;

use App\Contracts\Payment\PaymentGatewayClientInterface;
use App\DataTransferObjects\PaymentWebhookPayload;
use App\Enums\PaymentTransactionStatus;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\PaymentTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Xendit Invoices API (https://developers.xendit.co/api-reference/#invoices).
 * $config keys required: secret_key, callback_token.
 */
class XenditGatewayClient implements PaymentGatewayClientInterface
{
    private const BASE_URL = 'https://api.xendit.co';

    public function createPayment(Order $order, PaymentTransaction $transaction, array $config): array
    {
        $response = Http::withBasicAuth($config['secret_key'], '')
            ->timeout(10)
            ->post(self::BASE_URL.'/v2/invoices', [
                'external_id' => 'order-'.$order->id.'-'.$transaction->id,
                'amount' => (float) $transaction->amount,
                'currency' => 'IDR',
                'description' => 'Pembayaran order '.$order->order_no,
            ]);

        if (! $response->successful()) {
            throw new ApiException(__('messages.payment.gateway_request_failed', ['name' => 'Xendit']), 502);
        }

        return [
            'reference' => (string) $response->json('id'),
            'instructions' => [
                'invoice_url' => $response->json('invoice_url'),
                'expiry_date' => $response->json('expiry_date'),
            ],
        ];
    }

    public function verifyWebhookSignature(Request $request, array $config): bool
    {
        $token = $request->header('x-callback-token');

        return is_string($token) && hash_equals((string) ($config['callback_token'] ?? ''), $token);
    }

    public function parseWebhookPayload(Request $request): PaymentWebhookPayload
    {
        $payload = $request->json()->all();
        $status = strtoupper((string) ($payload['status'] ?? ''));
        $invoiceId = (string) ($payload['id'] ?? '');

        return new PaymentWebhookPayload(
            eventId: $invoiceId.':'.$status,
            reference: $invoiceId,
            status: match ($status) {
                'PAID', 'SETTLED' => PaymentTransactionStatus::PAID,
                'EXPIRED' => PaymentTransactionStatus::EXPIRED,
                'CANCELLED' => PaymentTransactionStatus::CANCELLED,
                default => PaymentTransactionStatus::PENDING,
            },
            amount: isset($payload['paid_amount']) ? (float) $payload['paid_amount'] : null,
            raw: $payload,
        );
    }
}
