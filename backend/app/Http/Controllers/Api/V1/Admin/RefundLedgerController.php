<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderSalesReduction;
use App\Models\RefundAttempt;
use App\Models\RefundObligation;
use App\Services\Refund\CancellationFinancialService;
use App\Services\Refund\RefundAuthorization;
use App\Services\Refund\RefundLedgerService;
use App\Services\Refund\RefundMetrics;
use Illuminate\Http\Request;

/**
 * The 360° refund ledger work area (design §7): obligation lifecycle, attempt lifecycle,
 * needs_verification exits, deferred materialization and reinstatement.
 *
 * Finance drives the money movement; the Owner (branch agen / super_admin) approves historical
 * changes, Exit B, reinstatement and deferred materialization. Branch scoping mirrors every other
 * admin financial endpoint — a refund object is only ever resolved through its order.
 */
class RefundLedgerController extends Controller
{
    public function __construct(
        private readonly RefundLedgerService $ledger,
        private readonly RefundAuthorization $authorization,
        private readonly RefundMetrics $metrics,
        private readonly CancellationFinancialService $cancellation,
    ) {}

    public function index(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);

        $obligations = RefundObligation::query()
            ->where('order_id', $order->id)
            ->with(['attempts' => fn ($q) => $q->latest('id'), 'payouts', 'children'])
            ->orderBy('id')
            ->get();

        $reductions = OrderSalesReduction::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get();

