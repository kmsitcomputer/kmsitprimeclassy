<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderFinancialSnapshot;
use App\Models\OrderItem;
use App\Models\OrderSalesReduction;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\RefundAttempt;
use App\Models\RefundObligation;
use App\Models\RefundPayout;
use App\Models\ShippingConfiguration;
use App\Models\User;
use App\Models\Voucher;
use App\Models\WarehouseStock;
use App\Services\Order\OrderFulfillmentService;
use App\Services\Order\ReturnService;
use App\Services\Refund\CancellationFinancialService;
use App\Services\Refund\RefundLedgerService;
use App\Services\Refund\RefundMetrics;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * Controlled DEV UAT for the authorized manual/cash Refund Ledger lifecycle, executed through the
 * real HTTP surface and the real services against the isolated test database.
 */
class RefundLedgerUatTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_uat_return_approval_snapshot_manual_cash_payout_and_reporting(): void
    {
        $f = $this->order(withVoucher: 30000);
        $item = $f['item'];
        $order = $f['order'];

        $order->update([
            'payment_status' => 'paid',
            'paid_amount' => $order->total_amount,
            'gross_received_amount' => $order->total_amount,
            'remaining_amount' => 0,
        ]);

        $this->putItemDelivered($order, $item);

        $return = app(ReturnService::class)->requestReturn(
            $order->fresh(),
            $f['konsumen'],
            [['order_item_id' => $item->id, 'quantity' => 1, 'restock' => false]],
            'UAT customer return',
            null,
        );

        app(ReturnService::class)->review($return, $f['admin'], true, 'UAT approve');

        $snapshot = OrderFinancialSnapshot::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame('return_approval', $snapshot->trigger);
        $this->assertSame(3, $snapshot->units()->count());
        $this->assertSame(3000000, (int) round((float) $snapshot->units()->sum('allocated_discount') * 100));

        $obligation = RefundObligation::query()
            ->where('order_id', $order->id)
            ->where('source_type', 'return_stage')
            ->firstOrFail();
        $this->assertSame('approved', $obligation->status);
        $this->assertSame(9000000, (int) round((float) $obligation->amount * 100));
        $this->assertSame(1, OrderSalesReduction::query()->where('order_id', $order->id)->count());

        // Finance executes through the HTTP work area with an idempotency key.
        $attemptId = $this->actingAs($f['keuangan'])
            ->withHeaders(['Idempotency-Key' => 'uat-return-attempt-1'])
            ->postJson("/api/v1/admin/refund-obligations/{$obligation->id}/attempts", [
                'amount_cents' => 9000000,
                'method' => 'cash',
            ])->assertCreated()->json('data.id');

        $this->actingAs($f['keuangan'])
            ->withHeaders(['Idempotency-Key' => 'uat-return-attempt-1'])
            ->postJson("/api/v1/admin/refund-obligations/{$obligation->id}/attempts", [
                'amount_cents' => 9000000,
                'method' => 'cash',
            ])->assertCreated()->assertJsonPath('data.id', $attemptId);

        $this->assertSame(1, RefundAttempt::query()->where('obligation_id', $obligation->id)->count());

        $this->actingAs($f['keuangan'])->postJson("/api/v1/admin/refund-attempts/{$attemptId}/initiate")->assertOk();
        $this->actingAs($f['keuangan'])->postJson("/api/v1/admin/refund-attempts/{$attemptId}/complete", [
            'proof_type' => 'cash_receipt',
        ])->assertOk();

        $payout = RefundPayout::query()->where('obligation_id', $obligation->id)->firstOrFail();
        $this->assertSame('proven', $payout->verification_status);
        $this->assertTrue((bool) $payout->affects_paid_amount);
        $this->assertSame(9000000, (int) round((float) $payout->amount * 100));
        $this->assertSame('completed', $obligation->fresh()->status);

        $metrics = app(RefundMetrics::class)->forOrder($order->fresh());
        $this->assertSame(18000000, $metrics['paid_cents']);
        $this->assertSame(9000000, $metrics['proven_cents']);
        $this->assertSame(0, $metrics['available_cents']);

        config(['refunds.reporting_source' => 'ledger']);
        $this->actingAs($f['keuangan'])
            ->getJson('/api/v1/reports/finance-summary')
            ->assertOk()
            ->assertJsonPath('data.total_refunds', 90000);
    }

    public function test_uat_return_rejection_creates_no_financial_entitlement(): void
    {
        $f = $this->order();
        $this->putItemDelivered($f['order'], $f['item']);

        $return = app(ReturnService::class)->requestReturn(
            $f['order']->fresh(),
            $f['konsumen'],
            [['order_item_id' => $f['item']->id, 'quantity' => 1, 'restock' => false]],
            'UAT rejected return',
            null,
        );

        app(ReturnService::class)->review($return, $f['admin'], false, 'UAT reject');

        $this->assertSame('rejected', $return->fresh()->status);
        $this->assertSame('terkirim', $f['item']->fresh()->status);
        $this->assertSame(0, (int) $f['item']->fresh()->returned_quantity);
        $this->assertSame(0, OrderFinancialSnapshot::query()->where('order_id', $f['order']->id)->count());
        $this->assertSame(0, RefundObligation::query()->where('order_id', $f['order']->id)->count());
        $this->assertSame(0, OrderSalesReduction::query()->where('order_id', $f['order']->id)->count());
    }

    public function test_uat_failed_and_unknown_attempts_then_cancellation_and_reconciliation(): void
    {
        $f = $this->order();
        $order = $f['order'];
        $order->update([
            'payment_status' => 'paid',
            'paid_amount' => 300000,
            'gross_received_amount' => 300000,
            'remaining_amount' => 0,
        ]);

        app(OrderFulfillmentService::class)->adjustItemQuantity($f['item']->fresh(), 2, $f['admin'], 'UAT shortfall');

        $obligation = RefundObligation::query()
            ->where('order_id', $order->id)->where('source_type', 'fulfillment_shortfall')->firstOrFail();
        $ledger = app(RefundLedgerService::class);

        // Failed attempt: releases its reservation, no payout.
        $failed = $ledger->createAttempt($order->fresh(), $obligation, [
            'amount_cents' => 10000000, 'method' => 'cash', 'idempotency_key' => 'uat-failed-1',
        ], $f['admin']);
        $ledger->recordInitiation($failed, $f['admin']);
        $ledger->markFailed($failed->fresh(), $f['admin'], 'bank_rejected', 'UAT failed');
        $this->assertSame(0, RefundPayout::query()->where('obligation_id', $obligation->id)->count());

        // Unknown attempt: cancellation must defer, then reconciliation settles exactly once.
        $unknown = $ledger->createAttempt($order->fresh(), $obligation, [
            'amount_cents' => 10000000, 'method' => 'bank_transfer', 'idempotency_key' => 'uat-unknown-1',
        ], $f['admin']);
        $ledger->recordInitiation($unknown, $f['admin'], 'UAT-UNKNOWN-REF');
        $ledger->markUnknown($unknown->fresh(), $f['admin'], 'no callback');

        $cancellation = app(CancellationFinancialService::class);
        $order->update(['status' => 'dibatalkan', 'cancelled_at' => now(), 'cancelled_by' => $f['admin']->id]);
        $cancellation->bookCancellationReduction($order->fresh(), $f['admin']);
        $cancellation->finalize($order->fresh(), $f['admin']);

        $this->assertSame('pending_reconciliation', $order->fresh()->cancellation_financial_status);
        $this->assertSame(0, RefundObligation::query()->where('order_id', $order->id)->where('source_type', 'cancellation')->count());

        $ledger->reconcileUnknown($unknown->fresh(), $f['admin'], true, 'UAT bank statement evidence');

        $this->assertSame('finalized', $order->fresh()->cancellation_financial_status);
        $this->assertSame(1, RefundPayout::query()->where('obligation_id', $obligation->id)->count());
        $this->assertSame(20000000, (int) round((float) $order->fresh()->paid_amount * 100));

        $cancelObligation = RefundObligation::query()
            ->where('order_id', $order->id)->where('source_type', 'cancellation')->firstOrFail();
        $this->assertSame(20000000, (int) round((float) $cancelObligation->amount * 100));
        $this->assertSame('approved', $cancelObligation->status);

        $attempt = $ledger->createAttempt($order->fresh(), $cancelObligation, [
            'amount_cents' => 20000000, 'method' => 'cash', 'idempotency_key' => 'uat-cancel-payout-1',
        ], $f['admin']);
        $ledger->recordInitiation($attempt, $f['admin']);
        $payout = $ledger->completeAttempt($attempt->fresh(), $f['admin']);

        $this->assertTrue((bool) $payout->fresh()->recorded_on_cancelled_obligation);
        $this->assertSame(0, (int) round((float) $order->fresh()->paid_amount * 100));
        $this->assertSame('completed', $cancelObligation->fresh()->status);
    }

    /** @return array{agen:User,admin:User,keuangan:User,konsumen:User,product:Product,order:Order,item:OrderItem} */
    private function order(int $withVoucher = 0): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'UAT Branch', 'address' => 'Test', 'latitude' => -6.2, 'longitude' => 106.8]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 0, 'free_shipping_enabled' => true, 'is_active' => true]);

        $product = Product::create([
            'sku' => 'UA-'.Str::uuid(), 'name' => 'UAT Cake', 'slug' => 'uat-'.uniqid(),
            'has_variations' => false, 'base_price' => 100000, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 20]);

        $payload = [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ];

        if ($withVoucher > 0) {
            Voucher::create([
                'agent_id' => $agen->id, 'code' => 'UAT'.Str::upper(Str::random(6)), 'name' => 'UAT voucher',
                'type' => 'fixed', 'value' => $withVoucher, 'is_active' => true,
            ]);
            $payload['voucher_code'] = Voucher::query()->where('agent_id', $agen->id)->value('code');
        }

        $orderId = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', $payload)->assertCreated()->json('data.id');

        $order = Order::withoutGlobalScopes()->findOrFail($orderId);
        $item = OrderItem::query()->where('order_id', $orderId)->firstOrFail();

        return compact('agen', 'admin', 'keuangan', 'konsumen', 'product', 'order', 'item');
    }

    private function putItemDelivered(Order $order, OrderItem $item): void
    {
        $order->update(['status' => 'terkirim']);
        $item->update(['status' => 'terkirim']);
    }
}
