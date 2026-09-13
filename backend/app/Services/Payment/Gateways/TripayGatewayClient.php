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
 * Tripay closed-payment API (https://tripay.co.id/developer). $config keys
 * required: merchant_code, private_key, api_key, environment ('sandbox' or
 * 'production' — Tripay uses a different base URL per mode, not just
 * different keys).
 */
class TripayGatewayClient implements PaymentGatewayClientInterface
{
    public function createPayment(Order $order, PaymentTransaction $transaction, array $config): array
    {
        $merchantRef = 'order-'.$order->id.'-'.$transaction->id;
        $amount = (int) round((float) $transaction->amount);
        $signature = hash_hmac('sha256', $config['merchant_code'].$merchantRef.$amount, $config['private_key']);

        $response = Http::withToken($config['api_key'])
            ->timeout(10)
            ->post($this->baseUrl($config).'/transaction/create', [
                'method' => 'QRIS',
                'merchant_ref' => $merchantRef,
                'amount' => $amount,
                'customer_name' => $order->recipient_name_snapshot,
                'customer_email' => 'no-reply@primeclassy.test',
                'order_items' => [['sku' => 'ORDER', 'name' => 'Order '.$order->order_no, 'price' => $amount, 'quantity' => 1]],
                'signature' => $signature,
            ]);

        if (! $response->successful()) {
            throw new ApiException(__('messages.payment.gateway_request_failed', ['name' => 'Tripay']), 502);
        }

        return [
            'reference' => (string) $response->json('data.reference'),
            'instructions' => [
                'checkout_url' => $response->json('data.checkout_url'),
                'qr_string' => $response->json('data.qr_string'),
                'pay_code' => $response->json('data.pay_code'),
            ],
        ];
    }

    public function verifyWebhookSignature(Request $request, array $config): bool
    {
        $signature = $request->header('X-Callback-Signature');
        $expected = hash_hmac('sha256', $request->getContent(), (string) ($config['private_key'] ?? ''));

        return is_string($signature) && hash_equals($expected, $signature);
    }

    public function parseWebhookPayload(Request $request): PaymentWebhookPayload
    {
        $payload = $request->json()->all();
        $status = strtoupper((string) ($payload['status'] ?? ''));
        $reference = (string) ($payload['reference'] ?? '');

        return new PaymentWebhookPayload(
            eventId: $reference.':'.$status,
            reference: $reference,
            status: match ($status) {
                'PAID' => PaymentTransactionStatus::PAID,
                'EXPIRED' => PaymentTransactionStatus::EXPIRED,
                'FAILED' => PaymentTransactionStatus::FAILED,
                'REFUND' => PaymentTransactionStatus::REFUNDED,
                default => PaymentTransactionStatus::PENDING,
            },
            amount: isset($payload['total_amount']) ? (float) $payload['total_amount'] : null,
            raw: $payload,
        );
    }

    private function baseUrl(array $config): string
    {
        return ($config['environment'] ?? 'sandbox') === 'production'
            ? 'https://tripay.co.id/api'
            : 'https://tripay.co.id/api-sandbox';
    }
}