        return $this->ok([
            'order_id' => $order->id,
            'cancellation_financial_status' => $order->cancellation_financial_status,
            'metrics_cents' => $this->metrics->forOrder($order),
            'obligations' => $obligations,
            'reductions' => $reductions,
        ]);
    }

    public function approve(Request $request, RefundObligation $obligation)
    {
        $order = $this->orderFor($obligation);
        $this->authorizeOrder($request, $order);
        $this->authorization->assertOwner($request->user(), $order);

        return $this->ok($this->ledger->approveObligation($obligation, $request->user(), 'owner_manual_correction'));
    }

    public function storeAttempt(Request $request, RefundObligation $obligation)
    {
        $order = $this->orderFor($obligation);
        $this->authorizeOrder($request, $order);
        $this->authorization->assertFinance($request->user());

        $validated = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'in:bank_transfer,gateway,cash'],
            'external_reference' => ['nullable', 'string', 'max:191'],
            'destination_snapshot' => ['nullable', 'array'],
        ]);

        $attempt = $this->ledger->createAttempt($order, $obligation, [
            'amount_cents' => (int) $validated['amount_cents'],
            'method' => $validated['method'],
            'external_reference' => $validated['external_reference'] ?? null,
            'destination_snapshot' => $validated['destination_snapshot'] ?? null,
            'idempotency_key' => $this->idempotencyKey($request, 'attempt'),
        ], $request->user());

        return $this->created($attempt, 'Attempt dibuat.');
    }

    public function initiate(Request $request, RefundAttempt $attempt)
    {
        $this->authorizeAttempt($request, $attempt);

        $validated = $request->validate(['external_reference' => ['nullable', 'string', 'max:191']]);

        return $this->ok($this->ledger->recordInitiation($attempt, $request->user(), $validated['external_reference'] ?? null));
    }

    public function complete(Request $request, RefundAttempt $attempt)
    {
        $this->authorizeAttempt($request, $attempt);

        $validated = $request->validate([
            'proof_media_id' => ['nullable', 'integer', 'exists:media,id'],
            'proof_type' => ['nullable', 'string', 'max:60'],
            'gateway_reference' => ['nullable', 'string', 'max:191'],
        ]);

        $payout = $this->ledger->completeAttempt(
            $attempt,
            $request->user(),
            isset($validated['proof_media_id']) ? (int) $validated['proof_media_id'] : null,
            $validated['proof_type'] ?? null,
            $validated['gateway_reference'] ?? null,
        );

        return $this->ok($payout, 'Payout tercatat.');
    }

    public function cancel(Request $request, RefundAttempt $attempt)
    {
        $this->authorizeAttempt($request, $attempt);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->ok($this->ledger->cancelAttempt($attempt, $request->user(), $validated['reason']));
    }

    public function markFailed(Request $request, RefundAttempt $attempt)
    {
        $this->authorizeAttempt($request, $attempt);
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:60'],
            'message' => ['required', 'string', 'max:255'],
        ]);

        return $this->ok($this->ledger->markFailed($attempt, $request->user(), $validated['code'], $validated['message']));
    }

    public function unknown(Request $request, RefundAttempt $attempt)
    {
        $this->authorizeAttempt($request, $attempt);
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        return $this->ok($this->ledger->markUnknown($attempt, $request->user(), $validated['note'] ?? null));
    }

    public function reconcile(Request $request, RefundAttempt $attempt)
    {
        $this->authorizeAttempt($request, $attempt);
        $validated = $request->validate([
            'succeeded' => ['required', 'boolean'],
            'evidence' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        return $this->ok($this->ledger->reconcileUnknown(
            $attempt,
            $request->user(),
            (bool) $validated['succeeded'],
            $validated['evidence'],
        ));
    }

    public function resolveVerification(Request $request, RefundObligation $obligation)
    {
        $order = $this->orderFor($obligation);
        $this->authorizeOrder($request, $order);

        $validated = $request->validate([
            'exit' => ['required', 'in:A,B'],
            'evidence' => ['required', 'string', 'min:3', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->ok($this->ledger->resolveNeedsVerification(
            $obligation,
            $request->user(),
            $validated['exit'],
            $validated['evidence'],
            $validated['reason'] ?? null,
        ));
    }

    public function requestMaterialization(Request $request, RefundObligation $obligation)
    {
        $order = $this->orderFor($obligation);
        $this->authorizeOrder($request, $order);

        $validated = $request->validate(['amount_cents' => ['required', 'integer', 'min:1']]);

        $this->ledger->requestDeferredMaterialization(
            $obligation,
            (int) $validated['amount_cents'],
            $request->user(),
            $this->idempotencyKey($request, 'deferred-request'),
        );

        return $this->ok(null, 'Permintaan materialisasi dicatat.');
    }

    public function materialize(Request $request, RefundObligation $obligation)
    {
        $order = $this->orderFor($obligation);
        $this->authorizeOrder($request, $order);

        $validated = $request->validate(['amount_cents' => ['required', 'integer', 'min:1']]);

        $child = $this->ledger->materializeDeferred(
            $obligation,
            (int) $validated['amount_cents'],
            $request->user(),
            $this->idempotencyKey($request, 'deferred-materialize'),
        );

        return $this->created($child, 'Deferred dimaterialisasi.');
    }

    public function reinstate(Request $request, OrderSalesReduction $reduction)
    {
        $order = Order::query()->whereKey($reduction->order_id)->firstOrFail();
        $this->authorizeOrder($request, $order);

        $validated = $request->validate([
            'evidence' => ['required', 'string', 'min:3', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->ok($this->ledger->reinstateReduction(
            $reduction,
            $request->user(),
            $validated['evidence'],
            $validated['reason'] ?? null,
        ));
    }

    public function finalizeCancellation(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);
        $this->authorization->assertFinance($request->user());

        return $this->ok($this->cancellation->finalize($order, $request->user()));
    }

    private function orderFor(RefundObligation $obligation): Order
    {
        return Order::query()->whereKey($obligation->order_id)->firstOrFail();
    }

    private function authorizeAttempt(Request $request, RefundAttempt $attempt): void
    {
        $obligation = RefundObligation::query()->whereKey($attempt->obligation_id)->firstOrFail();
        $order = $this->orderFor($obligation);
        $this->authorizeOrder($request, $order);
        $this->authorization->assertFinance($request->user());
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        $actor = $request->user();

        if (! $actor->isRole('super_admin') && (int) $actor->agent_id !== (int) $order->agent_id) {
            throw new ApiException(__('messages.system.unauthorized_action'), 404);
        }

        if (! $actor->isRole('super_admin', 'agen', 'keuangan', 'admin')) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }
    }

    private function idempotencyKey(Request $request, string $prefix): string
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '') {
            throw new ApiException('Header Idempotency-Key wajib untuk aksi ini.', 422, [
                'code' => 'idempotency_key_required',
            ]);
        }

        return $prefix.':'.$key;
    }
}
