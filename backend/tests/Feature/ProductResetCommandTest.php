<?php

namespace Tests\Feature;

use App\Models\CmsPage;
use App\Models\Language;
use App\Models\Media;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
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
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Voucher;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Product + Variation domain reset (products:reset).
 *
 * Covers the 13 required behaviours: dry-run purity, idempotency, full
 * removal of owned children (products, variations, stocks, media),
 * survival of unrelated data, order/history blocking, rollback on
 * unexpected failure, repeat safety, and absence of forbidden mechanisms.
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

    private function makeAgent(): User
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);

        return $agen->fresh();
    }

    private function makeSimpleProduct(string $sku = 'SIMPLE-001'): Product
    {
        return Product::create([
            'name' => 'Simple '.$sku, 'slug' => 'simple-'.strtolower($sku), 'sku' => $sku,
            'has_variations' => false, 'base_price' => 10000, 'weight_grams' => 100, 'status' => 'active',
        ]);
    }

    /** Product with a full variation graph (attributes/options/compositions). */
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

        return compact('product', 'attribute', 'option', 'variation');
    }

    /** Snapshot every table the reset may read or write. */
    private function snapshotAll(): array
    {
        $tables = [
            'products', 'product_variations', 'product_attributes',
            'product_variation_attributes', 'product_variation_attribute_options',
            'product_variation_attribute_options_translations',
            'product_variation_attributes_translations',
            'product_variation_compositions', 'products_translations',
            'product_images', 'product_fees', 'product_variation_fees',
            'catalog_skus', 'product_stocks', 'product_variation_stocks',
            'warehouse_stocks', 'product_discounts', 'vouchers', 'media',
            'users', 'roles', 'product_categories', 'orders', 'order_items',
            'shipments', 'payment_transactions', 'payment_methods', 'commissions', 'settings',
            'stock_movements', 'activity_logs', 'warehouse_settings',
            'warehouse_sub_locations', 'languages',
        ];

        $counts = [];
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }

        return $counts;
    }

    /* TEST 1 — dry-run performs zero mutations. */

    public function test_dry_run_performs_zero_mutations(): void
    {
        $agent = $this->makeAgent();
        $product = $this->makeSimpleProduct();
        ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'agent', 'amount' => 500]);
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 5, 'quantity_reserved' => 0]);

        $before = $this->snapshotAll();

        $this->artisan('products:reset')->assertSuccessful();
        $this->assertSame($before, $this->snapshotAll());
    }

    /* TEST 2 — empty product catalog is safe/idempotent. */

    public function test_empty_catalog_is_safe_and_idempotent(): void
    {
        $agent = $this->makeAgent();

        $this->artisan('products:reset')->assertSuccessful();

        $this->artisan('products:reset', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, Product::withTrashed()->count());
        $this->assertSame(0, ProductVariation::withTrashed()->count());
        $this->assertNotNull($agent->fresh());
    }

    /* TEST 3 — product with normal owned children is completely removed. */

    public function test_product_with_owned_children_is_completely_removed(): void
    {
        $agent = $this->makeAgent();
        $language = Language::create(['code' => 'id', 'name' => 'Indonesian', 'is_default' => true]);
        $product = $this->makeSimpleProduct('FULL-001');

        ProductAttribute::create(['product_id' => $product->id, 'attribute_name' => 'Rasa', 'attribute_value' => 'Coklat']);
        DB::table('products_translations')->insert(['product_id' => $product->id, 'language_id' => $language->id, 'name' => 'Kue', 'description' => 'Enak']);
        ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'agent', 'amount' => 1000]);
        ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'sales', 'amount' => 500]);
        Storage::disk('public')->put('products/full-001.jpg', 'fake-bytes');
        ProductImage::create(['product_id' => $product->id, 'path' => 'products/full-001.jpg', 'is_primary' => true]);
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 7, 'quantity_reserved' => 0]);
        ProductDiscount::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'name' => 'Promo', 'percentage' => 10]);

        $this->assertSame(1, DB::table('catalog_skus')->where('owner_type', 'product')->count());

        $this->artisan('products:reset', ['--force' => true])->assertSuccessful();
        $this->assertSame(0, Product::withTrashed()->count());
        $this->assertSame(0, DB::table('product_attributes')->count());
        $this->assertSame(0, DB::table('products_translations')->count());
        $this->assertSame(0, DB::table('product_images')->count());
        $this->assertSame(0, DB::table('product_fees')->count());
        $this->assertSame(0, DB::table('product_stocks')->count());
        $this->assertSame(0, DB::table('product_discounts')->count());
        $this->assertSame(0, DB::table('catalog_skus')->count());
        $this->assertFalse(Storage::disk('public')->exists('products/full-001.jpg'));
        $this->assertNotNull($agent->fresh());
    }

    /* TEST 4 — variations and all variation-owned children are removed. */

    public function test_variations_and_variation_children_are_removed(): void
    {
        $agent = $this->makeAgent();
        $language = Language::create(['code' => 'id', 'name' => 'Indonesian', 'is_default' => true]);
        ['product' => $product, 'attribute' => $attribute, 'option' => $option, 'variation' => $variation] = $this->makeVariationProduct('T4');

        DB::table('product_variation_attributes_translations')->insert([
            'product_variation_attribute_id' => $attribute->id, 'language_id' => $language->id,
            'name' => 'Ukuran', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_variation_attribute_options_translations')->insert([
            'product_variation_attribute_option_id' => $option->id, 'language_id' => $language->id,
            'value' => 'Besar', 'created_at' => now(), 'updated_at' => now(),
        ]);
        ProductVariationFee::create(['product_variation_id' => $variation->id, 'beneficiary_role' => 'agent', 'amount' => 800]);
        ProductVariationStock::create(['agent_id' => $agent->id, 'product_variation_id' => $variation->id, 'quantity_on_hand' => 3, 'quantity_reserved' => 0]);
        ProductDiscount::create(['agent_id' => $agent->id, 'product_variation_id' => $variation->id, 'name' => 'Var Promo', 'percentage' => 5]);
        Voucher::create(['agent_id' => $agent->id, 'code' => 'VAR-T4', 'name' => 'Var voucher', 'type' => 'percentage', 'value' => 5, 'product_variation_id' => $variation->id]);
        Voucher::create(['agent_id' => $agent->id, 'code' => 'GLOBAL-T4', 'name' => 'Whole catalog', 'type' => 'fixed', 'value' => 1000]);

        $this->artisan('products:reset', ['--force' => true])->assertSuccessful();
        $this->assertSame(0, Product::withTrashed()->count());
        $this->assertSame(0, ProductVariation::withTrashed()->count());
        $this->assertSame(0, DB::table('product_variation_attributes')->count());
        $this->assertSame(0, DB::table('product_variation_attribute_options')->count());
        $this->assertSame(0, DB::table('product_variation_compositions')->count());
        $this->assertSame(0, DB::table('product_variation_attributes_translations')->count());
        $this->assertSame(0, DB::table('product_variation_attribute_options_translations')->count());
        $this->assertSame(0, DB::table('product_variation_fees')->count());
        $this->assertSame(0, DB::table('product_variation_stocks')->count());
        $this->assertSame(0, DB::table('product_discounts')->count());
        // The variation-targeted voucher goes; the whole-catalog voucher stays.
        $this->assertSame(1, DB::table('vouchers')->count());
        $this->assertDatabaseHas('vouchers', ['code' => 'GLOBAL-T4']);
    }

    /* TEST 5 — product/current catalog stock is removed correctly. */

    public function test_product_stock_current_state_is_removed(): void
    {
        $agent = $this->makeAgent();
        $location = WarehouseSubLocation::create([
            'agent_id' => $agent->id, 'code' => 'SUB-1', 'name' => 'Sub 1', 'is_active' => true, 'created_by' => $agent->id,
        ]);
        $product = $this->makeSimpleProduct('STOCK-001');
        ['variation' => $variation] = $this->makeVariationProduct('ST');

        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);
        ProductVariationStock::create(['agent_id' => $agent->id, 'product_variation_id' => $variation->id, 'quantity_on_hand' => 4, 'quantity_reserved' => 0]);
        WarehouseStock::withoutGlobalScopes()->create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 10]);
        WarehouseStock::withoutGlobalScopes()->create(['agent_id' => $agent->id, 'product_variation_id' => $variation->id, 'stock_type' => 'transit', 'quantity' => 4]);
        WarehouseStock::withoutGlobalScopes()->create(['agent_id' => $agent->id, 'product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $location->id, 'quantity' => 2]);

        $this->artisan('products:reset', ['--force' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('product_stocks')->count());
        $this->assertSame(0, DB::table('product_variation_stocks')->count());
        $this->assertSame(0, DB::table('warehouse_stocks')->count());
        $this->assertSame(0, Product::withTrashed()->count());
        $this->assertSame(0, ProductVariation::withTrashed()->count());
        // Stock containers survive; only product-owned rows go.
        $this->assertNotNull($location->fresh());
        $this->assertNotNull($agent->fresh());
    }

    /* TEST 6 — cart/wishlist have no backend tables; users survive. */

    public function test_cart_wishlist_have_no_backend_tables_and_users_survive(): void
    {
        // Cart and wishlist are frontend-only Pinia/localStorage stores
        // (pc-cart / pc-wishlist); the backend must own no such tables.
        $this->assertFalse(Schema::hasTable('cart_items'));
        $this->assertFalse(Schema::hasTable('carts'));
        $this->assertFalse(Schema::hasTable('wishlist_items'));
        $this->assertFalse(Schema::hasTable('wishlists'));

        $agent = $this->makeAgent();
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        $this->makeSimpleProduct('CART-001');

        $this->artisan('products:reset', ['--force' => true])->assertSuccessful();
        $this->assertNotNull($agent->fresh());
        $this->assertNotNull($konsumen->fresh());
    }

    /* TEST 7 — product-owned media DB relationships are safely handled. */

    public function test_product_media_relationships_are_handled(): void
    {
        $product = $this->makeSimpleProduct('MEDIA-001');
        Storage::disk('public')->put('products/media-001.jpg', 'product-bytes');
        ProductImage::create(['product_id' => $product->id, 'path' => 'products/media-001.jpg', 'is_primary' => true]);

        Storage::disk('public')->put('media/product-morph.jpg', 'morph-bytes');
        $productMedia = Media::create([
            'disk' => 'public', 'path' => 'media/product-morph.jpg', 'collection' => 'product_image',
            'original_filename' => 'morph.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg',
            'size' => 11, 'mediable_type' => Product::class, 'mediable_id' => $product->id,
        ]);

        // Unrelated media must survive with its bytes intact.
        Storage::disk('public')->put('media/cms-keep.jpg', 'cms-bytes');
        $cmsMedia = Media::create([
            'disk' => 'public', 'path' => 'media/cms-keep.jpg', 'collection' => 'cms_content',
            'original_filename' => 'cms.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg',
            'size' => 9, 'mediable_type' => CmsPage::class, 'mediable_id' => 999999,
        ]);

        $this->artisan('products:reset', ['--force' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('product_images')->count());
        $this->assertDatabaseMissing('media', ['id' => $productMedia->id]);
        $this->assertFalse(Storage::disk('public')->exists('products/media-001.jpg'));
        $this->assertFalse(Storage::disk('public')->exists('media/product-morph.jpg'));
        $this->assertDatabaseHas('media', ['id' => $cmsMedia->id]);
        $this->assertTrue(Storage::disk('public')->exists('media/cms-keep.jpg'));
    }

    /* TEST 8 — representative unrelated data survives. */

    public function test_unrelated_data_survives(): void
    {
        $agent = $this->makeAgent();
        User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        User::factory()->admin()->create(['agent_id' => $agent->id]);

        $category = ProductCategory::create(['name' => 'Kue', 'slug' => 'kue', 'is_active' => true]);
        $method = PaymentMethod::create(['code' => 'cod', 'name' => 'COD', 'type' => 'cod', 'is_active' => true]);
        Setting::create(['key' => 'site_name', 'value' => 'PrimeClassy', 'group' => 'website', 'is_public' => true]);
        WarehouseSetting::create(['agent_id' => $agent->id, 'factory_plan_enabled' => false]);
        WarehouseSubLocation::create([
            'agent_id' => $agent->id, 'code' => 'SUB-KEEP', 'name' => 'Keep', 'is_active' => true, 'created_by' => $agent->id,
        ]);
        Language::create(['code' => 'en', 'name' => 'English', 'is_default' => true]);
        $this->makeSimpleProduct('UNREL-001');

        $before = $this->snapshotAll();

        $this->artisan('products:reset', ['--force' => true])->assertSuccessful();
        foreach (['users', 'roles', 'product_categories', 'payment_methods', 'settings', 'warehouse_settings', 'warehouse_sub_locations', 'languages'] as $table) {
            $this->assertSame($before[$table], DB::table($table)->count(), "Preserved table {$table} changed");
        }
        $this->assertDatabaseHas('product_categories', ['id' => $category->id]);
        $this->assertDatabaseHas('payment_methods', ['id' => $method->id]);
        $this->assertSame(0, Product::withTrashed()->count());
    }

    /* TEST 9 — a preserved order referencing a product blocks, never deletes history. */

    public function test_order_referencing_product_blocks_instead_of_deleting_history(): void
    {
        $agent = $this->makeAgent();
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        $product = $this->makeSimpleProduct('ORDER-001');
        $method = PaymentMethod::create(['code' => 'cod', 'name' => 'COD', 'type' => 'cod', 'is_active' => true]);

        $order = Order::withoutGlobalScopes()->create([
            'order_no' => 'ORD-BLOCK-1', 'konsumen_id' => $konsumen->id, 'agent_id' => $agent->id,
            'payment_method_id' => $method->id, 'status' => 'diterima', 'payment_status' => 'unpaid',
            'subtotal_amount' => 10000, 'total_amount' => 10000,
            'recipient_name_snapshot' => 'Customer', 'recipient_phone_snapshot' => '0800', 'address_snapshot' => 'Address',
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku,
            'unit_price_snapshot' => 10000, 'subtotal_snapshot' => 10000,
            'original_quantity' => 1, 'fulfilled_quantity' => 1, 'status' => 'diterima',
        ]);
        StockMovement::create([
            'agent_id' => $agent->id, 'product_id' => $product->id,
            'type' => 'reserve', 'quantity' => 1, 'reference_type' => 'order', 'reference_id' => $order->id,
        ]);

        $this->artisan('products:reset')->assertFailed();

        $this->artisan('products:reset', ['--force' => true])->assertFailed();

        // The transaction is untouchable: order, item, snapshots and movement survive.
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'order_no' => 'ORD-BLOCK-1']);
        $this->assertDatabaseHas('order_items', ['id' => $item->id, 'product_name_snapshot' => $product->name]);
        $this->assertSame(1, DB::table('stock_movements')->where('product_id', $product->id)->count());
        $this->assertNotNull(Product::withTrashed()->find($product->id));
    }

    /* TEST 10 — blocked execution makes zero mutations. */

    public function test_blocked_execution_makes_zero_mutations(): void
    {
        $agent = $this->makeAgent();
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        $product = $this->makeSimpleProduct('BLOCK-002');
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 2, 'quantity_reserved' => 0]);
        $method = PaymentMethod::create(['code' => 'cod', 'name' => 'COD', 'type' => 'cod', 'is_active' => true]);
        $order = Order::withoutGlobalScopes()->create([
            'order_no' => 'ORD-BLOCK-2', 'konsumen_id' => $konsumen->id, 'agent_id' => $agent->id,
            'payment_method_id' => $method->id, 'status' => 'diterima', 'payment_status' => 'unpaid',
            'subtotal_amount' => 10000, 'total_amount' => 10000,
            'recipient_name_snapshot' => 'Customer', 'recipient_phone_snapshot' => '0800', 'address_snapshot' => 'Address',
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku,
            'unit_price_snapshot' => 10000, 'subtotal_snapshot' => 10000,
            'original_quantity' => 1, 'fulfilled_quantity' => 1, 'status' => 'diterima',
        ]);

        $before = $this->snapshotAll();

        $this->artisan('products:reset', ['--force' => true])->assertFailed();
        $this->assertSame($before, $this->snapshotAll());
    }

    /* TEST 11 — an unexpected DB failure rolls the whole operation back. */

    public function test_unexpected_db_error_rolls_back(): void
    {
        $agent = $this->makeAgent();
        $product = $this->makeSimpleProduct('ROLLBACK-001');
        ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'agent', 'amount' => 100]);
        ProductStock::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'quantity_on_hand' => 1, 'quantity_reserved' => 0]);

        Product::deleting(function (): void {
            throw new \RuntimeException('Simulated late failure during product removal.');
        });

        try {
            $this->artisan('products:reset', ['--force' => true])->assertFailed();
        } finally {
            // Restore model boot state so later tests in this process keep
            // SoftDeletes and trait hooks intact.
            Product::flushEventListeners();
            Product::clearBootedModels();
        }

        $this->assertNotNull(Product::withTrashed()->find($product->id));
        $this->assertSame(1, DB::table('product_fees')->where('product_id', $product->id)->count());
        $this->assertSame(1, DB::table('product_stocks')->where('product_id', $product->id)->count());
        $this->assertSame(1, DB::table('catalog_skus')->where('owner_type', 'product')->count());
    }

    /* TEST 12 — a second reset after a successful reset is safe. */

    public function test_second_reset_after_success_is_safe(): void
    {
        $this->makeAgent();
        $this->makeSimpleProduct('REPEAT-001');

        $this->artisan('products:reset', ['--force' => true])->assertSuccessful();
        $this->assertSame(0, Product::withTrashed()->count());

        $before = $this->snapshotAll();
        $this->artisan('products:reset', ['--force' => true])->assertSuccessful();
        $this->assertSame($before, $this->snapshotAll());
    }

    /* TEST 14 — unsafe file paths are never deleted; verification fails closed. */

    public function test_unsafe_file_paths_are_never_deleted(): void
    {
        $this->makeAgent();
        $product = $this->makeSimpleProduct('UNSAFE-001');
        ProductImage::create(['product_id' => $product->id, 'path' => '../escape-attempt.jpg', 'is_primary' => true]);
        Storage::disk('public')->put('products/canary.jpg', 'canary');

        $code = Artisan::call('products:reset', ['--force' => true]);
        $output = Artisan::output();

        // Fail-closed: the command refuses success while an unsafe path is
        // unresolved, but the database reset itself is intact and no
        // unrelated file is touched.
        $this->assertSame(1, $code);
        $this->assertStringContainsString('skipped-unsafe', $output);
        $this->assertSame(0, Product::withTrashed()->count());
        $this->assertSame(0, DB::table('product_images')->count());
        $this->assertTrue(Storage::disk('public')->exists('products/canary.jpg'));
    }

    /* TEST 13 — no forbidden mechanism exists in the implementation. */

    public function test_no_forbidden_mechanisms_exist(): void
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

        // Default invocation must be the safe dry-run (destructive work needs --force).
        $this->assertStringContainsString('{--force', $source);
    }
}
