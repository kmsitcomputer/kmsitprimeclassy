<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductDiscount;
use App\Models\ProductStock;
use App\Models\ProductVariation;
use App\Models\ProductVariationStock;
use App\Models\ShippingConfiguration;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * IMP-002 — Product/Variation discounts.
 *
 * Covers: product-targeted, variation-targeted, enabled/disabled, validity
 * window, cross-agent denial, and that quote + order creation both apply the
 * SAME effective price through PricingService (server-authoritative).
 */
class DiscountTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);

        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Toko', 'address' => 'Jl. X', 'latitude' => -6.2, 'longitude' => 106.8]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true]);

        $product = Product::create(['name' => 'Kue', 'slug' => 'kue-'.uniqid(), 'has_variations' => true, 'base_price' => 100000, 'weight_grams' => 500, 'status' => 'active']);
        $variation = ProductVariation::create(['product_id' => $product->id, 'sku' => 'D-V-'.Str::uuid(), 'name' => 'Coklat', 'price' => 120000, 'weight_grams' => 500, 'is_active' => true]);
        // Simple product for the product-targeted case.
        $simple = Product::create(['sku' => 'D-S-'.Str::uuid(), 'name' => 'Kue Polos', 'slug' => 'kue-polos-'.uniqid(), 'has_variations' => false, 'base_price' => 50000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 50, 'quantity_reserved' => 0]);
        ProductVariationStock::create(['agent_id' => $agen->id, 'product_variation_id' => $variation->id, 'quantity_on_hand' => 50, 'quantity_reserved' => 0]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $simple->id, 'quantity_on_hand' => 50, 'quantity_reserved' => 0]);

        $this->b = compact('agen', 'admin', 'konsumen', 'product', 'variation', 'simple');
    }

    private function placeOrder(array $items, ?string $voucherCode = null): Order
    {
        $payload = [
            'payment_method_code' => 'cod',
            'items' => $items,
            'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ];
        if ($voucherCode !== null) {
            $payload['voucher_code'] = $voucherCode;
        }

        $id = $this->actingAs($this->b['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', $payload)->assertCreated()->json('data.id');

        return Order::withoutGlobalScopes()->findOrFail($id);
    }

    public function test_product_discount_lowers_the_effective_unit_price_in_quote_and_order(): void
    {
        ProductDiscount::create(['agent_id' => $this->b['agen']->id, 'product_id' => $this->b['simple']->id, 'name' => 'Diskon', 'percentage' => 20, 'is_active' => true]);

        $quote = $this->actingAs($this->b['konsumen'])->postJson('/api/v1/checkout/quote', [
            'items' => [['product_id' => $this->b['simple']->id, 'quantity' => 1]],
            'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ])->assertOk()->json('data');

        // base 50000 - 20% = 40000
        $this->assertSame(40000.0, (float) $quote['subtotal_amount']);

        $order = $this->placeOrder([['product_id' => $this->b['simple']->id, 'quantity' => 1]]);
        $this->assertSame(40000.0, (float) $order->items()->first()->unit_price_snapshot);
        $this->assertSame(40000.0, (float) $order->subtotal_amount);
    }

    public function test_variation_discount_wins_over_product_discount(): void
    {
        ProductDiscount::create(['agent_id' => $this->b['agen']->id, 'product_id' => $this->b['product']->id, 'name' => 'Produk 10%', 'percentage' => 10, 'is_active' => true]);
        ProductDiscount::create(['agent_id' => $this->b['agen']->id, 'product_variation_id' => $this->b['variation']->id, 'name' => 'Variasi 30%', 'percentage' => 30, 'is_active' => true]);

        $order = $this->placeOrder([['product_id' => $this->b['product']->id, 'product_variation_id' => $this->b['variation']->id, 'quantity' => 1]]);
        // variation price 120000 - 30% = 84000 (variation discount wins over product's 10%).
        $this->assertSame(84000.0, (float) $order->items()->first()->unit_price_snapshot);
    }

    public function test_inactive_or_expired_discount_never_applies(): void
    {
        ProductDiscount::create(['agent_id' => $this->b['agen']->id, 'product_id' => $this->b['simple']->id, 'name' => 'Inactive', 'percentage' => 50, 'is_active' => false]);
        ProductDiscount::create(['agent_id' => $this->b['agen']->id, 'product_id' => $this->b['simple']->id, 'name' => 'Expired', 'percentage' => 50, 'is_active' => true, 'starts_at' => now()->subDays(30)->toDateString(), 'ends_at' => now()->subDays(1)->toDateString()]);
        ProductDiscount::create(['agent_id' => $this->b['agen']->id, 'product_id' => $this->b['simple']->id, 'name' => 'Future', 'percentage' => 50, 'is_active' => true, 'starts_at' => now()->addDays(2)->toDateString()]);

        $order = $this->placeOrder([['product_id' => $this->b['simple']->id, 'quantity' => 1]]);
        $this->assertSame(50000.0, (float) $order->items()->first()->unit_price_snapshot);
    }

    public function test_cross_agent_discount_is_denied(): void
    {
        $other = User::factory()->agen()->create();
        $other->update(['agent_id' => $other->id]);

        // Agent B cannot read/create a discount for Agent A's branch.
        $this->actingAs($this->b['agen'])->postJson('/api/v1/promo/discounts', [
            'name' => 'X', 'product_id' => $this->b['simple']->id, 'percentage' => 10,
        ])->assertCreated();

        $this->actingAs($other)->getJson('/api/v1/promo/discounts')->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_keuangan_cannot_manage_discounts(): void
    {
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $this->b['agen']->id]);
        $this->actingAs($keuangan)->getJson('/api/v1/promo/discounts')->assertForbidden();
        $this->actingAs($keuangan)->postJson('/api/v1/promo/discounts', ['name' => 'X', 'percentage' => 10])->assertForbidden();
    }

    /* ------------- A1-16: toggle-only PATCH works ------------- */

    public function test_toggle_only_patch_deactivates_and_reactivates_a_discount(): void
    {
        $row = ProductDiscount::create(['agent_id' => $this->b['agen']->id, 'product_id' => $this->b['simple']->id, 'name' => 'Diskon', 'percentage' => 10, 'is_active' => true]);

        // The shipped UI sends ONLY { is_active: false }.
        $this->actingAs($this->b['admin'])
            ->patchJson("/api/v1/promo/discounts/{$row->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->assertFalse($row->fresh()->is_active);
        // ...and the reverse toggle works too, preserving every other field.
        $this->actingAs($this->b['admin'])
            ->patchJson("/api/v1/promo/discounts/{$row->id}", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
        $this->assertSame(10, (int) $row->fresh()->percentage, 'percentage untouched by a toggle');
    }

    /* ------------- A1-17: unsupported targetless discounts are rejected ------------- */

    public function test_targetless_discount_is_rejected_instead_of_saved_never_effective(): void
    {
        // Neither product_id nor variation — PricingService would never select
        // such a row; the old API saved it "successfully" while doing nothing.
        $this->actingAs($this->b['agen'])
            ->postJson('/api/v1/promo/discounts', ['name' => 'Semua', 'percentage' => 10])
            ->assertStatus(422);
        $this->assertDatabaseCount('product_discounts', 0);
    }

    public function test_variation_target_must_belong_to_the_selected_product(): void
    {
        $otherProduct = Product::create(['name' => 'Lain', 'slug' => 'lain-'.uniqid(), 'has_variations' => true, 'base_price' => 90000, 'weight_grams' => 500, 'status' => 'active']);
        $otherVariation = ProductVariation::create(['product_id' => $otherProduct->id, 'sku' => 'O-'.Str::uuid(), 'name' => 'Variasi', 'price' => 95000, 'weight_grams' => 500, 'is_active' => true]);

        // variation_id of a DIFFERENT product than product_id — must 422.
        $this->actingAs($this->b['agen'])
            ->postJson('/api/v1/promo/discounts', [
                'name' => 'Mismatch', 'product_id' => $this->b['product']->id,
                'product_variation_id' => $otherVariation->id, 'percentage' => 10,
            ])
            ->assertStatus(422);
        $this->assertDatabaseCount('product_discounts', 0);
    }

    /* ------------- A1-18: super_admin branch management ------------- */

    public function test_super_admin_can_create_and_toggle_discount_in_an_explicit_branch_but_needs_a_valid_agent(): void
    {
        $branchA = $this->b['agen'];
        $branchB = User::factory()->agen()->create();
        $branchB->update(['agent_id' => $branchB->id]);
        AgentProfile::create(['user_id' => $branchB->id, 'store_name' => 'Toko B', 'address' => 'Jl. B', 'latitude' => -6.2, 'longitude' => 106.8]);

        $super = User::factory()->superAdmin()->create();

        // Without ?agent_id= super_admin is refused (previously 500 on
        // user.agent_id null).
        $this->actingAs($super)->postJson('/api/v1/promo/discounts', ['name' => 'X', 'product_id' => $this->b['simple']->id, 'percentage' => 10])
            ->assertStatus(422);

        // Invalid agent id → 422, not 500.
        $this->actingAs($super)->postJson('/api/v1/promo/discounts?agent_id=999999', ['name' => 'X', 'product_id' => $this->b['simple']->id, 'percentage' => 10])
            ->assertStatus(422);

        // Valid agent → created under THAT branch, and toggle works too.
        $created = $this->actingAs($super)
            ->postJson('/api/v1/promo/discounts?agent_id='.$branchA->id, ['name' => 'Super', 'product_id' => $this->b['simple']->id, 'percentage' => 15])
            ->assertCreated()->json('data.id');
        $this->assertSame((int) $branchA->id, (int) ProductDiscount::withoutGlobalScopes()->findOrFail($created)->agent_id);

        $this->actingAs($super)
            ->patchJson("/api/v1/promo/discounts/{$created}?agent_id={$branchA->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // Listing with ?agent_id= scopes to that branch.
        $list = $this->actingAs($super)->getJson('/api/v1/promo/discounts?agent_id='.$branchA->id)->assertOk()->json('data');
        $this->assertContains((int) $created, array_map('intval', array_column($list, 'id')));
    }
    public function test_patch_can_clear_variation_target_to_apply_to_parent_product(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        $product = \App\Models\Product::create(['sku' => null, 'name' => 'Variants', 'slug' => 'final-variants', 'has_variations' => true, 'status' => 'active']);
        $variant = \App\Models\ProductVariation::create(['product_id' => $product->id, 'sku' => 'FINAL-VAR-A', 'price' => 10000, 'weight_grams' => 500, 'is_active' => true]);
        $discount = \App\Models\ProductDiscount::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'product_variation_id' => $variant->id, 'name' => 'Variant', 'percentage' => 10]);
        $this->actingAs($agen)->patchJson("/api/v1/promo/discounts/{$discount->id}", ['product_variation_id' => null])->assertOk();
        $this->assertNull($discount->fresh()->product_variation_id);
    }

}