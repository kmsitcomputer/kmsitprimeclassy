<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BankTransferVerification;
use App\Models\CodPaymentProof;
use App\Models\Commission;
use App\Models\DeliveryVerification;
use App\Models\InventoryCancellationReversal;
use App\Models\Media;
use App\Models\Order;
use App\Models\OrderAdditionalPayment;
use App\Models\OrderItem;
use App\Models\OrderItemAdjustment;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductFee;
use App\Models\ProductStock;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use App\Models\Shipment;
use App\Models\StockHandover;
use App\Models\StockMovement;
use App\Models\StockRequest;
use App\Models\StockRequestFulfillment;
use App\Models\StockRequestItem;
use App\Models\StockRequestProposal;
use App\Models\StockRequestProposalItem;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\SubStockRequest;
use App\Models\SubStockRequestItem;
use App\Models\SubStockReservation;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TransactionResetCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Transaction tables the reset must empty (mirrors ResetTransactions order; filtered by existence). */
    private function transactionTables(): array
    {
        return array_values(array_filter([
            'sub_stock_reservations',
            'stock_request_proposal_items',
            'stock_request_proposals',
            'stock_request_fulfillments',
            'inventory_cancellation_reversals',
            'stock_request_items',
            'stock_requests',
            'return_items',
            'returns',
            'order_item_adjustments',
            'commissions',
            'order_fulfillment_change_proposals',
            'delivery_verifications',
            'cod_payment_proofs',
            'bank_transfer_verifications',
            'order_items',
            'shipments',
            'order_additional_payments',
            'payment_webhook_logs',
            'payment_transactions',
            'orders',
            'stock_handovers',
            'sub_stock_request_items',
            'sub_stock_requests',
            'stock_transfer_items',
            'stock_transfers',
        ], fn (string $table) => Schema::hasTable($table)));
    }

    /**
     * Seeds one order graph exercising every FK edge from the production
     * recon: split order items, shipment + delivery verification, payment
     * chain, returns, commissions, adjustments, the stock-request chain and
     * the warehouse transfer / Sub-request chain.
     *
     * @return array<string, mixed>
     */
    private function seedTransactionGraph(): array
    {
        $this->seed(RoleSeeder::class);
        Storage::fake('public');

        $agent = User::factory()->agen()->create();
        $customer = User::factory()->konsumen()->create(['agent_id' => $agent->id]);

        $product = Product::create([
            'name' => 'Master Product', 'slug' => 'master-product', 'sku' => 'MASTER-001',
            'has_variations' => false, 'base_price' => 10000, 'weight_grams' => 100, 'status' => 'active',
        ]);
        ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'agent', 'amount' => 1000]);
        // on_hand 18 with an 'out' movement of -2 restores to 20; reserved 2 is zeroed.
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 18, 'quantity_reserved' => 2]);

        $method = PaymentMethod::create(['code' => 'test', 'name' => 'Test', 'type' => 'manual', 'is_active' => true]);

        $order = Order::create([
            'order_no' => 'RESET-001', 'konsumen_id' => $customer->id, 'agent_id' => $agent->id,
            'payment_method_id' => $method->id, 'status' => 'diterima', 'payment_status' => 'unpaid',
            'subtotal_amount' => 40000, 'total_amount' => 40000,
            'recipient_name_snapshot' => 'Customer', 'recipient_phone_snapshot' => '0800', 'address_snapshot' => 'Address',
        ]);
        $shipment = Shipment::create(['order_id' => $order->id, 'status' => 'pending']);
        $item = OrderItem::create([
            'order_id' => $order->id, 'shipment_id' => $shipment->id, 'product_id' => $product->id,
            'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku,
            'unit_price_snapshot' => 10000, 'original_quantity' => 2, 'fulfilled_quantity' => 2,
            'subtotal_snapshot' => 20000, 'status' => 'diterima',
        ]);
        // Split child exercises the order_items self-FK (RESTRICT) nulling.
        $split = OrderItem::create([
            'order_id' => $order->id, 'shipment_id' => $shipment->id, 'split_from_order_item_id' => $item->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku,
            'unit_price_snapshot' => 10000, 'original_quantity' => 2, 'fulfilled_quantity' => 2,
            'subtotal_snapshot' => 20000, 'status' => 'diterima',
        ]);

        $payment = PaymentTransaction::create([
            'order_id' => $order->id, 'payment_method_id' => $method->id,
            'type' => 'payment', 'amount' => 40000, 'status' => 'pending',
        ]);
        Storage::disk('public')->put('bank-proof/reset-001.jpg', 'proof');
        BankTransferVerification::create([
            'payment_transaction_id' => $payment->id, 'bank_name' => 'Bank',
            'account_name' => 'Agent', 'account_number' => '123',
            'proof_image_path' => 'bank-proof/reset-001.jpg', 'status' => 'pending',
        ]);
        $codMedia = Media::create([
            'disk' => 'public', 'path' => 'media/cod_payment_proof/reset-001.jpg',
            'collection' => 'cod_payment_proof', 'mime_type' => 'image/jpeg',
            'extension' => 'jpg', 'size' => 10,
        ]);
        Storage::disk('public')->put('media/cod_payment_proof/reset-001.jpg', 'proof');
        CodPaymentProof::create([
            'payment_transaction_id' => $payment->id, 'proof_media_id' => $codMedia->id, 'status' => 'pending',
        ]);
        $additional = OrderAdditionalPayment::create([
            'order_id' => $order->id, 'requested_by' => $agent->id, 'method' => 'transfer',
            'payment_transaction_id' => $payment->id, 'amount' => 5000,
            'reason' => 'Extra', 'status' => 'pending',
        ]);
        $item->update(['additional_payment_id' => $additional->id]);

        OrderItemAdjustment::create([
            'order_item_id' => $item->id, 'adjusted_by' => $agent->id,
            'quantity_reduced' => 0, 'reason' => 'audit', 'refund_amount' => 0,
        ]);
        Commission::create([
            'order_id' => $order->id, 'order_item_id' => $item->id,
            'beneficiary_user_id' => $agent->id, 'beneficiary_role' => 'agent',
            'amount' => 1000, 'earned_at' => now(),
        ]);
        $return = ReturnRequest::create([
            'order_id' => $order->id, 'requested_by' => $customer->id, 'reason' => 'defect',
        ]);
        ReturnItem::create([
            'return_id' => $return->id, 'order_item_id' => $item->id,
            'quantity_returned' => 1, 'refund_amount' => 10000,
        ]);
        DeliveryVerification::create([
            'shipment_id' => $shipment->id, 'outcome' => 'received',
            'verified_by' => $agent->id, 'verified_at' => now(),
        ]);

        // Stock-request chain (the production failure: stock_request_items -> order_items).
        $stockRequest = StockRequest::create([
            'agent_id' => $agent->id, 'order_id' => $order->id,
            'request_number' => 'SR-RESET-001', 'status' => 'pending',
        ]);
        $stockItem = StockRequestItem::create([
            'stock_request_id' => $stockRequest->id, 'order_item_id' => $item->id,
            'product_id' => $product->id, 'requested_qty' => 2, 'fulfilled_qty' => 0, 'remaining_qty' => 2,
        ]);
        $proposal = StockRequestProposal::create([
            'agent_id' => $agent->id, 'stock_request_id' => $stockRequest->id,
            'status' => 'pending', 'requested_by' => $agent->id,
        ]);
        StockRequestProposalItem::create([
            'stock_request_proposal_id' => $proposal->id,
            'stock_request_item_id' => $stockItem->id, 'quantity' => 2,
        ]);
        StockRequestFulfillment::create([
            'stock_request_id' => $stockRequest->id,
            'idempotency_key' => 'reset-001', 'fulfilled_by' => $agent->id,
        ]);
        InventoryCancellationReversal::create([
            'agent_id' => $agent->id, 'order_id' => $order->id, 'order_item_id' => $split->id,
            'stock_request_id' => $stockRequest->id, 'processed_by' => $agent->id,
        ]);

        // Sub / warehouse chain.
        $location = WarehouseSubLocation::create([
            'agent_id' => $agent->id, 'code' => 'SUB-RESET', 'name' => 'Sub Reset', 'created_by' => $agent->id,
        ]);
        SubStockReservation::create([
            'agent_id' => $agent->id, 'sub_location_id' => $location->id,
            'order_item_id' => $split->id, 'product_id' => $product->id,
            'quantity' => 2, 'status' => 'active',
        ]);
        WarehouseStock::create([
            'agent_id' => $agent->id, 'product_id' => $product->id,
            'stock_type' => 'transit', 'quantity' => 20,
        ]);
        WarehouseSetting::create(['agent_id' => $agent->id, 'factory_plan_enabled' => false]);

        $transfer = StockTransfer::create([
            'agent_id' => $agent->id, 'transfer_number' => 'TR-RESET-001',
            'source_stock_type' => 'transit', 'destination_stock_type' => 'sub',
            'status' => 'pending', 'created_by' => $agent->id,
        ]);
        StockTransferItem::create([
            'stock_transfer_id' => $transfer->id, 'product_id' => $product->id, 'quantity' => 2,
        ]);
        StockHandover::create([
            'agent_id' => $agent->id, 'stock_transfer_id' => $transfer->id,
            'handover_number' => 'HOV-RESET-001', 'handed_over_by' => $agent->id, 'status' => 'pending',
        ]);
        $subRequest = SubStockRequest::create([
            'agent_id' => $agent->id, 'sub_location_id' => $location->id,
            'request_number' => 'SSR-RESET-001', 'direction' => 'replenish',
            'status' => 'requested', 'requested_by' => $agent->id,
            'stock_transfer_id' => $transfer->id,
        ]);
        SubStockRequestItem::create([
            'sub_stock_request_id' => $subRequest->id, 'product_id' => $product->id, 'quantity' => 2,
        ]);

        StockMovement::create([
            'agent_id' => $agent->id, 'product_id' => $product->id, 'type' => 'reserve',
            'quantity' => 2, 'reference_type' => 'order', 'reference_id' => $order->id,
        ]);
        StockMovement::create([
            'agent_id' => $agent->id, 'product_id' => $product->id, 'type' => 'out',
            'quantity' => -2, 'reference_type' => 'order', 'reference_id' => $order->id,
        ]);

        ActivityLog::create([
            'causer_id' => $agent->id, 'subject_type' => 'App\\Models\\Order',
            'subject_id' => $order->id, 'event' => 'order.created',
        ]);
        ActivityLog::create([
            'causer_id' => $agent->id, 'subject_type' => 'App\\Models\\StockTransfer',
            'subject_id' => $transfer->id, 'event' => 'stock_transfer.approved',
        ]);

        return compact('agent', 'customer', 'product', 'method', 'order', 'location');
    }

    public function test_dry_run_does_not_mutate_data(): void
    {
        $this->seedTransactionGraph();

        $counts = fn () => collect($this->transactionTables())
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
        $before = $counts();
        $this->assertGreaterThan(0, $before['orders']);
        $this->assertGreaterThan(0, $before['stock_requests']);
        $this->assertGreaterThan(0, $before['stock_request_items']);
        $movementsBefore = DB::table('stock_movements')->count();
        $activityBefore = DB::table('activity_logs')->count();

        $this->artisan('transactions:reset', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($before, $counts());
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
        $this->assertSame($activityBefore, DB::table('activity_logs')->count());
        $this->assertDatabaseHas('product_stocks', ['quantity_on_hand' => 18, 'quantity_reserved' => 2]);
    }

    public function test_reset_succeeds_with_stock_requests_linked_to_order_items(): void
    {
        $this->seedTransactionGraph();

        // Precondition mirroring the production failure shape.
        $this->assertDatabaseCount('stock_requests', 1);
        $this->assertDatabaseCount('stock_request_items', 1);
        $this->assertDatabaseCount('order_items', 2);

        $this->artisan('transactions:reset', ['--force' => true])->assertSuccessful();

        foreach (['stock_request_proposal_items', 'stock_request_proposals', 'stock_request_fulfillments', 'inventory_cancellation_reversals', 'stock_request_items', 'stock_requests', 'sub_stock_reservations', 'order_items', 'orders'] as $table) {
            if (Schema::hasTable($table)) {
                $this->assertDatabaseCount($table, 0);
            }
        }
    }

    public function test_reset_handles_all_fk_dependent_transaction_tables(): void
    {
        $this->seedTransactionGraph();

        // Every FK edge from the production recon is populated.
        foreach (['delivery_verifications', 'return_items', 'returns', 'order_item_adjustments', 'commissions', 'cod_payment_proofs', 'bank_transfer_verifications', 'order_additional_payments', 'payment_transactions', 'shipments', 'stock_handovers', 'sub_stock_request_items', 'sub_stock_requests', 'stock_transfer_items', 'stock_transfers'] as $table) {
            $this->assertGreaterThan(0, DB::table($table)->count(), "expected seeded rows in {$table}");
        }

        $this->artisan('transactions:reset', ['--force' => true])->assertSuccessful();

        foreach ($this->transactionTables() as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        // Reset is idempotent: a second run on empty tables still succeeds.
        $this->artisan('transactions:reset', ['--force' => true])->assertSuccessful();
    }

    public function test_reset_empties_transactions_and_preserves_master_and_physical_stock(): void
    {
        $seed = $this->seedTransactionGraph();

        $this->artisan('transactions:reset', ['--force' => true])->assertSuccessful();

        // D. Order/payment/shipment/warehouse transaction data is empty.
        foreach ($this->transactionTables() as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, DB::table('stock_movements')->where('reference_type', 'order')->count());

        // E. Master / configuration data survives.
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseHas('products', ['id' => $seed['product']->id, 'sku' => 'MASTER-001']);
        $this->assertDatabaseHas('product_fees', ['product_id' => $seed['product']->id, 'beneficiary_role' => 'agent', 'amount' => 1000]);
        $this->assertDatabaseHas('payment_methods', ['id' => $seed['method']->id, 'code' => 'test']);
        $this->assertDatabaseHas('warehouse_sub_locations', ['id' => $seed['location']->id, 'code' => 'SUB-RESET']);
        $this->assertDatabaseHas('warehouse_settings', ['agent_id' => $seed['agent']->id]);
        $this->assertDatabaseHas('warehouse_stocks', [
            'agent_id' => $seed['agent']->id, 'product_id' => $seed['product']->id,
            'stock_type' => 'transit', 'quantity' => 20,
        ]);

        // F/G/H. Physical stock rows survive, reservations zeroed, on-hand restored (18 - (-2) = 20).
        $this->assertDatabaseHas('product_stocks', [
            'agent_id' => $seed['agent']->id, 'product_id' => $seed['product']->id,
            'quantity_on_hand' => 20, 'quantity_reserved' => 0,
        ]);
        $this->assertSame(0, (int) DB::table('product_stocks')->sum('quantity_reserved'));
        $this->assertSame(0, (int) DB::table('product_variation_stocks')->sum('quantity_reserved'));
    }

    public function test_reset_preserves_warehouse_physical_stock_while_deleting_transfer_graph(): void
    {
        $this->seed(RoleSeeder::class);
        Storage::fake('public');

        $agent = User::factory()->agen()->create();

        $product = Product::create([
            'name' => 'Warehouse Product', 'slug' => 'warehouse-product', 'sku' => 'WH-001',
            'has_variations' => false, 'base_price' => 10000, 'weight_grams' => 100, 'status' => 'active',
        ]);
        // Agent order effect keeps the existing restoration semantics:
        // on_hand 18 with an 'out' movement of -2 restores to 20; reserved 2 is zeroed.
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 18, 'quantity_reserved' => 2]);

        $location = WarehouseSubLocation::create([
            'agent_id' => $agent->id, 'code' => 'SUB-WH15', 'name' => 'Sub WH15', 'created_by' => $agent->id,
        ]);

        // Post-transfer CURRENT STATE: physical balances that must survive the reset exactly.
        WarehouseStock::create([
            'agent_id' => $agent->id, 'product_id' => $product->id,
            'stock_type' => 'transit', 'quantity' => 15,
        ]);
        WarehouseStock::create([
            'agent_id' => $agent->id, 'product_id' => $product->id,
            'stock_type' => 'sub', 'sub_location_id' => $location->id, 'quantity' => 5,
        ]);

        // The transfer / Sub-request / handover transaction graph that produced that state.
        $transfer = StockTransfer::create([
            'agent_id' => $agent->id, 'transfer_number' => 'TR-WH15-001',
            'source_stock_type' => 'transit', 'destination_stock_type' => 'sub',
            'destination_sub_location_id' => $location->id,
            'status' => 'completed', 'created_by' => $agent->id,
            'completed_by' => $agent->id, 'completed_at' => now(),
        ]);
        StockTransferItem::create([
            'stock_transfer_id' => $transfer->id, 'product_id' => $product->id, 'quantity' => 5,
        ]);
        StockHandover::create([
            'agent_id' => $agent->id, 'stock_transfer_id' => $transfer->id,
            'handover_number' => 'HOV-WH15-001', 'handed_over_by' => $agent->id, 'status' => 'handed_over',
        ]);
        $subRequest = SubStockRequest::create([
            'agent_id' => $agent->id, 'sub_location_id' => $location->id,
            'request_number' => 'SSR-WH15-001', 'direction' => 'replenish',
            'status' => 'executed', 'requested_by' => $agent->id,
            'stock_transfer_id' => $transfer->id,
        ]);
        SubStockRequestItem::create([
            'sub_stock_request_id' => $subRequest->id, 'product_id' => $product->id, 'quantity' => 5,
        ]);

        // Warehouse-domain history: intentionally preserved, must not fail the
        // final "Remaining transaction stock movements" verification.
        StockMovement::create([
            'agent_id' => $agent->id, 'product_id' => $product->id, 'type' => 'transfer_out',
            'quantity' => -5, 'stock_type' => 'transit', 'counterpart_stock_type' => 'sub',
            'transfer_id' => $transfer->id, 'reference_type' => StockTransfer::class,
            'reference_id' => $transfer->id, 'note' => 'before=20;after=15', 'created_by' => $agent->id,
        ]);
        StockMovement::create([
            'agent_id' => $agent->id, 'product_id' => $product->id, 'type' => 'transfer_in',
            'quantity' => 5, 'stock_type' => 'sub', 'sub_location_id' => $location->id,
            'counterpart_stock_type' => 'transit',
            'transfer_id' => $transfer->id, 'reference_type' => StockTransfer::class,
            'reference_id' => $transfer->id, 'note' => 'before=0;after=5', 'created_by' => $agent->id,
        ]);
        // Transaction-derived Agent movements: deleted, and drive product restoration.
        StockMovement::create([
            'agent_id' => $agent->id, 'product_id' => $product->id, 'type' => 'reserve',
            'quantity' => 2, 'reference_type' => 'order', 'reference_id' => 1,
        ]);
        StockMovement::create([
            'agent_id' => $agent->id, 'product_id' => $product->id, 'type' => 'out',
            'quantity' => -2, 'reference_type' => 'order', 'reference_id' => 1,
        ]);

        $this->assertDatabaseCount('stock_transfers', 1);
        $this->assertDatabaseCount('stock_transfer_items', 1);
        $this->assertDatabaseCount('stock_handovers', 1);
        $this->assertDatabaseCount('sub_stock_requests', 1);
        $this->assertDatabaseCount('sub_stock_request_items', 1);

        $this->artisan('transactions:reset', ['--force' => true])->assertSuccessful();

        // Transaction tables are empty — the transfer graph is gone.
        foreach ($this->transactionTables() as $table) {
            $this->assertDatabaseCount($table, 0);
        }

        // BOTH warehouse physical rows still exist with EXACT quantities.
        $this->assertDatabaseHas('warehouse_stocks', [
            'agent_id' => $agent->id, 'product_id' => $product->id,
            'stock_type' => 'transit', 'quantity' => 15,
        ]);
        $this->assertDatabaseHas('warehouse_stocks', [
            'agent_id' => $agent->id, 'product_id' => $product->id,
            'stock_type' => 'sub', 'quantity' => 5,
        ]);
        $this->assertSame(15, (int) WarehouseStock::where('agent_id', $agent->id)->where('product_id', $product->id)->where('stock_type', 'transit')->value('quantity'));
        $this->assertSame(5, (int) WarehouseStock::where('agent_id', $agent->id)->where('product_id', $product->id)->where('stock_type', 'sub')->value('quantity'));

        // Transaction movements deleted; warehouse-domain history preserved.
        $this->assertSame(0, DB::table('stock_movements')->where('reference_type', 'order')->count());
        $this->assertSame(2, DB::table('stock_movements')->where('reference_type', StockTransfer::class)->count());

        // Existing Agent restoration semantics unchanged (18 - (-2) = 20, reserved zeroed).
        $this->assertDatabaseHas('product_stocks', [
            'agent_id' => $agent->id, 'product_id' => $product->id,
            'quantity_on_hand' => 20, 'quantity_reserved' => 0,
        ]);
    }

    public function test_unsupported_movement_types_block_reset_without_partial_mutation(): void
    {
        $this->seed(RoleSeeder::class);
        $agent = User::factory()->agen()->create();
        $customer = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        $product = Product::create([
            'name' => 'Blocker Product', 'slug' => 'blocker-product', 'sku' => 'BLOCK-001',
            'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 10, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 1]);
        $method = PaymentMethod::create(['code' => 'block', 'name' => 'Block', 'type' => 'manual', 'is_active' => true]);
        $order = Order::create([
            'order_no' => 'RESET-BLOCK', 'konsumen_id' => $customer->id, 'agent_id' => $agent->id,
            'payment_method_id' => $method->id, 'status' => 'diterima', 'payment_status' => 'unpaid',
            'subtotal_amount' => 1000, 'total_amount' => 1000,
            'recipient_name_snapshot' => 'C', 'recipient_phone_snapshot' => '0', 'address_snapshot' => 'A',
        ]);
        StockMovement::create([
            'agent_id' => $agent->id, 'product_id' => $product->id, 'type' => 'fulfillment',
            'quantity' => -1, 'reference_type' => 'order', 'reference_id' => $order->id,
        ]);

        $this->artisan('transactions:reset', ['--force' => true])->assertFailed();

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('product_stocks', ['quantity_on_hand' => 10, 'quantity_reserved' => 1]);
    }

    public function test_production_guard_requires_explicit_force(): void
    {
        $this->seed(RoleSeeder::class);
        User::factory()->agen()->create();

        $this->app['env'] = 'production';

        $this->artisan('transactions:reset', ['--dry-run' => true])->assertSuccessful();
        $this->artisan('transactions:reset')->assertFailed();
        $this->assertDatabaseCount('users', 1);

        $this->artisan('transactions:reset', ['--force' => true])->assertSuccessful();
    }
}
