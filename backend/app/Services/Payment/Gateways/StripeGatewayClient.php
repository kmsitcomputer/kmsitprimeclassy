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
 * Stripe PaymentIntents API (https://stripe.com/docs/api/payment_intents).
 * $config keys required: secret_key, webhook_secret.
 */
class StripeGatewayClient implements PaymentGatewayClientInterface
{
    private const BASE_URL = 'https://api.stripe.com/v1';

    /** Stripe replay-protection tolerance for the webhook signature's timestamp, in seconds. */
    private const SIGNATURE_TOLERANCE_SECONDS = 300;

    public function createPayment(Order $order, PaymentTransaction $transaction, array $config): array
    {
        $response = Http::withToken($config['secret_key'])
            ->asForm()
            ->timeout(10)
            ->post(self::BASE_URL.'/payment_intents', [
                // IDR is a zero-decimal currency for Stripe — no cent multiplication.
                'amount' => (int) round((float) $transaction->amount),
                'currency' => 'idr',
                'description' => 'Pembayaran order '.$order->order_no,
                'metadata' => ['order_id' => $order->id, 'transaction_id' => $transaction->id],
            ]);

        if (! $response->successful()) {
            throw new ApiException(__('messages.payment.gateway_request_failed', ['name' => 'Stripe']), 502);
        }

        return [
            'reference' => (string) $response->json('id'),
            'instructions' => [
                'client_secret' => $response->json('client_secret'),
            ],
        ];
    }

    public function verifyWebhookSignature(Request $request, array $config): bool
    {
        $header = $request->header('Stripe-Signature');
        $secret = (string) ($config['webhook_secret'] ?? '');

        if (! is_string($header) || $secret === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
            $parts[$key][] = $value;
        }

        $timestamp = $parts['t'][0] ?? null;
        $signatures = $parts['v1'] ?? [];

        if (! $timestamp || empty($signatures)) {
            return false;
        }

        // Replay-safety: reject a signature for a request older than the tolerance
        // window, even if the HMAC itself is mathematically valid.
        if (abs(time() - (int) $timestamp) > self::SIGNATURE_TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        foreach ($signatures as $signature) {
            if (is_string($signature) && hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public function parseWebhookPayload(Request $request): PaymentWebhookPayload
    {
        $payload = $request->json()->all();
        $type = (string) ($payload['type'] ?? '');
        $object = $payload['data']['object'] ?? [];

        return new PaymentWebhookPayload(
            eventId: (string) ($payload['id'] ?? ''),
            reference: (string) ($object['id'] ?? ''),
            status: match ($type) {
                'payment_intent.succeeded' => PaymentTransactionStatus::PAID,
                'payment_intent.payment_failed' => PaymentTransactionStatus::FAILED,
                'payment_intent.canceled' => PaymentTransactionStatus::CANCELLED,
                'charge.refunded' => PaymentTransactionStatus::REFUNDED,
                default => PaymentTransactionStatus::PENDING,
            },
            amount: isset($object['amount']) ? (float) $object['amount'] : null,
            raw: $payload,
        );
    }
}
