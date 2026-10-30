<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderFinancialSnapshot;
use App\Models\OrderItem;
use App\Models\OrderItemAdjustment;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\RefundMigrationBatch;
use App\Models\RefundObligation;
use App\Models\RefundPayout;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use App\Models\ShippingConfiguration;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\Report\ReportService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

/**
 * N-03: backfill dry-run writes nothing, apply is insert-or-skip by legacy key, rerun is a no-op,
 * G is derived as P + A and paid_amount is never touched a second time.
 */
class RefundBackfillTest extends TestCase
{
    use HasTestRegion;
    use RestoresIsolatedTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_dry_run_writes_nothing_and_apply_is_idempotent(): void
    {
        [$order, $item, $admin] = $this->legacyOrder();
        $paidBefore = (string) $order->paid_amount;

        OrderItemAdjustment::create([
            'order_item_id' => $item->id,
            'adjusted_by' => $admin->id,
            'quantity_reduced' => 1,
            'reason' => 'legacy refund',
            'refund_amount' => 20000,
            'refund_status' => 'processed',
        ]);

        $this->artisan('refunds:backfill-ledger', ['--batch' => 'bf-test'])->assertSuccessful();

        $this->assertDatabaseCount('order_financial_snapshots', 0);
        $this->assertDatabaseCount('refund_obligations', 0);
        $this->assertDatabaseCount('refund_payouts', 0);
        $this->assertSame($paidBefore, (string) Order::withoutGlobalScopes()->findOrFail($order->id)->paid_amount);
        $this->assertSame('dry_run', RefundMigrationBatch::where('batch_key', 'bf-test:dry_run')->value('status'));

        $this->artisan('refunds:backfill-ledger', ['--apply' => true, '--batch' => 'bf-test'])->assertSuccessful();

        $this->assertDatabaseCount('order_financial_snapshots', 1);
        $this->assertSame('provisional_legacy', OrderFinancialSnapshot::firstOrFail()->provenance);
        $this->assertDatabaseCount('refund_obligations', 1);
        $this->assertDatabaseCount('refund_payouts', 1);

        $payout = RefundPayout::firstOrFail();
        $this->assertSame('legacy_unverified', $payout->verification_status);
        $this->assertTrue((bool) $payout->affects_paid_amount);
        $this->assertNull($payout->paid_effect_applied_at);

        $fresh = Order::withoutGlobalScopes()->findOrFail($order->id);
        $this->assertSame($paidBefore, (string) $fresh->paid_amount, 'paid_amount is never written by backfill');
        $this->assertSame(
            (float) $paidBefore + 20000.0,
            (float) $fresh->gross_received_amount,
            'G = P + A',
        );

        $snapshotCount = OrderFinancialSnapshot::count();
        $obligationCount = RefundObligation::count();
        $payoutCount = RefundPayout::count();

        $this->artisan('refunds:backfill-ledger', ['--apply' => true, '--batch' => 'bf-test'])->assertSuccessful();

        $this->assertSame($snapshotCount, OrderFinancialSnapshot::count());
        $this->assertSame($obligationCount, RefundObligation::count());
        $this->assertSame($payoutCount, RefundPayout::count());
        $this->assertSame(
            (float) $paidBefore + 20000.0,
            (float) Order::withoutGlobalScopes()->findOrFail($order->id)->gross_received_amount,
            'G is not double counted on rerun',
        );
    }

    public function test_reporting_read_switch_uses_only_proven_payouts(): void
    {
        [$order, $item, $admin] = $this->legacyOrder();

        OrderItemAdjustment::create([
            'order_item_id' => $item->id,
            'adjusted_by' => $admin->id,
            'quantity_reduced' => 1,
            'reason' => 'legacy refund',
            'refund_amount' => 20000,
            'refund_status' => 'processed',
        ]);

        $this->artisan('refunds:backfill-ledger', ['--apply' => true, '--batch' => 'bf-report'])->assertSuccessful();

        $superAdmin = User::factory()->superAdmin()->create(['agent_id' => null]);
        $reports = app(ReportService::class);

        config(['refunds.reporting_source' => 'legacy']);
        $this->assertSame(20000.0, (float) $reports->financeSummary($superAdmin, [])['total_refunds']);

        config(['refunds.reporting_source' => 'ledger']);
        $this->assertSame(0.0, (float) $reports->financeSummary($superAdmin, [])['total_refunds'], 'legacy_unverified is not R_c');

        RefundPayout::firstOrFail()->update(['verification_status' => 'proven']);
        $this->assertSame(20000.0, (float) $reports->financeSummary($superAdmin, [])['total_refunds']);
    }

