<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariation;
use App\Models\ProductVariationStock;
use App\Models\ReturnItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Services\Stock\SellableStockService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

class WarehouseReturnDispositionTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        Storage::fake('public');
    }

    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Toko QA', 'address' => 'Jl. QA', 'latitude' => -6.2, 'longitude' => 106.8166]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        $foreign = User::factory()->agen()->create();
        $foreign->update(['agent_id' => $foreign->id]);
        $foreignAdmin = User::factory()->admin()->create(['agent_id' => $foreign->id]);
        $foreignGudang = User::factory()->gudang()->create(['agent_id' => $foreign->id, 'parent_id' => $foreign->id]);

        return compact('agen', 'admin', 'gudang', 'konsumen', 'foreign', 'foreignAdmin', 'foreignGudang');
    }

    private function delivered(array $b, int $qty = 3, int $transit = 10, int $reserved = 0): array
    {
        $product = Product::create(['sku' => 'RET-'.Str::uuid(), 'name' => 'Returnable Cake', 'slug' => 'returnable-'.uniqid(), 'has_variations' => false, 'base_price' => 10000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $b['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $b['agen']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => $transit]);
        WarehouseSetting::create(['agent_id' => $b['agen']->id, 'factory_plan_enabled' => false]);

        $orderId = $this->actingAs($b['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => $qty]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ])->json('data.id');
        $order = Order::withoutGlobalScopes()->findOrFail($orderId);
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'dikirim'])->assertOk();
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'terkirim'])->assertOk();
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();

        $returnId = $this->actingAs($b['konsumen'])->post("/api/v1/orders/{$order->id}/returns", [
            'reason' => 'Salah kirim', 'items' => [['order_item_id' => $item->id, 'quantity' => $qty]],
            'evidence' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertCreated()->json('data.id');

        return compact('product', 'order', 'item', 'returnId');
    }

    private function bucket(int $agentId, int $productId, string $type, ?int $variationId = null): int
    {
        return (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $agentId)->where('stock_type', $type)
            ->when($variationId, fn ($q) => $q->where('product_variation_id', $variationId))
            ->when(! $variationId, fn ($q) => $q->where('product_id', $productId))->sum('quantity');
    }

    private function returnMovements(): int
    {
        return (int) StockMovement::query()->where('type', 'return_restock')->count();
    }

    public function test_initial_accept_and_good_inspection_have_zero_inventory_effect(): void
    {
        $b = $this->branch();
        $f = $this->delivered($b);
        $before = app(SellableStockService::class)->forProduct($b['agen']->id, $f['product']->id)['available'];

        $this->actingAs($b['admin'])->patchJson("/api/v1/admin/returns/{$f['returnId']}/review", ['approved' => true])->assertOk();
        $this->assertSame(10, $this->bucket($b['agen']->id, $f['product']->id, 'transit'));
        $this->assertSame(0, $this->returnMovements());

        $returnItem = ReturnItem::where('order_item_id', $f['item']->id)->firstOrFail();
        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/returns/{$returnItem->id}/inspect", [
            'received_quantity' => 3, 'good_quantity' => 3, 'damaged_quantity' => 0,
        ])->assertOk()->assertJsonPath('data.disposition_status', 'pending_disposition');

        $returnItem->refresh();
        $this->assertNotNull($returnItem->inspected_at);
        $this->assertNull($returnItem->restock_processed_at);

        $this->assertSame(10, $this->bucket($b['agen']->id, $f['product']->id, 'transit'));
        $this->assertSame($before, app(SellableStockService::class)->forProduct($b['agen']->id, $f['product']->id)['available']);
        $this->assertSame(0, $this->returnMovements());
    }

    public function test_damaged_inspection_has_zero_effect_and_finalizes_without_restock(): void
    {
        $b = $this->branch();
        $f = $this->delivered($b);
        $before = app(SellableStockService::class)->forProduct($b['agen']->id, $f['product']->id)['available'];

        $this->actingAs($b['admin'])->patchJson("/api/v1/admin/returns/{$f['returnId']}/review", ['approved' => true])->assertOk();
        $returnItem = ReturnItem::where('order_item_id', $f['item']->id)->firstOrFail();
        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/returns/{$returnItem->id}/inspect", [
            'received_quantity' => 3, 'good_quantity' => 0, 'damaged_quantity' => 3,
        ])->assertOk();

        $returnItem->refresh();
        $this->assertNotNull($returnItem->inspected_at);
        $this->assertNull($returnItem->restock_processed_at);
        $this->assertSame('pending_disposition', $returnItem->disposition_status);

        $this->assertSame(10, $this->bucket($b['agen']->id, $f['product']->id, 'transit'));
        $this->assertSame(0, $this->returnMovements());

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/returns/{$returnItem->id}/finalize")
            ->assertOk()->assertJsonPath('data.disposition_status', 'damaged_confirmed');

        $returnItem->refresh();
        $this->assertNull($returnItem->restock_processed_at);
        $this->assertSame(10, $this->bucket($b['agen']->id, $f['product']->id, 'transit'));
        $this->assertSame($before, app(SellableStockService::class)->forProduct($b['agen']->id, $f['product']->id)['available']);
        $this->assertSame(0, $this->returnMovements());
    }

    public function test_good_final_approval_restocks_transit_exactly_once(): void
    {
        $b = $this->branch();
        $f = $this->delivered($b);
        $reserved = (int) ProductStock::withoutGlobalScopes()->where('agent_id', $b['agen']->id)->value('quantity_reserved');
        $before = app(SellableStockService::class)->forProduct($b['agen']->id, $f['product']->id)['available'];

        $this->actingAs($b['admin'])->patchJson("/api/v1/admin/returns/{$f['returnId']}/review", ['approved' => true])->assertOk();
        $returnItem = ReturnItem::where('order_item_id', $f['item']->id)->firstOrFail();
        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/returns/{$returnItem->id}/inspect", [
            'received_quantity' => 3, 'good_quantity' => 3, 'damaged_quantity' => 0,
        ])->assertOk();

        $returnItem->refresh();
        $this->assertNull($returnItem->restock_processed_at);

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/returns/{$returnItem->id}/finalize")
            ->assertOk()->assertJsonPath('data.disposition_status', 'restocked');

        $returnItem->refresh();
        $this->assertNotNull($returnItem->restock_processed_at);
        $this->assertSame(13, $this->bucket($b['agen']->id, $f['product']->id, 'transit'));
        $this->assertSame(0, $this->bucket($b['agen']->id, $f['product']->id, 'shipping'));
        $this->assertSame($reserved, (int) ProductStock::withoutGlobalScopes()->where('agent_id', $b['agen']->id)->value('quantity_reserved'));
        $this->assertSame($before + 3, app(SellableStockService::class)->forProduct($b['agen']->id, $f['product']->id)['available']);
        $this->assertSame(1, $this->returnMovements());
        $this->assertDatabaseHas('stock_movements', ['agent_id' => $b['agen']->id, 'product_id' => $f['product']->id, 'type' => 'return_restock', 'stock_type' => 'transit', 'quantity' => 3]);

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/returns/{$returnItem->id}/finalize")->assertOk();
        $this->assertSame(13, $this->bucket($b['agen']->id, $f['product']->id, 'transit'));
        $this->assertSame(1, $this->returnMovements());
    }

    public function test_authorization_and_cross_agent_blocked(): void
    {
        $b = $this->branch();
        $f = $this->delivered($b);

        $this->actingAs($b['admin'])->patchJson("/api/v1/admin/returns/{$f['returnId']}/review", ['approved' => true])->assertOk();
        $returnItem = ReturnItem::where('order_item_id', $f['item']->id)->firstOrFail();

        $this->actingAs($b['foreignGudang'])->postJson("/api/v1/warehouse/returns/{$returnItem->id}/inspect", [
            'received_quantity' => 1, 'good_quantity' => 1, 'damaged_quantity' => 0,
        ])->assertStatus(403);
        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/returns/{$returnItem->id}/finalize")->assertForbidden();
        $this->actingAs($b['foreignAdmin'])->postJson("/api/v1/warehouse/returns/{$returnItem->id}/finalize")->assertStatus(403);
        $superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($superAdmin)->postJson("/api/v1/warehouse/returns/{$returnItem->id}/finalize")->assertForbidden();
        $agenAdmin = User::factory()->admin()->create(['agent_id' => User::factory()->agen()->create()->id]);
        $this->actingAs($agenAdmin)->postJson("/api/v1/warehouse/returns/{$returnItem->id}/finalize")->assertStatus(403);

        $this->assertSame(0, $this->returnMovements());
        $this->assertSame(10, $this->bucket($b['agen']->id, $f['product']->id, 'transit'));
    }

    public function test_initial_reject_blocks_inspection_and_variation_isolation(): void
    {
        $b = $this->branch();
        $f = $this->delivered($b);

        $this->actingAs($b['admin'])->patchJson("/api/v1/admin/returns/{$f['returnId']}/review", ['approved' => false])->assertOk();
        $returnItem = ReturnItem::where('order_item_id', $f['item']->id)->firstOrFail();
        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/returns/{$returnItem->id}/inspect", [
            'received_quantity' => 1, 'good_quantity' => 1, 'damaged_quantity' => 0,
        ])->assertUnprocessable();
        $this->assertSame(0, $this->returnMovements());

        $product = Product::create(['name' => 'Return Var Cake', 'slug' => 'return-var-'.uniqid(), 'has_variations' => true, 'status' => 'active']);
        $small = ProductVariation::create(['product_id' => $product->id, 'sku' => 'RV-S-'.uniqid(), 'price' => 10000, 'weight_grams' => 100, 'is_active' => true]);
        $large = ProductVariation::create(['product_id' => $product->id, 'sku' => 'RV-L-'.uniqid(), 'price' => 10000, 'weight_grams' => 100, 'is_active' => true]);
        foreach ([$small, $large] as $variation) {
            ProductVariationStock::create(['agent_id' => $b['agen']->id, 'product_variation_id' => $variation->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
            WarehouseStock::create(['agent_id' => $b['agen']->id, 'product_variation_id' => $variation->id, 'stock_type' => 'transit', 'quantity' => $variation->id === $small->id ? 5 : 10]);
        }
        WarehouseSetting::create(['agent_id' => $b['foreign']->id, 'factory_plan_enabled' => false]);

        $orderId = $this->actingAs($b['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'product_variation_id' => $small->id, 'quantity' => 2]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ])->json('data.id');
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'dikirim'])->assertOk();
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'terkirim'])->assertOk();
        $varItem = OrderItem::where('order_id', $orderId)->firstOrFail();
        $this->assertSame($small->id, (int) $varItem->product_variation_id);
        $varReturnId = $this->actingAs($b['konsumen'])->post("/api/v1/orders/{$orderId}/returns", [
            'reason' => 'Salah varian', 'items' => [['order_item_id' => $varItem->id, 'quantity' => 2]],
            'evidence' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertCreated()->json('data.id');
        $this->actingAs($b['admin'])->patchJson("/api/v1/admin/returns/{$varReturnId}/review", ['approved' => true])->assertOk();
        $varReturnItem = ReturnItem::where('order_item_id', $varItem->id)->firstOrFail();
        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/returns/{$varReturnItem->id}/inspect", [
            'received_quantity' => 2, 'good_quantity' => 2, 'damaged_quantity' => 0,
        ])->assertOk();
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/returns/{$varReturnItem->id}/finalize")->assertOk();

        $this->assertSame(7, $this->bucket($b['agen']->id, 0, 'transit', $small->id));
        $this->assertSame(10, $this->bucket($b['agen']->id, 0, 'transit', $large->id));
        $this->assertDatabaseHas('stock_movements', ['product_id' => null, 'product_variation_id' => $small->id, 'type' => 'return_restock', 'quantity' => 2]);
    }

    public function test_mixed_return_finalizes_good_portion_only(): void
    {
        $b = $this->branch();
        $f = $this->delivered($b, qty: 3);

        $secondItem = OrderItem::create(['order_id' => $f['order']->id, 'product_id' => $f['product']->id, 'product_name_snapshot' => $f['product']->name, 'sku_snapshot' => $f['product']->sku, 'unit_price_snapshot' => 10000, 'subtotal_snapshot' => 10000, 'original_quantity' => 2, 'fulfilled_quantity' => 2, 'status' => 'terkirim']);
        $f['item']->update(['original_quantity' => 4, 'fulfilled_quantity' => 4, 'returned_quantity' => 0]);
        $secondReturnId = $this->actingAs($b['konsumen'])->post("/api/v1/orders/{$f['order']->id}/returns", [
            'reason' => 'Sebagian rusak', 'items' => [['order_item_id' => $secondItem->id, 'quantity' => 1]],
            'evidence' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertCreated()->json('data.id');

        $this->actingAs($b['admin'])->patchJson("/api/v1/admin/returns/{$f['returnId']}/review", ['approved' => true])->assertOk();
        $this->actingAs($b['admin'])->patchJson("/api/v1/admin/returns/{$secondReturnId}/review", ['approved' => true])->assertOk();

        $goodItem = ReturnItem::where('order_item_id', $f['item']->id)->firstOrFail();
        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/returns/{$goodItem->id}/inspect", [
            'received_quantity' => 3, 'good_quantity' => 2, 'damaged_quantity' => 1,
        ])->assertOk();
        $this->assertNull($goodItem->fresh()->restock_processed_at);
        $this->assertSame(10, $this->bucket($b['agen']->id, $f['product']->id, 'transit'));
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/returns/{$goodItem->id}/finalize")->assertOk();
        $this->assertSame(12, $this->bucket($b['agen']->id, $f['product']->id, 'transit'));
        $this->assertSame('restocked', $goodItem->fresh()->disposition_status);
        $this->assertNotNull($goodItem->fresh()->restock_processed_at);
        $this->assertSame(2, (int) StockMovement::withoutGlobalScopes()->where('type', 'return_restock')->where('reference_id', $goodItem->id)->sum('quantity'));

        $badItem = ReturnItem::where('order_item_id', $secondItem->id)->firstOrFail();
        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/returns/{$badItem->id}/inspect", [
            'received_quantity' => 1, 'good_quantity' => 0, 'damaged_quantity' => 1,
        ])->assertOk();
        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/returns/{$badItem->id}/finalize")->assertOk();
        $this->assertSame(12, $this->bucket($b['agen']->id, $f['product']->id, 'transit'));
        $this->assertSame('damaged_confirmed', $badItem->fresh()->disposition_status);
        $this->assertSame(1, $this->returnMovements());
    }

    public function test_reinspection_is_blocked_before_and_after_finalization(): void
    {
        $b = $this->branch();
        $f = $this->delivered($b);

        $this->actingAs($b['admin'])->patchJson("/api/v1/admin/returns/{$f['returnId']}/review", ['approved' => true])->assertOk();
        $returnItem = ReturnItem::where('order_item_id', $f['item']->id)->firstOrFail();
        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/returns/{$returnItem->id}/inspect", [
            'received_quantity' => 3, 'good_quantity' => 3, 'damaged_quantity' => 0,
        ])->assertOk();
        $inspectedAt = $returnItem->fresh()->inspected_at;

        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/returns/{$returnItem->id}/inspect", [
            'received_quantity' => 3, 'good_quantity' => 0, 'damaged_quantity' => 3,
        ])->assertUnprocessable();
        $returnItem->refresh();
        $this->assertSame('good', $returnItem->condition_status);
        $this->assertSame(3, $returnItem->good_quantity);
        $this->assertNull($returnItem->restock_processed_at);
        $this->assertSame(10, $this->bucket($b['agen']->id, $f['product']->id, 'transit'));
        $this->assertSame(0, $this->returnMovements());

        $this->actingAs($b['admin'])->postJson("/api/v1/warehouse/returns/{$returnItem->id}/finalize")->assertOk();

        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/returns/{$returnItem->id}/inspect", [
            'received_quantity' => 3, 'good_quantity' => 3, 'damaged_quantity' => 0,
        ])->assertUnprocessable();
        $this->assertSame('restocked', $returnItem->fresh()->disposition_status);
        $this->assertSame(13, $this->bucket($b['agen']->id, $f['product']->id, 'transit'));
        $this->assertSame(1, $this->returnMovements());
    }
}
