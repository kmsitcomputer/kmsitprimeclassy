<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSalesReduction;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\RefundAttempt;
use App\Models\RefundLedgerEvent;
use App\Models\RefundObligation;
use App\Models\RefundPayout;
use App\Models\ShippingConfiguration;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\Refund\OrderFinancialSnapshotService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * Cross-tenant (cross-Agent) IDOR matrix for the refund ledger HTTP surface.
 *
 * Tenant B resources are resolved through their order, and Order carries the global Agent scope
 * plus the controller's explicit same-Agent check. A tenant-A actor must receive 404 (not a 403
 * that confirms existence) and must leave B's financial state untouched.
 */
class RefundLedgerIdorTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_tenant_a_cannot_read_tenant_b_refund_work_area(): void
    {
        $a = $this->branch('IDOR A');
        $b = $this->branch('IDOR B');
        $bResources = $this->seedTenantB($b);

        foreach ([$a['keuangan'], $a['agen'], $a['admin']] as $actor) {
            $response = $this->actingAs($actor)
                ->getJson("/api/v1/admin/orders/{$b['order']->id}/refunds")
                ->assertStatus(404);

            $response->assertJsonMissingPath('data.obligations');
            $this->assertStringNotContainsString($bResources['obligation']->obligation_no, $response->getContent());
        }

        // Tightening the read did not mutate or remove B's evidence.
        $this->assertSame(3, RefundObligation::query()->where('order_id', $b['order']->id)->count());
        $this->assertSame(1, RefundAttempt::query()->where('obligation_id', $bResources['obligation']->id)->count());
        $this->assertSame(1, RefundPayout::query()->where('order_id', $b['order']->id)->count());
        $this->assertSame(1, OrderSalesReduction::query()->where('order_id', $b['order']->id)->count());
        $this->assertSame(2, RefundLedgerEvent::query()->where('order_id', $b['order']->id)->count());
    }

    public function test_tenant_a_cannot_mutate_any_tenant_b_refund_resource(): void
    {
        $a = $this->branch('IDOR MUT A');
        $b = $this->branch('IDOR MUT B');
        $r = $this->seedTenantB($b);

        $actor = $a['keuangan'];
        $before = $this->tenantSnapshot($b);

        $calls = [
            ['post', "/api/v1/admin/refund-obligations/{$r['obligation']->id}/approve", []],
            ['post', "/api/v1/admin/refund-obligations/{$r['obligation']->id}/attempts", ['amount_cents' => 1000, 'method' => 'cash']],
            ['post', "/api/v1/admin/refund-obligations/{$r['needsVerification']->id}/needs-verification/resolve", ['exit' => 'B', 'evidence' => 'forged']],
            ['post', "/api/v1/admin/refund-obligations/{$r['deferredOrigin']->id}/deferred/request", ['amount_cents' => 1000]],
            ['post', "/api/v1/admin/refund-obligations/{$r['deferredOrigin']->id}/deferred/materialize", ['amount_cents' => 1000]],
            ['post', "/api/v1/admin/refund-attempts/{$r['attempt']->id}/initiate", []],
            ['post', "/api/v1/admin/refund-attempts/{$r['attempt']->id}/complete", []],
            ['post', "/api/v1/admin/refund-attempts/{$r['attempt']->id}/cancel", ['reason' => 'forged']],
            ['post', "/api/v1/admin/refund-attempts/{$r['attempt']->id}/fail", ['code' => 'x', 'message' => 'y']],
            ['post', "/api/v1/admin/refund-attempts/{$r['attempt']->id}/unknown", []],
            ['post', "/api/v1/admin/refund-attempts/{$r['attempt']->id}/reconcile", ['succeeded' => true, 'evidence' => 'forged']],
            ['post', "/api/v1/admin/order-sales-reductions/{$r['reduction']->id}/reinstate", ['evidence' => 'forged']],
            ['post', "/api/v1/admin/orders/{$b['order']->id}/cancellation/finalize", []],
        ];

        foreach ($calls as [$method, $path, $payload]) {
            $response = $this->actingAs($actor)
                ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
                ->{$method.'Json'}($path, $payload);

            $response->assertStatus(404);
        }

        // Owner-only route: a same-branch actor would get 403, but a cross-tenant actor must
        // get the same non-confirming 404 because the order is outside its scope.
        $this->actingAs($a['agen'])
            ->postJson("/api/v1/admin/refund-obligations/{$r['needsVerification']->id}/needs-verification/resolve", [
                'exit' => 'B', 'evidence' => 'forged',
            ])->assertStatus(404);

        $this->assertSame($before, $this->tenantSnapshot($b));
    }

    public function test_role_matrix_and_authorized_branch_operations(): void
    {
        $a = $this->branch('ROLE A');
        $b = $this->branch('ROLE B');
        $r = $this->seedTenantB($b);

        // Roles outside the refund route surface are denied by the route middleware.
        foreach ([$a['konsumen'], $a['kurir']] as $actor) {
            $this->actingAs($actor)
                ->getJson("/api/v1/admin/orders/{$a['order']->id}/refunds")
                ->assertStatus(403);
        }

        // Admin has read-only oversight in its own branch.
        $this->actingAs($a['admin'])
            ->getJson("/api/v1/admin/orders/{$a['order']->id}/refunds")
            ->assertOk();

        $aObligation = RefundObligation::create([
            'obligation_no' => 'RO-'.Str::ulid(),
            'order_id' => $a['order']->id,
            'source_type' => 'return_stage',
            'source_id' => $a['item']->id,
            'obligation_key' => 'role-a-obligation',
            'amount' => 10000,
            'status' => 'approved',
            'provenance' => 'forward',
            'approved_at' => now(),
            'deferred_amount' => '0.00',
            'deferred_remaining' => '0.00',
        ]);

        // Admin cannot move money even in its own branch.
        $this->actingAs($a['admin'])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/admin/refund-obligations/{$aObligation->id}/attempts", [
                'amount_cents' => 1000, 'method' => 'cash',
            ])->assertStatus(403);

        // Admin is denied by the route middleware before any target is resolved.
        $this->actingAs($a['admin'])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/admin/refund-obligations/{$r['obligation']->id}/attempts", [
                'amount_cents' => 1000, 'method' => 'cash',
            ])->assertStatus(403);

        // Same-branch Finance is allowed: proves the matrix is not a blanket denial.
        $attemptId = $this->actingAs($b['keuangan'])
            ->withHeaders(['Idempotency-Key' => 'idor-positive-'.Str::uuid()])
            ->postJson("/api/v1/admin/refund-obligations/{$r['obligation']->id}/attempts", [
                'amount_cents' => 1000, 'method' => 'cash',
            ])->assertCreated()->json('data.id');

        $this->assertNotNull($attemptId);
        $this->assertSame(2, RefundAttempt::query()->where('obligation_id', $r['obligation']->id)->count());
    }

    public function test_refund_reporting_is_tenant_scoped(): void
    {
        $a = $this->branch('REPORT A');
        $b = $this->branch('REPORT B');
        $r = $this->seedTenantB($b);

        // A proven payout on B is a legitimate R_c for B, never for A.
        $r['payout']->update(['verification_status' => 'proven']);
        config(['refunds.reporting_source' => 'ledger']);

        $scopedA = $this->actingAs($a['keuangan'])
            ->getJson('/api/v1/reports/finance-summary')
            ->assertOk()
            ->json('data.total_refunds');

        $scopedB = $this->actingAs($b['keuangan'])
            ->getJson('/api/v1/reports/finance-summary')
            ->assertOk()
            ->json('data.total_refunds');

        $this->assertSame(0.0, (float) $scopedA);
        $this->assertSame((float) $r['payout']->amount, (float) $scopedB);
    }

    private array $branchCache = [];

    /** @return array{agen:User,admin:User,keuangan:User,konsumen:User,kurir:User,product:Product,order:Order,item:OrderItem} */
    public function test_colliding_idempotency_key_never_returns_another_agents_attempt(): void
    {
        $a = $this->branch('RF01 KEY A');
        $b = $this->branch('RF01 KEY B');
        $aObligation = $this->approvedObligationFor($a, 'rf01-key-a', 50000);
        $bObligation = $this->approvedObligationFor($b, 'rf01-key-b', 50000);

        $sharedKey = 'rf01-shared-'.Str::uuid();

        // Tenant B creates its attempt first, carrying tenant-sensitive payload.
        $bAttemptId = $this->actingAs($b['keuangan'])
            ->withHeaders(['Idempotency-Key' => $sharedKey])
            ->postJson("/api/v1/admin/refund-obligations/{$bObligation->id}/attempts", [
                'amount_cents' => 25000,
                'method' => 'bank_transfer',
                'external_reference' => 'B-SECRET-REF',
                'destination_snapshot' => ['account_no' => 'B-SECRET-ACCOUNT'],
            ])->assertCreated()->json('data.id');

        // Tenant A reuses the same client key on its OWN obligation: safe conflict, no B payload.
        $response = $this->actingAs($a['keuangan'])
            ->withHeaders(['Idempotency-Key' => $sharedKey])
            ->postJson("/api/v1/admin/refund-obligations/{$aObligation->id}/attempts", [
                'amount_cents' => 25000,
                'method' => 'bank_transfer',
            ]);

        $response->assertStatus(409)->assertJsonPath('errors.code', 'idempotency_key_conflict');
        $this->assertNull($response->json('data'), 'A conflict response must not carry a refund payload.');

        $body = $response->getContent();
        $this->assertStringNotContainsString('B-SECRET', $body);
        $this->assertStringNotContainsString($bObligation->obligation_no, $body);
        $this->assertStringNotContainsString('destination_snapshot', $body);

        // No write crossed tenants: A has nothing, B keeps exactly its one attempt.
        $this->assertSame(0, RefundAttempt::query()->where('obligation_id', $aObligation->id)->count());
        $this->assertSame(1, RefundAttempt::query()->where('obligation_id', $bObligation->id)->count());
        $this->assertSame(
            $bAttemptId,
            (int) RefundAttempt::query()->where('obligation_id', $bObligation->id)->value('id'),
        );

        // The engine still works for A with its own key (the conflict did not poison the obligation).
        $this->actingAs($a['keuangan'])
            ->withHeaders(['Idempotency-Key' => 'rf01-a-own-'.Str::uuid()])
            ->postJson("/api/v1/admin/refund-obligations/{$aObligation->id}/attempts", [
                'amount_cents' => 25000,
                'method' => 'bank_transfer',
            ])->assertCreated();
    }

    public function test_attempt_replay_is_idempotent_only_for_the_same_obligation_and_payload(): void
    {
        $a = $this->branch('RF01 REPLAY A');
        $obligation = $this->approvedObligationFor($a, 'rf01-replay-ob', 50000);
        $otherObligation = $this->approvedObligationFor($a, 'rf01-replay-ob2', 50000);

        $key = 'rf01-replay-'.Str::uuid();
        $url = "/api/v1/admin/refund-obligations/{$obligation->id}/attempts";
        $payload = ['amount_cents' => 20000, 'method' => 'cash'];

        $firstId = $this->actingAs($a['keuangan'])->withHeaders(['Idempotency-Key' => $key])
            ->postJson($url, $payload)->assertCreated()->json('data.id');

        // Identical retry -> the same attempt, still exactly one row.
        $secondId = $this->actingAs($a['keuangan'])->withHeaders(['Idempotency-Key' => $key])
            ->postJson($url, $payload)->assertCreated()->json('data.id');

        $this->assertSame($firstId, $secondId);
        $this->assertSame(1, RefundAttempt::query()->where('obligation_id', $obligation->id)->count());

        // Same key with a different amount is NOT a replay.
        $this->actingAs($a['keuangan'])->withHeaders(['Idempotency-Key' => $key])
            ->postJson($url, ['amount_cents' => 21000, 'method' => 'cash'])
            ->assertStatus(409)
            ->assertJsonPath('errors.code', 'idempotency_key_conflict');

        // Same key with a different method is NOT a replay either.
        $this->actingAs($a['keuangan'])->withHeaders(['Idempotency-Key' => $key])
            ->postJson($url, ['amount_cents' => 20000, 'method' => 'bank_transfer'])
            ->assertStatus(409)
            ->assertJsonPath('errors.code', 'idempotency_key_conflict');

        // Same agent, DIFFERENT obligation, same key -> conflict, not a borrowed attempt.
        $this->actingAs($a['keuangan'])->withHeaders(['Idempotency-Key' => $key])
            ->postJson("/api/v1/admin/refund-obligations/{$otherObligation->id}/attempts", $payload)
            ->assertStatus(409)
            ->assertJsonPath('errors.code', 'idempotency_key_conflict');

        $this->assertSame(1, RefundAttempt::query()->where('obligation_id', $obligation->id)->count());
        $this->assertSame(0, RefundAttempt::query()->where('obligation_id', $otherObligation->id)->count());
    }

    private function approvedObligationFor(array $branch, string $key, int $amount): RefundObligation
    {
        $this->actingAs($branch['admin']);

        $snapshot = app(OrderFinancialSnapshotService::class)
            ->ensure($branch['order']->fresh(), OrderFinancialSnapshotService::TRIGGER_RETURN_APPROVAL, $branch['admin']);

        return RefundObligation::create([
            'obligation_no' => 'RO-'.Str::ulid(),
            'order_id' => $branch['order']->id,
            'source_type' => 'fulfillment_shortfall',
            'source_id' => $branch['item']->id,
            'obligation_key' => $key,
            'amount' => $amount,
            'status' => 'approved',
            'provenance' => 'forward',
            'snapshot_id' => $snapshot->id,
            'approved_at' => now(),
            'approval_source' => 'manual',
            'deferred_amount' => '0.00',
            'deferred_remaining' => '0.00',
        ]);
    }

    private function branch(string $name): array
    {
        if (isset($this->branchCache[$name])) {
            return $this->branchCache[$name];
        }

        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => $name, 'address' => 'Test', 'latitude' => -6.2, 'longitude' => 106.8]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        $kurir = User::factory()->kurir()->create(['agent_id' => $agen->id]);
        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 0, 'free_shipping_enabled' => true, 'is_active' => true]);

        $product = Product::create([
            'sku' => 'ID-'.Str::uuid(), 'name' => $name, 'slug' => 'idor-'.Str::lower(Str::random(10)),
            'has_variations' => false, 'base_price' => 100000, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 10]);

        $orderId = $this->actingAs($agen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
            'konsumen_id' => $konsumen->id,
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $product->id, 'quantity' => 3]],
                'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated()->json('data.id');

        $order = Order::withoutGlobalScopes()->findOrFail($orderId);
        $item = OrderItem::query()->where('order_id', $orderId)->firstOrFail();

        return $this->branchCache[$name] = compact('agen', 'admin', 'keuangan', 'konsumen', 'kurir', 'product', 'order', 'item');
    }

    /** @return array{obligation:RefundObligation,needsVerification:RefundObligation,deferredOrigin:RefundObligation,attempt:RefundAttempt,payout:RefundPayout,reduction:OrderSalesReduction} */
    private function seedTenantB(array $b): array
    {
        $this->actingAs($b['admin']);

        $snapshot = app(OrderFinancialSnapshotService::class)
            ->ensure($b['order']->fresh(), OrderFinancialSnapshotService::TRIGGER_RETURN_APPROVAL, $b['admin']);

        $obligation = RefundObligation::create([
            'obligation_no' => 'RO-'.Str::ulid(),
            'order_id' => $b['order']->id,
            'source_type' => 'fulfillment_shortfall',
            'source_id' => $b['item']->id,
            'obligation_key' => 'idor-b-obligation',
            'amount' => 50000,
            'status' => 'approved',
            'provenance' => 'forward',
            'snapshot_id' => $snapshot->id,
            'approved_at' => now(),
            'approval_source' => 'manual',
            'deferred_amount' => '0.00',
            'deferred_remaining' => '0.00',
        ]);

        $needsVerification = RefundObligation::create([
            'obligation_no' => 'RO-'.Str::ulid(),
            'order_id' => $b['order']->id,
            'source_type' => 'legacy',
            'source_id' => 99,
            'obligation_key' => 'idor-b-needs-verification',
            'amount' => 10000,
            'status' => 'needs_verification',
            'provenance' => 'legacy',
            'snapshot_id' => $snapshot->id,
            'deferred_amount' => '0.00',
            'deferred_remaining' => '0.00',
        ]);

        $deferredOrigin = RefundObligation::create([
            'obligation_no' => 'RO-'.Str::ulid(),
            'order_id' => $b['order']->id,
            'source_type' => 'fulfillment_shortfall',
            'source_id' => $b['item']->id,
            'obligation_key' => 'idor-b-deferred',
            'amount' => 0,
            'status' => 'approved',
            'provenance' => 'forward',
            'snapshot_id' => $snapshot->id,
            'approved_at' => now(),
            'deferred_amount' => 20000,
            'deferred_remaining' => 20000,
        ]);

        $attempt = RefundAttempt::create([
            'obligation_id' => $obligation->id,
            'attempt_no' => 1,
            'method' => 'cash',
            'amount' => 1000,
            'status' => 'created',
            'idempotency_key' => 'idor-b-attempt-1',
        ]);

        $payout = RefundPayout::create([
            'payout_no' => 'RP-'.Str::ulid(),
            'obligation_id' => $obligation->id,
            'order_id' => $b['order']->id,
            'attempt_id' => $attempt->id,
            'payout_key' => 'idor-b-attempt-1',
            'amount' => 1000,
            'method' => 'cash',
            'paid_at' => now(),
            'verification_status' => 'legacy_unverified',
            'affects_paid_amount' => true,
            'paid_effect_applied_at' => now(),
        ]);

        $reduction = OrderSalesReduction::create([
            'order_id' => $b['order']->id,
            'order_item_id' => $b['item']->id,
            'source_type' => 'return_stage',
            'source_id' => $b['item']->id,
            'amount' => 10000,
            'applied_to_order_total' => false,
            'provenance' => 'forward',
        ]);

        app(\App\Services\Refund\RefundAudit::class)->record(
            'idor.b.seed',
            $b['order'],
            obligationId: $obligation->id,
            eventKey: 'idor-b-event-1',
            actor: $b['admin'],
        );

        return compact('obligation', 'needsVerification', 'deferredOrigin', 'attempt', 'payout', 'reduction');
    }

    /** @return array<string, mixed> */
    private function tenantSnapshot(array $b): array
    {
        $order = Order::withoutGlobalScopes()->findOrFail($b['order']->id);

        return [
            'order_status' => $order->status,
            'cancellation_financial_status' => $order->cancellation_financial_status,
            'paid_amount' => (string) $order->paid_amount,
            'obligations' => RefundObligation::query()->where('order_id', $order->id)->orderBy('id')->get()->map(fn ($o) => [
                $o->id, $o->status, (string) $o->amount, (string) $o->deferred_remaining, $o->cancel_reason,
            ])->all(),
            'attempts' => RefundAttempt::query()->whereIn('obligation_id', RefundObligation::query()->where('order_id', $order->id)->select('id'))->orderBy('id')->get()->map(fn ($a) => [
                $a->id, $a->status, (string) $a->amount,
            ])->all(),
            'payouts' => RefundPayout::query()->where('order_id', $order->id)->orderBy('id')->get()->map(fn ($p) => [
                $p->id, (string) $p->amount, $p->verification_status,
            ])->all(),
            'reductions' => OrderSalesReduction::query()->where('order_id', $order->id)->orderBy('id')->get()->map(fn ($red) => [
                $red->id, (string) $red->amount, $red->reinstated_at?->toDateTimeString(),
            ])->all(),
            'events' => RefundLedgerEvent::query()->where('order_id', $order->id)->orderBy('id')->pluck('event_type')->all(),
        ];
    }
}