    public function test_interrupted_apply_resumes_without_skipping_or_duplicating_rows(): void
    {
        [$orderOne, $itemOne, $adminOne] = $this->legacyOrder();
        [$orderTwo, $itemTwo, $adminTwo] = $this->legacyOrder();

        $adjustmentOne = OrderItemAdjustment::create([
            'order_item_id' => $itemOne->id,
            'adjusted_by' => $adminOne->id,
            'quantity_reduced' => 1,
            'reason' => 'legacy refund one',
            'refund_amount' => 20000,
            'refund_status' => 'processed',
        ]);

        $adjustmentTwo = OrderItemAdjustment::create([
            'order_item_id' => $itemTwo->id,
            'adjusted_by' => $adminTwo->id,
            'quantity_reduced' => 1,
            'reason' => 'legacy refund two',
            'refund_amount' => 20000,
            'refund_status' => 'processed',
        ]);

        // A legacy processed return must stay needs_verification and must never get a payout.
        $return = ReturnRequest::create([
            'order_id' => $orderTwo->id,
            'requested_by' => $adminTwo->id,
            'reason' => 'legacy return',
            'status' => 'approved',
            'total_refund_amount' => 10000,
        ]);
        $returnItem = ReturnItem::create([
            'return_id' => $return->id,
            'order_item_id' => $itemTwo->id,
            'quantity_returned' => 1,
            'refund_amount' => 10000,
            'restock' => false,
            'status' => 'approved',
            'refund_status' => 'processed',
        ]);

        // The CLI has no authenticated request context; clear the test's last actingAs user.
        $this->app['auth']->forgetGuards();

        // Dry-run (limited) writes nothing and uses a separate mode key.
        $this->artisan('refunds:backfill-ledger', ['--batch' => 'resume', '--limit' => 1])->assertSuccessful();
        $this->assertDatabaseCount('order_financial_snapshots', 0);
        $this->assertDatabaseCount('refund_obligations', 0);
        $this->assertDatabaseCount('refund_payouts', 0);
        $this->assertSame('dry_run', RefundMigrationBatch::where('batch_key', 'resume:dry_run')->value('status'));

        // Interrupted apply: only the first candidate order is processed.
        $this->artisan('refunds:backfill-ledger', [
            '--apply' => true, '--batch' => 'resume', '--limit' => 1,
        ])->assertSuccessful();

        $this->assertSame(1, OrderFinancialSnapshot::query()->where('order_id', $orderOne->id)->count());
        $this->assertSame(0, OrderFinancialSnapshot::query()->where('order_id', $orderTwo->id)->count());
        $this->assertSame(1, RefundObligation::query()->where('order_id', $orderOne->id)->count());
        $this->assertSame(0, RefundObligation::query()->where('order_id', $orderTwo->id)->count());

        // Resume the same batch without a limit: skips the committed row, processes the rest.
        $this->artisan('refunds:backfill-ledger', ['--apply' => true, '--batch' => 'resume'])->assertSuccessful();

        $this->assertSame(2, OrderFinancialSnapshot::count());
        $this->assertSame(2, RefundPayout::count());
        $this->assertSame(3, RefundObligation::count(), 'two adjustment payouts + one needs_verification return');

        $verification = RefundObligation::query()
            ->where('obligation_key', 'legacy:return_item:'.$returnItem->id)
            ->firstOrFail();
        $this->assertSame('needs_verification', $verification->status);
        $this->assertSame(0, RefundPayout::query()->where('obligation_id', $verification->id)->count());
        $this->assertSame(0, RefundPayout::query()->where('verification_status', 'proven')->count());

        // R_c is proven-only: legacy_unverified + needs_verification contribute nothing.
        $proven = app(\App\Services\Refund\RefundMetrics::class)
            ->provenCentsForOrders([$orderOne->id, $orderTwo->id]);
        $this->assertSame(0, $proven);

        // A second full rerun is a pure no-op and records duplicate keys.
        $this->artisan('refunds:backfill-ledger', ['--apply' => true, '--batch' => 'resume'])->assertSuccessful();

        $this->assertSame(2, OrderFinancialSnapshot::count());
        $this->assertSame(2, RefundPayout::count());
        $this->assertSame(3, RefundObligation::count());
        foreach (['legacy:return_item:'.$returnItem->id] as $key) {
            $this->assertSame(1, RefundObligation::query()->where('obligation_key', $key)->count());
        }
        $this->assertSame(1, RefundObligation::query()->where('obligation_key', 'legacy:order_item_adjustment:'.$adjustmentOne->id)->count());
        $this->assertSame(1, RefundObligation::query()->where('obligation_key', 'legacy:order_item_adjustment:'.$adjustmentTwo->id)->count());

        $batch = RefundMigrationBatch::query()->where('batch_key', 'resume:apply')->firstOrFail();
        $this->assertSame('completed', $batch->status);
        $this->assertGreaterThanOrEqual(2, (int) ($batch->stats['duplicate_keys'] ?? 0));
        $this->assertSame(0, (int) ($batch->stats['payouts_created'] ?? 0));
        $this->assertSame(0, (int) ($batch->stats['verification_created'] ?? 0));
    }

    private function legacyOrder(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Backfill Branch', 'address' => 'Test', 'latitude' => -6.2, 'longitude' => 106.8]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true]);
        $product = Product::create(['sku' => 'BF-'.Str::uuid(), 'name' => 'Backfill Cake', 'slug' => 'bf-'.uniqid(), 'has_variations' => false, 'base_price' => 20000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 10]);

        $id = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $product->id, 'quantity' => 3]],
                'recipient_name' => 'Buyer', 'recipient_phone' => '0812', 'address_line' => 'Jl. Buyer',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated()->json('data.id');

        $item = OrderItem::where('order_id', $id)->firstOrFail();
        $order = Order::withoutGlobalScopes()->findOrFail($id);

        // Simulate the historical state: the two refunds already left cash and paid_amount was
        // already reduced by the legacy endpoint, with no ledger payout recorded.
        $order->update([
            'payment_status' => 'paid',
            'total_amount' => 60000,
            'paid_amount' => 40000,
            'remaining_amount' => 0,
        ]);

        return [$order->fresh(), $item, $admin];
    }
}
