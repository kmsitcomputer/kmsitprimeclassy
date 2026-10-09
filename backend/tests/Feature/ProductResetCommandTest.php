<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BankTransferVerification;
use App\Models\CodPaymentProof;
use App\Models\Commission;
use App\Models\DeliveryVerification;
use App\Models\InventoryCancellationReversal;
use App\Models\Language;
use App\Models\Media;
use App\Models\Order;
use App\Models\OrderAdditionalPayment;
use App\Models\OrderItem;
use App\Models\OrderItemAdjustment;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookLog;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductCategory;
use App\Models\ProductDiscount;
use App\Models\ProductFee;
use App\Models\ProductImage;
use App\Models\ProductStock;
use App\Models\ProductVariation;
use App\Models\ProductVariationAttribute;
use App\Models\ProductVariationAttributeOption;
use App\Models\ProductVariationComposition;
use App\Models\ProductVariationFee;
use App\Models\ProductVariationStock;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\StockHandover;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
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
use App\Models\Voucher;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Models\WarehouseStockRequest;
use App\Models\WarehouseSubLocation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * Full Product domain reset (products:reset) under the LOCKED Human decision:
 * the catalog AND every row that exists because of it — warehouse
 * transactions, stock movements, stock requests and the related operational
 * history — while master data and configuration survive.
 *
 * Covers: dry-run purity, the --backup-verified precondition, the complete
 * delete scope, the row-scoped order aggregate (an order without product
 * lines must survive), master/configuration protection, the unmapped-
 * dependency refusal, rollback, idempotency, file safety, and the absence of
 * forbidden mechanisms.
 */
class ProductResetCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('public');
    }

    /* ─────────────────────────── fixtures ─────────────────────────── */

    private function makeAgent(): User
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);

        return $agent->fresh();
    }

    private function makeSimpleProduct(string $sku = 'SIMPLE-001'): Product
    {
        return Product::create([
            'name' => 'Simple '.$sku, 'slug' => 'simple-'.strtolower($sku), 'sku' => $sku,
            'has_variations' => false, 'base_price' => 10000, 'weight_grams' => 100, 'status' => 'active',
        ]);
    }

    /** @return array{product:Product, variation:ProductVariation} */
    private function makeVariationProduct(string $suffix = 'V'): array
    {
        $product = Product::create([
            'name' => 'Variable '.$suffix, 'slug' => 'variable-'.strtolower($suffix),
            'has_variations' => true, 'status' => 'active',
        ]);
        $attribute = ProductVariationAttribute::create([
            'product_id' => $product->id, 'name' => 'Ukuran', 'sort_order' => 0,
        ]);
        $option = ProductVariationAttributeOption::create([
            'product_variation_attribute_id' => $attribute->id, 'value' => 'Besar', 'sort_order' => 0,
        ]);
        $variation = ProductVariation::create([
            'product_id' => $product->id, 'sku' => 'VAR-'.$suffix.'-001',
            'price' => 20000, 'weight_grams' => 200, 'is_active' => true, 'sort_order' => 0,
        ]);
        ProductVariationComposition::create([
            'product_variation_id' => $variation->id,
            'product_variation_attribute_id' => $attribute->id,
            'product_variation_attribute_option_id' => $option->id,
        ]);

        return compact('product', 'variation');
    }

    private function makeOrder(User $agent, User $konsumen, string $no, Product $product, ProductVariation $variation): array
    {
        $method = PaymentMethod::first() ?? PaymentMethod::create([
            'code' => 'cod', 'name' => 'COD', 'type' => 'cod', 'is_active' => true,
        ]);

        $order = Order::withoutGlobalScopes()->create([
            'order_no' => $no, 'konsumen_id' => $konsumen->id, 'agent_id' => $agent->id,
            'payment_method_id' => $method->id, 'status' => 'diterima', 'payment_status' => 'unpaid',
            'subtotal_amount' => 20000, 'total_amount' => 20000,
            'recipient_name_snapshot' => 'Customer', 'recipient_phone_snapshot' => '0800', 'address_snapshot' => 'Address',
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_variation_id' => $variation->id,
            'product_name_snapshot' => $product->name, 'sku_snapshot' => $variation->sku,
            'unit_price_snapshot' => 20000, 'subtotal_snapshot' => 20000,
            'original_quantity' => 2, 'fulfilled_quantity' => 2, 'status' => 'diterima',
        ]);

        return compact('order', 'item', 'method');
    }

    /** Every table this command or the fixtures may touch, counted. */
    private function snapshotAll(): array
    {
        $counts = [];
        foreach ($this->allTables() as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /** @return list<string> */
    private function allTables(): array
    {
        $tables = [];
        foreach (Schema::getTables() as $table) {
            $name = (string) ($table['name'] ?? '');
            if ($name !== '') {
                $tables[] = $name;
            }
        }
        sort($tables);

        return $tables;
    }

    private function force(string $backup = 'dev-backup-2026-10-08.sql'): PendingCommand
    {
        return $this->artisan('products:reset', ['--force' => true, '--backup-verified' => $backup]);
    }

    /* ─────────────────── 1. dry run is pure ─────────────────── */

    public function test_dry_run_performs_zero_mutations(): void
    {
        $agent = $this->makeAgent();
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        ['product' => $product, 'variation' => $variation] = $this->makeVariationProduct('DRY');
        $this->makeOrder($agent, $konsumen, 'ORD-DRY-1', $product, $variation);
        ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'agent', 'amount' => 500]);
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 5, 'quantity_reserved' => 0]);
        StockMovement::create([
            'agent_id' => $agent->id, 'product_id' => $product->id, 'type' => 'in', 'quantity' => 5,
        ]);

        $before = $this->snapshotAll();

        $this->artisan('products:reset')->assertSuccessful();

        $this->assertSame($before, $this->snapshotAll());
    }

    /* ─────── 2. --force without a verified backup is refused ─────── */

    public function test_force_without_verified_backup_is_refused(): void
    {
        $agent = $this->makeAgent();
        $product = $this->makeSimpleProduct('NOBACKUP');
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 1, 'quantity_reserved' => 0]);

        $before = $this->snapshotAll();

        $code = Artisan::call('products:reset', ['--force' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('--backup-verified', $output);
        $this->assertSame($before, $this->snapshotAll());
        $this->assertNotNull(Product::withTrashed()->find($product->id));
    }

    /* ─────────── 3. the whole product domain is removed ─────────── */

    public function test_full_reset_empties_catalog_and_product_derived_history(): void
    {
        $agent = $this->makeAgent();
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        $location = WarehouseSubLocation::create([
            'agent_id' => $agent->id, 'code' => 'SUB-1', 'name' => 'Sub 1', 'is_active' => true, 'created_by' => $agent->id,
        ]);
        $language = Language::create(['code' => 'id', 'name' => 'Indonesian', 'is_default' => true]);

        ['product' => $product, 'variation' => $variation] = $this->makeVariationProduct('FULL');
        ['order' => $order, 'item' => $item] = $this->makeOrder($agent, $konsumen, 'ORD-FULL-1', $product, $variation);

        // catalog children
        ProductAttribute::create(['product_id' => $product->id, 'attribute_name' => 'Rasa', 'attribute_value' => 'Coklat']);
        DB::table('products_translations')->insert(['product_id' => $product->id, 'language_id' => $language->id, 'name' => 'Kue']);
        $attribute = ProductVariationAttribute::where('product_id', $product->id)->firstOrFail();
        DB::table('product_variation_attributes_translations')->insert([
            'product_variation_attribute_id' => $attribute->id, 'language_id' => $language->id, 'name' => 'Ukuran',
        ]);
        ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'agent', 'amount' => 1000]);
        ProductVariationFee::create(['product_variation_id' => $variation->id, 'beneficiary_role' => 'agent', 'amount' => 800]);
        Storage::disk('public')->put('products/full.jpg', 'bytes');
        ProductImage::create(['product_id' => $product->id, 'path' => 'products/full.jpg', 'is_primary' => true]);
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 7, 'quantity_reserved' => 0]);
        ProductVariationStock::create(['agent_id' => $agent->id, 'product_variation_id' => $variation->id, 'quantity_on_hand' => 3, 'quantity_reserved' => 0]);
        WarehouseStock::withoutGlobalScopes()->create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 10]);
        WarehouseStock::withoutGlobalScopes()->create(['agent_id' => $agent->id, 'product_variation_id' => $variation->id, 'stock_type' => 'sub', 'sub_location_id' => $location->id, 'quantity' => 2]);
        ProductDiscount::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'name' => 'Promo', 'percentage' => 10]);
        Voucher::create(['agent_id' => $agent->id, 'code' => 'VAR-FULL', 'name' => 'Var voucher', 'type' => 'percentage', 'value' => 5, 'product_variation_id' => $variation->id]);

        // order aggregate
        Shipment::create(['order_id' => $order->id, 'status' => 'pending']);
        PaymentTransaction::create(['order_id' => $order->id, 'payment_method_id' => $order->payment_method_id, 'type' => 'payment', 'amount' => 20000, 'status' => 'paid']);
        Commission::create(['order_id' => $order->id, 'beneficiary_user_id' => $agent->id, 'beneficiary_role' => 'agent', 'amount' => 1000, 'earned_at' => now()]);
        $return = ReturnRequest::create(['order_id' => $order->id, 'requested_by' => $konsumen->id, 'reason' => 'rusak', 'status' => 'requested']);
        ReturnItem::create(['return_id' => $return->id, 'order_item_id' => $item->id, 'quantity_returned' => 1, 'refund_amount' => 20000]);
        OrderItemAdjustment::create(['order_item_id' => $item->id, 'adjusted_by' => $agent->id, 'quantity_reduced' => 0, 'reason' => 'koreksi']);
        OrderAdditionalPayment::create(['order_id' => $order->id, 'amount' => 1000]);
        $stockRequest = StockRequest::create(['agent_id' => $agent->id, 'order_id' => $order->id, 'request_number' => 'SR-1']);
        $stockRequestItem = StockRequestItem::create([
            'stock_request_id' => $stockRequest->id, 'order_item_id' => $item->id,
            'product_id' => $product->id, 'product_variation_id' => $variation->id,
            'requested_qty' => 2, 'fulfilled_qty' => 2, 'remaining_qty' => 0,
        ]);
        $proposal = StockRequestProposal::create(['agent_id' => $agent->id, 'stock_request_id' => $stockRequest->id, 'requested_by' => $agent->id, 'status' => 'pending']);
        StockRequestProposalItem::create(['stock_request_proposal_id' => $proposal->id, 'stock_request_item_id' => $stockRequestItem->id, 'quantity' => 2]);
        StockRequestFulfillment::create(['stock_request_id' => $stockRequest->id, 'idempotency_key' => 'ful-1', 'fulfilled_by' => $agent->id, 'quantity' => 2]);
        SubStockReservation::create(['agent_id' => $agent->id, 'sub_location_id' => $location->id, 'order_item_id' => $item->id, 'product_id' => $product->id, 'product_variation_id' => $variation->id, 'quantity' => 2, 'status' => 'active']);
        InventoryCancellationReversal::create(['agent_id' => $agent->id, 'order_id' => $order->id, 'order_item_id' => $item->id, 'processed_by' => $agent->id]);
        $shipment = Shipment::first();
        DeliveryVerification::create(['shipment_id' => $shipment->id, 'outcome' => 'received', 'verified_by' => $agent->id, 'verified_at' => now(), 'idempotency_key' => 'dv-1']);

        // warehouse operational history
        WarehouseStockRequest::create([
            'agent_id' => $agent->id, 'request_type' => 'addition', 'product_variation_id' => $variation->id,
            'target_stock_type' => 'transit', 'quantity' => 144, 'status' => 'approved', 'requested_by' => $agent->id,
        ]);
        StockMovement::create([
            'agent_id' => $agent->id, 'product_variation_id' => $variation->id,
            'type' => 'factory_in', 'quantity' => 144, 'reference_type' => 'factory_receipt',
        ]);
        $transfer = StockTransfer::create([
            'agent_id' => $agent->id, 'transfer_number' => 'TR-1', 'source_stock_type' => 'transit',
            'destination_stock_type' => 'sub', 'status' => 'completed', 'created_by' => $agent->id,
        ]);
        StockTransferItem::create(['stock_transfer_id' => $transfer->id, 'product_id' => $product->id, 'quantity' => 5]);
        StockHandover::create(['agent_id' => $agent->id, 'stock_transfer_id' => $transfer->id, 'handover_number' => 'HO-1', 'handed_over_by' => $agent->id]);
        $subRequest = SubStockRequest::create([
            'agent_id' => $agent->id, 'sub_location_id' => $location->id, 'stock_transfer_id' => $transfer->id,
            'request_number' => 'SSR-1', 'direction' => 'replenish', 'requested_by' => $agent->id,
        ]);
        SubStockRequestItem::create(['sub_stock_request_id' => $subRequest->id, 'product_variation_id' => $variation->id, 'quantity' => 5]);
        $opname = StockOpname::create([
            'agent_id' => $agent->id, 'opname_number' => 'OP-1', 'opname_type' => 'physical_opname',
            'status' => 'approved', 'created_by' => $agent->id,
        ]);
        StockOpnameItem::create(['stock_opname_id' => $opname->id, 'product_id' => $product->id, 'system_quantity' => 5, 'counted_quantity' => 5]);
        StockMovement::create([
            'agent_id' => $agent->id, 'product_id' => $product->id,
            'type' => 'opname_adjustment', 'quantity' => 5,
            'reference_type' => StockOpname::class, 'reference_id' => $opname->id, 'opname_id' => $opname->id,
        ]);
        $run = DB::table('warehouse_migration_runs')->insertGetId([
            'run_id' => 'legacy-1', 'dry_run' => 0, 'status' => 'completed', 'started_at' => now(),
            'rows_scanned' => 1, 'rows_migrated' => 1, 'rows_skipped' => 0, 'rows_conflicted' => 0,
            'rows_failed' => 0, 'quantity_total' => 10,
        ]);
        DB::table('warehouse_migration_markers')->insert([
            'run_id' => $run, 'source_type' => 'product_stock', 'source_id' => 1, 'agent_id' => $agent->id,
            'product_id' => $product->id, 'source_quantity' => 10, 'destination_stock_type' => 'transit', 'status' => 'completed',
        ]);

        // audit rows for the deleted subjects + one that must survive
        ActivityLog::create(['event' => 'order.dibuat', 'subject_type' => Order::class, 'subject_id' => $order->id, 'causer_id' => $agent->id]);
        ActivityLog::create(['event' => 'product.dibuat', 'subject_type' => Product::class, 'subject_id' => $product->id, 'causer_id' => $agent->id]);
        ActivityLog::create(['event' => 'user.login', 'subject_type' => User::class, 'subject_id' => $agent->id, 'causer_id' => $agent->id]);

        $this->force()->assertSuccessful();

        foreach ([
            'products', 'product_variations', 'product_attributes', 'products_translations',
            'product_variation_attributes', 'product_variation_attributes_translations',
            'product_variation_attribute_options', 'product_variation_compositions',
            'product_images', 'product_fees', 'product_variation_fees',
            'product_stocks', 'product_variation_stocks', 'warehouse_stocks', 'catalog_skus',
            'product_discounts', 'vouchers',
            'order_items', 'orders', 'shipments', 'payment_transactions', 'payment_webhook_logs',
            'commissions', 'returns', 'return_items', 'order_item_adjustments',
            'order_additional_payments', 'delivery_verifications',
            'stock_requests', 'stock_request_items', 'stock_request_proposals',
            'stock_request_proposal_items', 'stock_request_fulfillments', 'sub_stock_reservations',
            'inventory_cancellation_reversals', 'stock_transfers', 'stock_transfer_items',
            'stock_handovers', 'sub_stock_requests', 'sub_stock_request_items',
            'stock_opnames', 'stock_opname_items', 'warehouse_stock_requests', 'stock_movements',
            'warehouse_migration_markers', 'warehouse_migration_runs',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "Delete scope {$table} is not empty");
        }

        $this->assertSame(0, Product::withTrashed()->count());
        $this->assertSame(0, ProductVariation::withTrashed()->count());
        $this->assertFalse(Storage::disk('public')->exists('products/full.jpg'));

        // master data, containers and unrelated audit survive
        $this->assertNotNull($agent->fresh());
        $this->assertNotNull($konsumen->fresh());
        $this->assertNotNull($location->fresh());
        $this->assertNotNull($language->fresh());
        $this->assertSame(1, DB::table('activity_logs')->where('event', 'user.login')->count());
    }

    /* ─────── 4. shared / mixed history: an orderless order survives ─────── */

    public function test_order_without_product_lines_and_its_history_survive(): void
    {
        $agent = $this->makeAgent();
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        $method = PaymentMethod::create(['code' => 'cod', 'name' => 'COD', 'type' => 'cod', 'is_active' => true]);
        ['product' => $product, 'variation' => $variation] = $this->makeVariationProduct('MIX');

        // A product order — the whole aggregate must go.
        ['order' => $productOrder] = $this->makeOrder($agent, $konsumen, 'ORD-MIX-P', $product, $variation);
        Shipment::create(['order_id' => $productOrder->id, 'status' => 'pending']);
        $productPayment = PaymentTransaction::create([
            'order_id' => $productOrder->id, 'payment_method_id' => $method->id,
            'type' => 'payment', 'amount' => 20000, 'status' => 'paid',
        ]);

        // An order that never had a product line — nothing to do with the
        // catalog, so it and its records must survive untouched.
        $cleanOrder = Order::withoutGlobalScopes()->create([
            'order_no' => 'ORD-MIX-CLEAN', 'konsumen_id' => $konsumen->id, 'agent_id' => $agent->id,
            'payment_method_id' => $method->id, 'status' => 'diterima', 'payment_status' => 'unpaid',
            'subtotal_amount' => 0, 'total_amount' => 0,
            'recipient_name_snapshot' => 'Customer', 'recipient_phone_snapshot' => '0800', 'address_snapshot' => 'Address',
        ]);
        $cleanShipment = Shipment::create(['order_id' => $cleanOrder->id, 'status' => 'pending']);
        $cleanPayment = PaymentTransaction::create([
            'order_id' => $cleanOrder->id, 'payment_method_id' => $method->id,
            'type' => 'payment', 'amount' => 0, 'status' => 'paid',
        ]);
        Storage::disk('public')->put('payments/bank-transfer-proofs/keep.jpg', 'kept-bank-proof');
        $cleanBankProof = BankTransferVerification::create([
            'payment_transaction_id' => $cleanPayment->id, 'proof_image_path' => 'payments/bank-transfer-proofs/keep.jpg',
            'bank_name' => 'BCA', 'account_name' => 'Customer', 'account_number' => '456',
        ]);
        Storage::disk('public')->put('media/cod_payment_proof/keep.jpg', 'kept-cod-proof');
        $cleanCodMedia = Media::create([
            'disk' => 'public', 'path' => 'media/cod_payment_proof/keep.jpg', 'collection' => 'cod_payment_proof',
            'original_filename' => 'keep.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size' => 14,
            'mediable_type' => PaymentTransaction::class, 'mediable_id' => $cleanPayment->id,
        ]);
        CodPaymentProof::create([
            'payment_transaction_id' => $cleanPayment->id, 'proof_media_id' => $cleanCodMedia->id, 'status' => 'pending',
        ]);
        $cleanCommission = Commission::create([
            'order_id' => $cleanOrder->id, 'beneficiary_user_id' => $agent->id,
            'beneficiary_role' => 'agent', 'amount' => 0, 'earned_at' => now(),
        ]);
        $cleanReturn = ReturnRequest::create([
            'order_id' => $cleanOrder->id, 'requested_by' => $konsumen->id,
            'reason' => 'keep evidence', 'evidence_path' => 'returns/evidence/keep.jpg', 'status' => 'requested',
        ]);
        Storage::disk('public')->put('returns/evidence/keep.jpg', 'kept-return-proof');
        Storage::disk('public')->put('media/shipment_proof/keep.jpg', 'kept-shipment-proof');
        $cleanShipmentMedia = Media::create([
            'disk' => 'public', 'path' => 'media/shipment_proof/keep.jpg', 'collection' => 'shipment_proof',
            'original_filename' => 'keep.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size' => 20,
            'mediable_type' => Shipment::class, 'mediable_id' => $cleanShipment->id,
        ]);
        $cleanOrderLog = ActivityLog::create([
            'event' => 'order.kept', 'subject_type' => Order::class, 'subject_id' => $cleanOrder->id, 'causer_id' => $agent->id,
        ]);
        $deletedOrderLog = ActivityLog::create([
            'event' => 'order.deleted', 'subject_type' => Order::class, 'subject_id' => $productOrder->id, 'causer_id' => $agent->id,
        ]);

        $resetCode = Artisan::call('products:reset', ['--force' => true, '--backup-verified' => 'test-backup']);
        $this->assertSame(0, $resetCode, Artisan::output());

        $this->assertDatabaseMissing('orders', ['id' => $productOrder->id]);
        $this->assertDatabaseMissing('payment_transactions', ['id' => $productPayment->id]);

        $this->assertDatabaseHas('orders', ['id' => $cleanOrder->id]);
        $this->assertDatabaseHas('shipments', ['id' => $cleanShipment->id]);
        $this->assertDatabaseHas('payment_transactions', ['id' => $cleanPayment->id]);
        $this->assertDatabaseHas('commissions', ['id' => $cleanCommission->id]);
        $this->assertDatabaseHas('returns', ['id' => $cleanReturn->id, 'evidence_path' => 'returns/evidence/keep.jpg']);
        $this->assertDatabaseHas('media', ['id' => $cleanShipmentMedia->id]);
        $this->assertDatabaseHas('media', ['id' => $cleanCodMedia->id]);
        $this->assertDatabaseHas('bank_transfer_verifications', ['id' => $cleanBankProof->id]);
        $this->assertDatabaseHas('activity_logs', ['id' => $cleanOrderLog->id]);
        $this->assertDatabaseMissing('activity_logs', ['id' => $deletedOrderLog->id]);
        $this->assertTrue(Storage::disk('public')->exists('returns/evidence/keep.jpg'));
        $this->assertTrue(Storage::disk('public')->exists('media/shipment_proof/keep.jpg'));
        $this->assertTrue(Storage::disk('public')->exists('media/cod_payment_proof/keep.jpg'));
        $this->assertTrue(Storage::disk('public')->exists('payments/bank-transfer-proofs/keep.jpg'));
        $this->assertSame(1, DB::table('shipments')->count());
        $this->assertSame(1, DB::table('payment_transactions')->count());
    }

    /* ─────────── 5. master data and configuration survive ─────────── */

    public function test_master_data_and_configuration_are_preserved(): void
    {
        $agent = $this->makeAgent();
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        User::factory()->admin()->create(['agent_id' => $agent->id]);

        $category = ProductCategory::create(['name' => 'Kue', 'slug' => 'kue', 'is_active' => true]);
        $method = PaymentMethod::create(['code' => 'bca', 'name' => 'BCA', 'type' => 'manual', 'is_active' => true]);
        $setting = Setting::create(['key' => 'site_name', 'value' => 'PrimeClassy', 'group' => 'website', 'is_public' => true]);
        $warehouseSetting = WarehouseSetting::create(['agent_id' => $agent->id, 'factory_plan_enabled' => false]);
        $location = WarehouseSubLocation::create([
            'agent_id' => $agent->id, 'code' => 'SUB-KEEP', 'name' => 'Keep', 'is_active' => true, 'created_by' => $agent->id,
        ]);
        $language = Language::create(['code' => 'en', 'name' => 'English', 'is_default' => true]);
        DB::table('provinces')->insert(['id' => '32', 'name' => 'Jawa Barat']);
        DB::table('couriers')->insert(['type' => 'internal', 'name' => 'JNE', 'external_code' => 'jne', 'is_active' => 1]);

        // Whole-catalog promotions and a non-product SKU owner must survive.
        Voucher::create(['agent_id' => $agent->id, 'code' => 'GLOBAL', 'name' => 'Whole catalog', 'type' => 'fixed', 'value' => 1000]);
        ProductDiscount::create(['agent_id' => $agent->id, 'name' => 'All products', 'percentage' => 5]);
        DB::table('catalog_skus')->insert(['sku' => 'CUSTOMER-LEVEL', 'owner_type' => 'customer_tier', 'owner_id' => 1]);

        // Media that belongs to neither the catalog nor a transaction.
        Storage::disk('public')->put('media/site_logo.png', 'logo');
        $logo = Media::create([
            'disk' => 'public', 'path' => 'media/site_logo.png', 'collection' => 'site_logo',
            'original_filename' => 'logo.png', 'mime_type' => 'image/png', 'extension' => 'png', 'size' => 4,
        ]);

        $this->makeSimpleProduct('PRESERVE-001');

        $before = $this->snapshotAll();
        $this->force()->assertSuccessful();

        foreach (['users', 'roles', 'user_closures', 'product_categories', 'payment_methods', 'settings',
            'warehouse_settings', 'warehouse_sub_locations', 'languages', 'provinces', 'couriers', 'media'] as $table) {
            $this->assertSame($before[$table], DB::table($table)->count(), "Preserved table {$table} changed");
        }

        $this->assertDatabaseHas('product_categories', ['id' => $category->id]);
        $this->assertDatabaseHas('payment_methods', ['id' => $method->id]);
        $this->assertDatabaseHas('settings', ['id' => $setting->id]);
        $this->assertDatabaseHas('warehouse_settings', ['id' => $warehouseSetting->id]);
        $this->assertDatabaseHas('warehouse_sub_locations', ['id' => $location->id]);
        $this->assertDatabaseHas('languages', ['id' => $language->id]);
        $this->assertDatabaseHas('vouchers', ['code' => 'GLOBAL']);
        $this->assertDatabaseHas('product_discounts', ['name' => 'All products']);
        $this->assertDatabaseHas('catalog_skus', ['sku' => 'CUSTOMER-LEVEL']);
        $this->assertDatabaseHas('media', ['id' => $logo->id]);
        $this->assertTrue(Storage::disk('public')->exists('media/site_logo.png'));
        $this->assertNotNull($agent->fresh());
        $this->assertNotNull($konsumen->fresh());
    }

    /* ─────── 6. warehouse history no longer blocks the reset ─────── */

    public function test_warehouse_history_alone_is_deleted_not_blocking(): void
    {
        $agent = $this->makeAgent();
        ['variation' => $variation] = $this->makeVariationProduct('WH');

        $request = WarehouseStockRequest::create([
            'agent_id' => $agent->id, 'request_type' => 'addition', 'product_variation_id' => $variation->id,
            'target_stock_type' => 'transit', 'quantity' => 144, 'status' => 'approved', 'requested_by' => $agent->id,
        ]);
        $movement = StockMovement::create([
            'agent_id' => $agent->id, 'product_variation_id' => $variation->id,
            'type' => 'factory_in', 'quantity' => 144, 'reference_type' => 'factory_receipt',
        ]);

        $this->assertSame(0, DB::table('orders')->count());

        $this->force()->assertSuccessful();

        $this->assertDatabaseMissing('warehouse_stock_requests', ['id' => $request->id]);
        $this->assertDatabaseMissing('stock_movements', ['id' => $movement->id]);
        $this->assertSame(0, Product::withTrashed()->count());
    }

    /* ─────── 6b. an opname-linked movement is deleted before its opname ─────── */

    public function test_an_opname_linked_stock_movement_is_deleted_before_its_opname(): void
    {
        $agent = $this->makeAgent();
        ['product' => $product] = $this->makeVariationProduct('OPORD');

        $opname = StockOpname::create([
            'agent_id' => $agent->id, 'opname_number' => 'OP-ORD', 'opname_type' => 'physical_opname',
            'status' => 'approved', 'created_by' => $agent->id,
        ]);
        StockOpnameItem::create(['stock_opname_id' => $opname->id, 'product_id' => $product->id, 'system_quantity' => 5, 'counted_quantity' => 5]);
        // The production shape (StockOpnameService): the adjustment movement
        // carries opname_id, so stock_movements.opname_id -> stock_opnames is a
        // RESTRICT edge that must be deleted child-first.
        $movement = StockMovement::create([
            'agent_id' => $agent->id, 'product_id' => $product->id,
            'type' => 'opname_adjustment', 'quantity' => 5,
            'reference_type' => StockOpname::class, 'reference_id' => $opname->id, 'opname_id' => $opname->id,
        ]);

        $this->force()->assertSuccessful();

        $this->assertDatabaseMissing('stock_movements', ['id' => $movement->id]);
        $this->assertDatabaseMissing('stock_opnames', ['id' => $opname->id]);
        $this->assertSame(0, DB::table('stock_opname_items')->count());
    }

    /* ─────── 7. transaction evidence files are removed ─────── */

    public function test_transaction_evidence_files_are_deleted_with_their_records(): void
    {
        $agent = $this->makeAgent();
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        ['product' => $product, 'variation' => $variation] = $this->makeVariationProduct('FILES');
        ['order' => $order] = $this->makeOrder($agent, $konsumen, 'ORD-FILES-1', $product, $variation);

        $shipment = Shipment::create(['order_id' => $order->id, 'status' => 'pending']);
        $payment = PaymentTransaction::create([
            'order_id' => $order->id, 'payment_method_id' => $order->payment_method_id,
            'type' => 'payment', 'amount' => 20000, 'status' => 'pending',
        ]);

        Storage::disk('public')->put('payments/bank-transfer-proofs/proof.jpg', 'bytes');
        $bankProof = BankTransferVerification::create([
            'payment_transaction_id' => $payment->id, 'proof_image_path' => 'payments/bank-transfer-proofs/proof.jpg',
            'bank_name' => 'BCA', 'account_name' => 'Customer', 'account_number' => '123',
        ]);
        Storage::disk('public')->put('media/shipment_proof/receipt.jpg', 'bytes');
        $shipmentMedia = Media::create([
            'disk' => 'public', 'path' => 'media/shipment_proof/receipt.jpg', 'collection' => 'shipment_proof',
            'original_filename' => 'receipt.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size' => 5,
            'mediable_type' => Shipment::class, 'mediable_id' => $shipment->id,
        ]);
        Storage::disk('public')->put('media/cod_payment_proof/cod.jpg', 'bytes');
        $codMedia = Media::create([
            'disk' => 'public', 'path' => 'media/cod_payment_proof/cod.jpg', 'collection' => 'cod_payment_proof',
            'original_filename' => 'cod.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size' => 5,
            'mediable_type' => PaymentTransaction::class, 'mediable_id' => $payment->id,
        ]);
        CodPaymentProof::create(['payment_transaction_id' => $payment->id, 'proof_media_id' => $codMedia->id, 'status' => 'pending']);
        $shipment->update(['proof_media_id' => $shipmentMedia->id]);

        $resetCode = Artisan::call('products:reset', ['--force' => true, '--backup-verified' => 'test-backup']);
        $this->assertSame(0, $resetCode, Artisan::output());

        $this->assertDatabaseMissing('bank_transfer_verifications', ['id' => $bankProof->id]);
        $this->assertDatabaseMissing('media', ['id' => $shipmentMedia->id]);
        $this->assertDatabaseMissing('media', ['id' => $codMedia->id]);
        $this->assertFalse(Storage::disk('public')->exists('payments/bank-transfer-proofs/proof.jpg'));
        $this->assertFalse(Storage::disk('public')->exists('media/shipment_proof/receipt.jpg'));
        $this->assertFalse(Storage::disk('public')->exists('media/cod_payment_proof/cod.jpg'));
    }

    /* ─────── 7b. shared physical evidence paths are preserved for retained owners ─────── */

    public function test_shared_evidence_path_used_by_a_retained_owner_is_kept(): void
    {
        $agent = $this->makeAgent();
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        $method = PaymentMethod::create(['code' => 'cod', 'name' => 'COD', 'type' => 'cod', 'is_active' => true]);
        ['product' => $product, 'variation' => $variation] = $this->makeVariationProduct('SHARE');

        // Deleted product order evidence.
        ['order' => $deletedOrder] = $this->makeOrder($agent, $konsumen, 'ORD-SHARE-D', $product, $variation);
        $deletedShipment = Shipment::create(['order_id' => $deletedOrder->id, 'status' => 'pending']);
        $deletedPayment = PaymentTransaction::create([
            'order_id' => $deletedOrder->id, 'payment_method_id' => $method->id,
            'type' => 'payment', 'amount' => 10000, 'status' => 'pending',
        ]);

        // The same physical file is referenced by a retained shipment proof.
        $sharedPath = 'media/shipment_proof/shared-receipt.jpg';
        Storage::disk('public')->put($sharedPath, 'shared-bytes');
        $deletedMedia = Media::create([
            'disk' => 'public', 'path' => $sharedPath, 'collection' => 'shipment_proof',
            'original_filename' => 'shared.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size' => 12,
            'mediable_type' => Shipment::class, 'mediable_id' => $deletedShipment->id,
        ]);

        $cleanOrder = Order::withoutGlobalScopes()->create([
            'order_no' => 'ORD-SHARE-K', 'konsumen_id' => $konsumen->id, 'agent_id' => $agent->id,
            'payment_method_id' => $method->id, 'status' => 'diterima', 'payment_status' => 'unpaid',
            'subtotal_amount' => 0, 'total_amount' => 0,
            'recipient_name_snapshot' => 'Customer', 'recipient_phone_snapshot' => '0800', 'address_snapshot' => 'Address',
        ]);
        $keptShipment = Shipment::create(['order_id' => $cleanOrder->id, 'status' => 'pending']);
        $retainedMedia = Media::create([
            'disk' => 'public', 'path' => $sharedPath, 'collection' => 'shipment_proof',
            'original_filename' => 'shared.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size' => 12,
            'mediable_type' => Shipment::class, 'mediable_id' => $keptShipment->id,
        ]);
        $keptShipment->update(['proof_media_id' => $retainedMedia->id]);

        Artisan::call('products:reset', ['--force' => true, '--backup-verified' => 'test-backup']);

        // The deleted media row may be removed, but the shared file and retained row
        // must remain and point to it.
        $this->assertDatabaseHas('media', ['id' => $retainedMedia->id]);
        $this->assertTrue(Storage::disk('public')->exists($sharedPath));
        $this->assertSame(1, DB::table('shipments')->count());
    }

    /* ─────── 7c. ambiguous shared evidence media fails closed before mutation ─────── */

    public function test_evidence_media_referenced_by_a_retained_shipment_is_refused(): void
    {
        $agent = $this->makeAgent();
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        $method = PaymentMethod::create(['code' => 'cod', 'name' => 'COD', 'type' => 'cod', 'is_active' => true]);
        ['product' => $product, 'variation' => $variation] = $this->makeVariationProduct('AMBIG');

        ['order' => $deletedOrder] = $this->makeOrder($agent, $konsumen, 'ORD-AMBIG-D', $product, $variation);
        $deletedShipment = Shipment::create(['order_id' => $deletedOrder->id, 'status' => 'pending']);

        Storage::disk('public')->put('media/shipment_proof/ambiguous.jpg', 'bytes');
        $sharedMedia = Media::create([
            'disk' => 'public', 'path' => 'media/shipment_proof/ambiguous.jpg', 'collection' => 'shipment_proof',
            'original_filename' => 'ambiguous.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size' => 9,
            'mediable_type' => Shipment::class, 'mediable_id' => $deletedShipment->id,
        ]);

        // A retained orderless order's shipment still points at the same media row
        // that is inside the reset's evidence deletion scope.
        $cleanOrder = Order::withoutGlobalScopes()->create([
            'order_no' => 'ORD-AMBIG-K', 'konsumen_id' => $konsumen->id, 'agent_id' => $agent->id,
            'payment_method_id' => $method->id, 'status' => 'diterima', 'payment_status' => 'unpaid',
            'subtotal_amount' => 0, 'total_amount' => 0,
            'recipient_name_snapshot' => 'Customer', 'recipient_phone_snapshot' => '0800', 'address_snapshot' => 'Address',
        ]);
        $keptShipment = Shipment::create(['order_id' => $cleanOrder->id, 'status' => 'pending']);
        $keptShipment->update(['proof_media_id' => $sharedMedia->id]);

        $before = $this->snapshotAll();
        $code = Artisan::call('products:reset', ['--force' => true, '--backup-verified' => 'test-backup']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('retained shipment', Artisan::output());
        $this->assertSame($before, $this->snapshotAll());
    }

    /* ─────── 8. webhook audit is row-scoped, independent history survives ─────── */

    public function test_webhook_logs_are_row_scoped_and_independent_history_survives(): void
    {
        $agent = $this->makeAgent();
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        $method = PaymentMethod::create(['code' => 'xendit', 'name' => 'Xendit', 'type' => 'gateway', 'is_active' => true]);
        ['product' => $product, 'variation' => $variation] = $this->makeVariationProduct('HOOK');
        ['order' => $productOrder] = $this->makeOrder($agent, $konsumen, 'ORD-HOOK-P', $product, $variation);

        // Payment of a product order: its webhook audit IS product history.
        $deletedPayment = PaymentTransaction::create([
            'order_id' => $productOrder->id, 'payment_method_id' => $method->id,
            'type' => 'payment', 'amount' => 20000, 'status' => 'paid', 'gateway_reference' => 'REF-DEL',
        ]);
        $logOfDeletedPayment = PaymentWebhookLog::create([
            'payment_method_id' => $method->id, 'event_id' => 'evt-del-1', 'gateway_reference' => 'REF-DEL',
            'headers' => ['x' => 'y'], 'payload' => ['id' => 'evt-del-1'], 'signature_valid' => true, 'processed' => true,
            'created_at' => now(),
        ]);

        // An order without product lines: its payment webhook audit is NOT
        // product history.
        $cleanOrder = Order::withoutGlobalScopes()->create([
            'order_no' => 'ORD-HOOK-CLEAN', 'konsumen_id' => $konsumen->id, 'agent_id' => $agent->id,
            'payment_method_id' => $method->id, 'status' => 'diterima', 'payment_status' => 'unpaid',
            'subtotal_amount' => 0, 'total_amount' => 0,
            'recipient_name_snapshot' => 'Customer', 'recipient_phone_snapshot' => '0800', 'address_snapshot' => 'Address',
        ]);
        PaymentTransaction::create([
            'order_id' => $cleanOrder->id, 'payment_method_id' => $method->id,
            'type' => 'payment', 'amount' => 0, 'status' => 'paid', 'gateway_reference' => 'REF-KEEP',
        ]);
        $logOfSurvivingPayment = PaymentWebhookLog::create([
            'payment_method_id' => $method->id, 'event_id' => 'evt-keep-1', 'gateway_reference' => 'REF-KEEP',
            'headers' => ['x' => 'y'], 'payload' => ['id' => 'evt-keep-1'], 'signature_valid' => true, 'processed' => true,
            'created_at' => now(),
        ]);

        // handleWebhook() logs deliveries whose reference matches nothing at
        // all — spoofed/attack traffic. No order, no product, no link.
        $unknownReference = PaymentWebhookLog::create([
            'payment_method_id' => $method->id, 'event_id' => 'unknown-1', 'gateway_reference' => 'REF-UNKNOWN',
            'headers' => ['x' => 'y'], 'payload' => ['id' => 'unknown-1'], 'signature_valid' => false, 'processed' => false,
            'note' => 'No matching transaction for this reference.', 'created_at' => now(),
        ]);

        $this->assertSame(3, DB::table('payment_webhook_logs')->count());

        $this->force()->assertSuccessful();

        $this->assertDatabaseMissing('payment_webhook_logs', ['id' => $logOfDeletedPayment->id]);
        $this->assertDatabaseHas('payment_webhook_logs', ['id' => $logOfSurvivingPayment->id]);
        $this->assertDatabaseHas('payment_webhook_logs', ['id' => $unknownReference->id]);
        $this->assertSame(2, DB::table('payment_webhook_logs')->count());
        $this->assertDatabaseMissing('payment_transactions', ['id' => $deletedPayment->id]);
        $this->assertDatabaseHas('orders', ['id' => $cleanOrder->id]);
    }

    /* ─────── 9. an unmapped dependency refuses the run ─────── */

    public function test_unmapped_product_dependency_refuses_the_run(): void
    {
        Schema::create('reset_probe_references', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
        });
        DB::table('reset_probe_references')->insert(['product_id' => null]);

        try {
            $agent = $this->makeAgent();
            $this->makeSimpleProduct('PROBE-REF-001');
            $before = $this->snapshotAll();

            $code = Artisan::call('products:reset', ['--force' => true, '--backup-verified' => 'x.sql']);
            $output = Artisan::output();

            $this->assertSame(1, $code);
            $this->assertStringContainsString('UNMAPPED DEPENDENCY', $output);
            $this->assertStringContainsString('reset_probe_references', $output);
            $this->assertSame($before, $this->snapshotAll());
        } finally {
            Schema::dropIfExists('reset_probe_references');
        }
    }

    /* ─────── 10. a preserved row inside a delete slice is unmapped ─────── */

    public function test_preserved_row_referencing_a_delete_slice_is_refused(): void
    {
        $agent = $this->makeAgent();
        ['product' => $product] = $this->makeVariationProduct('SLICE');
        $other = $this->makeAgent();

        // A user avatar that happens to be a product-owned media row. The
        // media DELETE scope would take this row and SET NULL the avatar —
        // a preserved row rewritten by a run that never reported it.
        $media = Media::create([
            'disk' => 'public', 'path' => 'media/avatars/product.png', 'collection' => 'avatar',
            'original_filename' => 'avatar.png', 'mime_type' => 'image/png', 'extension' => 'png',
            'size' => 4, 'mediable_type' => Product::class, 'mediable_id' => $product->id,
        ]);
        $other->forceFill(['avatar_media_id' => $media->id])->save();

        $before = $this->snapshotAll();

        $code = Artisan::call('products:reset', ['--force' => true, '--backup-verified' => 'x.sql']);
        $output = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('UNMAPPED DEPENDENCY', $output);
        $this->assertStringContainsString('users.avatar_media_id', $output);
        $this->assertSame($before, $this->snapshotAll());
        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    /* ─────── 10b. a retained order pointing at a deleted targeted voucher is refused ─────── */

    public function test_retained_order_referencing_a_deleted_targeted_voucher_is_refused(): void
    {
        $agent = $this->makeAgent();
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        $method = PaymentMethod::create(['code' => 'cod', 'name' => 'COD', 'type' => 'cod', 'is_active' => true]);
        ['product' => $product, 'variation' => $variation] = $this->makeVariationProduct('VOUCH');

        // A product-targeted voucher the reset deletes.
        $voucher = Voucher::create(['agent_id' => $agent->id, 'code' => 'VAR-REV', 'name' => 'Var voucher', 'type' => 'percentage', 'value' => 5, 'product_variation_id' => $variation->id]);

        // An order WITHOUT product lines — the reset keeps it. Its voucher_id
        // points at the targeted voucher being deleted: the attribution would
        // dangle, so the run must refuse instead of orphan-keeping history.
        $cleanOrder = Order::withoutGlobalScopes()->create([
            'order_no' => 'ORD-VOUCH-K', 'konsumen_id' => $konsumen->id, 'agent_id' => $agent->id,
            'payment_method_id' => $method->id, 'status' => 'diterima', 'payment_status' => 'unpaid',
            'subtotal_amount' => 0, 'total_amount' => 0, 'voucher_id' => $voucher->id,
            'recipient_name_snapshot' => 'Customer', 'recipient_phone_snapshot' => '0800', 'address_snapshot' => 'Address',
        ]);

        $before = $this->snapshotAll();
        $code = Artisan::call('products:reset', ['--force' => true, '--backup-verified' => 'x.sql']);
        $output = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('UNMAPPED DEPENDENCY', $output);
        $this->assertStringContainsString('orders.voucher_id', $output);
        $this->assertSame($before, $this->snapshotAll());
        $this->assertDatabaseHas('orders', ['id' => $cleanOrder->id]);
        $this->assertDatabaseHas('vouchers', ['id' => $voucher->id]);
    }

    /* ─────── 11. rollback on an unexpected failure ─────── */

    public function test_unexpected_db_error_rolls_back_everything(): void
    {
        $agent = $this->makeAgent();
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        ['product' => $product, 'variation' => $variation] = $this->makeVariationProduct('ROLLBACK');
        $this->makeOrder($agent, $konsumen, 'ORD-ROLLBACK-1', $product, $variation);
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 1, 'quantity_reserved' => 0]);

        $before = $this->snapshotAll();

        // The delete scope runs as plain statements inside one transaction, so
        // the failure is injected on the products DELETE itself — the last and
        // most destructive statement in the plan.
        $armed = true;
        DB::connection()->listen(function ($query) use (&$armed): void {
            if ($armed && preg_match('/^delete from [`"]?products[`"]?$/i', trim($query->sql)) === 1) {
                throw new \RuntimeException('Simulated failure while removing the product roots.');
            }
        });

        try {
            $this->force()->assertFailed();
        } finally {
            $armed = false;
        }

        $this->assertSame($before, $this->snapshotAll());
        $this->assertNotNull(Product::withTrashed()->find($product->id));
        $this->assertSame(1, DB::table('order_items')->count());
        $this->assertSame(1, DB::table('product_stocks')->count());
    }

    /* ─────── 12. empty catalog and repeated runs are safe ─────── */

    public function test_empty_catalog_and_repeat_runs_are_safe(): void
    {
        $agent = $this->makeAgent();

        $this->artisan('products:reset')->assertSuccessful();
        $this->force()->assertSuccessful();

        $this->assertSame(0, Product::withTrashed()->count());
        $this->assertSame(0, ProductVariation::withTrashed()->count());

        $before = $this->snapshotAll();
        $this->force()->assertSuccessful();
        $this->assertSame($before, $this->snapshotAll());
        $this->assertNotNull($agent->fresh());
    }

    /* ─────── 13. unsafe storage paths are never deleted ─────── */

    public function test_unsafe_file_paths_are_never_deleted(): void
    {
        $agent = $this->makeAgent();
        $product = $this->makeSimpleProduct('UNSAFE-001');
        ProductImage::create(['product_id' => $product->id, 'path' => '../escape-attempt.jpg', 'is_primary' => true]);
        Storage::disk('public')->put('products/canary.jpg', 'canary');

        $code = Artisan::call('products:reset', ['--force' => true, '--backup-verified' => 'x.sql']);
        $output = Artisan::output();

        // Fail closed on the ambiguous path, but the database reset is intact
        // and no unrelated file is touched.
        $this->assertSame(1, $code);
        $this->assertStringContainsString('skipped-unsafe', $output);
        $this->assertSame(0, Product::withTrashed()->count());
        $this->assertSame(0, DB::table('product_images')->count());
        $this->assertTrue(Storage::disk('public')->exists('products/canary.jpg'));
    }

    /* ─────── 14. no forbidden mechanism, dry-run by default ─────── */

    public function test_no_forbidden_mechanisms_and_dry_run_default(): void
    {
        $source = (string) file_get_contents(app_path('Console/Commands/ResetProducts.php'));

        foreach ([
            'FOREIGN_KEY_CHECKS',
            'foreign_key_checks',
            'TRUNCATE',
            'truncate(',
            'disableForeignKeyConstraints',
            'enableForeignKeyConstraints',
            'migrate:fresh',
            'migrate:refresh',
            'migrate:reset',
            'db:wipe',
            'DROP TABLE',
            'Schema::drop',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "Forbidden mechanism present: {$forbidden}");
        }

        $this->assertStringContainsString('{--force', $source);
        $this->assertStringContainsString('{--backup-verified=', $source);
        $this->assertStringNotContainsString('migrate:fresh', $source);
    }

    /* ─────── 15. cart/wishlist have no backend tables ─────── */

    public function test_cart_and_wishlist_have_no_backend_tables(): void
    {
        foreach (['carts', 'cart_items', 'wishlists', 'wishlist_items'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
    }
}
