<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ReturnItem;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * Gudang inspection queue (`GET /warehouse/returns`): approved, not-yet-inspected return lines
 * only, branch-scoped, and read-only. The admin review list and every mutation endpoint keep
 * their existing authority.
 */
class WarehouseReturnQueueTest extends TestCase
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
        $sales = User::factory()->sales()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id, 'sales_id' => $sales->id]);

        return compact('agen', 'admin', 'gudang', 'sales', 'konsumen');
    }

    /** Delivers one COD order and files a return request, exactly like the existing return tests. */
    private function requestedReturn(array $b, int $qty = 2, bool $twoLines = false): array
    {
        $product = Product::create([
            'sku' => 'RET-QUEUE-'.Str::uuid(), 'name' => 'Return Queue Cake', 'slug' => 'return-queue-'.uniqid(),
            'has_variations' => false, 'base_price' => 10000, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $b['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
        WarehouseStock::create(['agent_id' => $b['agen']->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 10]);
        WarehouseSetting::create(['agent_id' => $b['agen']->id, 'factory_plan_enabled' => false]);

        $lines = [['product_id' => $product->id, 'quantity' => $qty]];
        if ($twoLines) {
            $second = Product::create([
                'sku' => 'RET-QUEUE-'.Str::uuid(), 'name' => 'Return Queue Cake 2', 'slug' => 'return-queue-2-'.uniqid(),
                'has_variations' => false, 'base_price' => 10000, 'weight_grams' => 500, 'status' => 'active',
            ]);
            ProductStock::create(['agent_id' => $b['agen']->id, 'product_id' => $second->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
            WarehouseStock::create(['agent_id' => $b['agen']->id, 'product_id' => $second->id, 'stock_type' => 'transit', 'quantity' => 10]);
            $lines[] = ['product_id' => $second->id, 'quantity' => $qty];
        }

        $orderId = $this->actingAs($b['agen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'konsumen_id' => $b['konsumen']->id,
            'payment_method_code' => 'cod',
            'items' => $lines,
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ])->json('data.id');

        $order = Order::withoutGlobalScopes()->findOrFail($orderId);
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'dikirim'])->assertOk();
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'terkirim'])->assertOk();
        $items = OrderItem::where('order_id', $order->id)->orderBy('id')->get();

        $returnId = $this->actingAs($b['konsumen'])->post("/api/v1/orders/{$order->id}/returns", [
            'reason' => 'Salah kirim',
            'items' => $items->map(fn ($item) => ['order_item_id' => $item->id, 'quantity' => $qty])->all(),
            'evidence' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertCreated()->json('data.id');

        return compact('product', 'order', 'items', 'returnId') + ['item' => $items->first()];
    }

    private function approve(array $b, int $returnId): void
    {
        $this->actingAs($b['admin'])
            ->patchJson("/api/v1/admin/returns/{$returnId}/review", ['approved' => true])
            ->assertOk();
    }

    public function test_queue_lists_only_approved_uninspected_lines_and_drops_them_after_inspection(): void
    {
        $b = $this->branch();
        $f = $this->requestedReturn($b);

        // Requested (not yet approved) must not be visible to Gudang.
        $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/returns')
            ->assertOk()->assertJsonPath('meta.total', 0);

        $this->approve($b, $f['returnId']);

        $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/returns')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $f['returnId'])
            ->assertJsonPath('data.0.items.0.status', 'approved')
            ->assertJsonPath('data.0.items.0.inspected_at', null)
            ->assertJsonCount(1, 'data.0.items');

        $returnItem = ReturnItem::where('return_id', $f['returnId'])->firstOrFail();
        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/returns/{$returnItem->id}/inspect", [
            'received_quantity' => 2, 'good_quantity' => 2, 'damaged_quantity' => 0,
        ])->assertOk();

        // Once inspected, the line leaves the queue; Admin still owns finalization.
        $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/returns')
            ->assertOk()->assertJsonPath('meta.total', 0);

        $this->assertNotNull($returnItem->fresh()->inspected_at);
        $this->assertSame('pending_disposition', $returnItem->fresh()->disposition_status);
    }

    public function test_queue_returns_only_the_uninspected_line_of_a_multi_line_return(): void
    {
        $b = $this->branch();
        $f = $this->requestedReturn($b, twoLines: true);
        $this->approve($b, $f['returnId']);

        $returnItems = ReturnItem::where('return_id', $f['returnId'])->orderBy('id')->get();
        $this->assertCount(2, $returnItems);

        // Inspect ONE line only.
        $this->actingAs($b['gudang'])->postJson("/api/v1/warehouse/returns/{$returnItems[0]->id}/inspect", [
            'received_quantity' => 2, 'good_quantity' => 2, 'damaged_quantity' => 0,
        ])->assertOk();

        $response = $this->actingAs($b['gudang'])->getJson('/api/v1/warehouse/returns')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data.0.items')
            ->assertJsonPath('data.0.items.0.id', $returnItems[1]->id)
            ->assertJsonPath('data.0.items.0.inspected_at', null);

        // The inspected line is never echoed back to Gudang's queue.
        $this->assertNotContains($returnItems[0]->id, array_column($response->json('data.0.items'), 'id'));
    }

    public function test_queue_is_branch_scoped_and_never_returns_another_branch(): void
    {
        $a = $this->branch();
        $foreign = $this->branch();

        $own = $this->requestedReturn($a);
        $other = $this->requestedReturn($foreign);
        $this->approve($a, $own['returnId']);
        $this->approve($foreign, $other['returnId']);

        $this->actingAs($a['gudang'])->getJson('/api/v1/warehouse/returns')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $own['returnId']);

        $this->actingAs($foreign['gudang'])->getJson('/api/v1/warehouse/returns')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $other['returnId']);

        // The admin review list keeps its own branch scope (unchanged behaviour).
        $this->actingAs($a['admin'])->getJson('/api/v1/admin/returns')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $own['returnId']);
    }

    public function test_queue_refuses_guests_and_non_gudang_roles(): void
    {
        // Must run before any actingAs() in this test: the auth guard persists for the request.
        $this->getJson('/api/v1/warehouse/returns')->assertStatus(401);

        $b = $this->branch();
        $f = $this->requestedReturn($b);
        $this->approve($b, $f['returnId']);

        foreach (['admin', 'sales', 'konsumen'] as $role) {
            $this->actingAs($b[$role])->getJson('/api/v1/warehouse/returns')->assertStatus(403);
        }

        // Reviews/finalization are untouched: Gudang still cannot review or finalize.
        $this->actingAs($b['gudang'])
            ->patchJson("/api/v1/admin/returns/{$f['returnId']}/review", ['approved' => true])
            ->assertStatus(403);
        $returnItem = ReturnItem::where('return_id', $f['returnId'])->firstOrFail();
        $this->actingAs($b['gudang'])
            ->postJson("/api/v1/warehouse/returns/{$returnItem->id}/finalize")
            ->assertStatus(403);
    }
}