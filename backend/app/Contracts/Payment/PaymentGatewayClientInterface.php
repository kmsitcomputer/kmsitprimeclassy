<?php

namespace App\Contracts\Payment;

use App\DataTransferObjects\PaymentWebhookPayload;
use App\Models\Order;
use App\Models\PaymentTransaction;
use Illuminate\Http\Request;

/**
 * The one contract Xendit/Tripay/Stripe clients must satisfy so
 * PaymentService never branches on which gateway it's talking to. $config is
 * always the plaintext, already-decrypted array from the owning agen's
 * active AgentPaymentGatewayConfig row for their own chosen environment —
 * these classes never touch the database or the `config` encrypted cast
 * themselves.
 */
interface PaymentGatewayClientInterface
{
    /**
     * Starts a payment with the provider for this transaction. Returns
     * whatever the confirmation/instruction step needs to show the
     * customer — e.g. a VA number, a QR string, a redirect URL — plus the
     * provider's own reference id for this payment.
     *
     * @return array{reference: string, instructions: array<string, mixed>}
     */
    public function createPayment(Order $order, PaymentTransaction $transaction, array $config): array;

    /**
     * Cryptographic proof this request actually came from the provider (a
     * shared callback token, an HMAC signature header) — checked BEFORE the
     * payload is parsed or trusted in any way. A webhook that fails this is
     * logged but never allowed to change a transaction's status.
     */
    public function verifyWebhookSignature(Request $request, array $config): bool;

    public function parseWebhookPayload(Request $request): PaymentWebhookPayload;
}
