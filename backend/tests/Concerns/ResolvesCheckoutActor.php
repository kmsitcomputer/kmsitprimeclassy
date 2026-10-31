<?php

namespace Tests\Concerns;

use App\Models\AgentPaymentGatewayConfig;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Owner rule (Improvement 04): a konsumen checking out for themselves may only
 * use full Bank Transfer — COD and DP are staff-only and a forged code is a 422.
 * Fixtures that need a konsumen's COD/DP order therefore have the buyer's
 * branch agen place it on their behalf (konsumen_id), exactly as staff do in
 * production. Bank Transfer orders and staff self-purchases keep the buyer as
 * the actor, so nothing else about the fixture changes. The buyer, stock
 * source (a konsumen always draws Agent stock) and fee chain are unaffected.
 */
trait ResolvesCheckoutActor
{
    /** @return array{0: User, 1: array<string, int>} [actor, extra order payload] */
    protected function checkoutActorFor(User $buyer, string $paymentMethodCode): array
    {
        if (! $buyer->isRole('konsumen') || $paymentMethodCode === 'bank_transfer') {
            return [$buyer, []];
        }

        return [User::query()->findOrFail($buyer->agent_id), ['konsumen_id' => $buyer->id]];
    }

    /**
     * A konsumen's own checkout (Bank Transfer) needs the branch's bank account, which
     * PaymentService::initiate snapshots onto the transaction.
     */
    protected function configureBankTransfer(int $agentId): void
    {
        $method = PaymentMethod::query()->where('code', 'bank_transfer')->firstOrFail();
        AgentPaymentGatewayConfig::updateOrCreate(
            ['agent_id' => $agentId, 'payment_method_id' => $method->id, 'environment' => 'sandbox'],
            ['config' => ['bank_name' => 'BCA', 'account_name' => 'QA', 'account_number' => '123']]
        );
    }

    /** POST /api/v1/orders for $buyer through the actor the payment method allows. */
    protected function postOrderAs(User $buyer, array $payload, ?string $idempotencyKey = null): TestResponse
    {
        [$actor, $onBehalf] = $this->checkoutActorFor($buyer, (string) ($payload['payment_method_code'] ?? ''));

        return $this->actingAs($actor)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey ?? (string) Str::uuid()])
            ->postJson('/api/v1/orders', $payload + $onBehalf);
    }
}
