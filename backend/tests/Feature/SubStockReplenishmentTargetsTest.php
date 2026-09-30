<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockHandover;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\SubStockRequest;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Codex Round-2 MAJOR-4 / MINOR-5 — first and new-SKU replenishment must not depend on an existing Sub stock
 * row, targets are server-authoritative (Agent Transit), and the response key is the canonical `sub_location`.
 */
class SubStockReplenishmentTargetsTest extends TestCase
{
    use RefreshDatabase;

    private User $agen;

    private User $admin;

    private User $gudang;

    private User $sub;

    private WarehouseSubLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        [$this->agen, $this->admin, $this->gudang, $this->sub, $this->location] = $this->branch();
    }

    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $sub = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $location = WarehouseSubLocation::create(['agent_id' => $agen->id, 'code' => 'L-'.Str::random(5), 'name' => 'Sub', 'created_by' => $agen->id]);
        $location->forceFill(['owner_user_id' => $sub->id])->save();

        return [$agen, $admin, $gudang, $sub, $location];
    }

    private function product(?User $agen = null, int $transit = 20): Product
    {
        $product = Product::create(['sku' => 'TGT-'.Str::uuid(), 'name' => 'Target Cake', 'slug' => 'tgt-'.Str::uuid(), 'has_variations' => false, 'status' => 'active']);
        if ($transit > 0) {
            WarehouseStock::create(['agent_id' => ($agen ?? $this->agen)->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => $transit]);
        }
        ProductStock::create(['agent_id' => ($agen ?? $this->agen)->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);

        return $product;
    }

    private function subQty(Product $product): ?int
    {
        return WarehouseStock::withoutGlobalScopes()->where('stock_type', 'sub')->where('sub_location_id', $this->location->id)->where('product_id', $product->id)->value('quantity');
    }

    private function replenishFully(Product $product, int $qty): int
    {
        $id = $this->actingAs($this->sub)->postJson('/api/v1/sub-stock/requests', [
            'direction' => 'replenish', 'items' => [['product_id' => $product->id, 'quantity' => $qty]],
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->admin)->postJson("/api/v1/sub-stock/requests/{$id}/approve")->assertOk();
        $this->actingAs($this->gudang)->postJson("/api/v1/sub-stock/requests/{$id}/execute")->assertOk()->assertJsonPath('data.status', 'executed');

        return $id;
    }

    public function test_owned_sub_with_zero_sub_rows_can_request_first_replenishment_end_to_end(): void
    {
        $product = $this->product();
        $this->assertSame(0, WarehouseStock::withoutGlobalScopes()->where('stock_type', 'sub')->where('sub_location_id', $this->location->id)->count());

        $this->actingAs($this->sub)->getJson('/api/v1/sub-stock/replenishment-targets')->assertOk()
            ->assertJsonFragment(['product_id' => $product->id, 'current_transit' => 20]);

        $this->replenishFully($product, 7);

        $this->assertSame(7, $this->subQty($product));
        $this->assertSame(13, (int) WarehouseStock::withoutGlobalScopes()->where('agent_id', $this->agen->id)->where('product_id', $product->id)->where('stock_type', 'transit')->value('quantity'));
    }

    public function test_existing_sub_can_replenish_a_previously_unstocked_sku(): void
    {
        $stocked = $this->product();
        $this->replenishFully($stocked, 3);
        $fresh = $this->product();

        $this->assertNull($this->subQty($fresh));
        $this->replenishFully($fresh, 5);

        $this->assertSame(5, $this->subQty($fresh));
        $this->assertSame(3, $this->subQty($stocked));
    }

    public function test_zero_current_transit_target_can_be_requested_but_execution_fails_safely_until_transit_exists(): void
    {
        $product = $this->product(null, 0);

        $listed = collect($this->actingAs($this->sub)->getJson('/api/v1/sub-stock/replenishment-targets')->assertOk()->json('data'))->firstWhere('product_id', $product->id);
        $this->assertNotNull($listed, 'a valid target is listed even with zero Transit');
        $this->assertSame(0, $listed['current_transit']);

        $id = $this->actingAs($this->sub)->postJson('/api/v1/sub-stock/requests', [
            'direction' => 'replenish', 'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->admin)->postJson("/api/v1/sub-stock/requests/{$id}/approve")->assertOk();

        $this->actingAs($this->gudang)->postJson("/api/v1/sub-stock/requests/{$id}/execute")->assertUnprocessable();
        $this->assertNull($this->subQty($product));
        $this->assertSame(0, StockMovement::withoutGlobalScopes()->where('product_id', $product->id)->count());
        $this->assertSame(0, StockHandover::withoutGlobalScopes()->count());
        $this->assertSame(0, StockTransfer::withoutGlobalScopes()->count());
        $this->assertSame('approved', SubStockRequest::withoutGlobalScopes()->findOrFail($id)->status);

        WarehouseStock::create(['agent_id' => $this->agen->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => 10]);
        $this->actingAs($this->gudang)->postJson("/api/v1/sub-stock/requests/{$id}/execute")->assertOk()->assertJsonPath('data.status', 'executed');
        $this->assertSame(4, $this->subQty($product));
    }

    public function test_invalid_or_ineligible_targets_are_rejected(): void
    {
        $inactive = $this->product();
        DB::table('products')->where('id', $inactive->id)->update(['status' => 'draft']);
        $deleted = $this->product();
        $deleted->delete();
        $withVariations = $this->product();
        DB::table('products')->where('id', $withVariations->id)->update(['has_variations' => true]);

        foreach ([$inactive->id, $deleted->id, $withVariations->id, 999999] as $productId) {
            $this->actingAs($this->sub)->postJson('/api/v1/sub-stock/requests', [
                'direction' => 'replenish', 'items' => [['product_id' => $productId, 'quantity' => 1]],
            ])->assertUnprocessable();
        }
        $this->actingAs($this->sub)->postJson('/api/v1/sub-stock/requests', [
            'direction' => 'replenish', 'items' => [['product_variation_id' => 999999, 'quantity' => 1]],
        ])->assertUnprocessable();

        $ids = collect($this->actingAs($this->sub)->getJson('/api/v1/sub-stock/replenishment-targets')->assertOk()->json('data'))->pluck('product_id')->all();
        $this->assertNotContains($inactive->id, $ids);
        $this->assertNotContains($deleted->id, $ids);
        $this->assertNotContains($withVariations->id, $ids);

        $this->actingAs($this->admin)->getJson('/api/v1/sub-stock/replenishment-targets')->assertForbidden();
        $this->actingAs($this->gudang)->getJson('/api/v1/sub-stock/replenishment-targets')->assertForbidden();
    }

    public function test_request_responses_use_the_canonical_snake_case_sub_location_key(): void
    {
        $product = $this->product();
        $this->actingAs($this->sub)->postJson('/api/v1/sub-stock/requests', [
            'direction' => 'replenish', 'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('data.sub_location.id', $this->location->id)->assertJsonMissingPath('data.subLocation');

        foreach ([$this->sub, $this->admin, $this->gudang] as $viewer) {
            $this->actingAs($viewer)->getJson('/api/v1/sub-stock/requests')->assertOk()
                ->assertJsonPath('data.0.sub_location.code', $this->location->code)->assertJsonMissingPath('data.0.subLocation');
        }
    }
}
