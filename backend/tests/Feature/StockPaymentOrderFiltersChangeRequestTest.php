<?php

namespace Tests\Feature;

use App\Models\AgentPaymentGatewayConfig;
use App\Models\AgentProfile;
use App\Models\BankTransferVerification;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariation;
use App\Models\ProductVariationStock;
use App\Models\ShippingConfiguration;
use App\Models\User;
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
 * Collected change request: stock synchronisation (canonical SellableStockService), Sales/Korsal
 * payment authority + payer/uploader/verifier audit, and server-side order filters.
 */
class StockPaymentOrderFiltersChangeRequestTest extends TestCase
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

    private function branch(string $label = 'A'): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Toko '.$label, 'address' => 'Jl. '.$label, 'latitude' => -6.2, 'longitude' => 106.8166]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $agen->id]);
        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $sales = User::factory()->sales()->create(['agent_id' => $agen->id, 'parent_id' => $korsal->id, 'korsal_id' => $korsal->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id, 'parent_id' => $sales->id, 'korsal_id' => $korsal->id, 'sales_id' => $sales->id]);

        $bank = PaymentMethod::query()->where('code', 'bank_transfer')->firstOrFail();
        AgentPaymentGatewayConfig::create([
            'agent_id' => $agen->id, 'payment_method_id' => $bank->id, 'environment' => 'sandbox',
            'config' => ['bank_name' => 'BCA', 'account_name' => 'PT Prime', 'account_number' => '123456'],
        ]);
        ShippingConfiguration::create([
            'agent_id' => $agen->id, 'price_per_km' => 2000, 'minimum_distance_km' => 0,
            'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true,
        ]);

        return compact('agen', 'admin', 'keuangan', 'korsal', 'sales', 'konsumen');
    }

    private function simpleProduct(): Product
    {
        return Product::create([
            'sku' => 'T-'.Str::uuid(), 'name' => 'Kue', 'slug' => 'kue-'.uniqid(),
            'has_variations' => false, 'base_price' => 100000, 'weight_grams' => 1000, 'status' => 'active',
        ]);
    }

    /** Variation product whose variations are stocked ONLY through warehouse buckets (the observed Kastangel shape). */
    private function variationProduct(int $agentId, array $transit): array
    {
        $product = Product::create(['name' => 'Kastangel', 'slug' => 'kastangel-'.uniqid(), 'has_variations' => true, 'status' => 'active']);
        $variations = [];
        foreach ($transit as $qty) {
            $v = $product->variations()->create(['sku' => 'KS-'.Str::uuid(), 'price' => 50000, 'weight_grams' => 500, 'is_active' => true]);
            WarehouseStock::create(['agent_id' => $agentId, 'product_variation_id' => $v->id, 'stock_type' => 'transit', 'quantity' => $qty]);
            $variations[] = $v;
        }

        return [$product, $variations];
    }

    private function order(User $konsumen, array $items, string $method = 'bank_transfer', array $extra = [])
    {
        return $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', array_merge([
                'payment_method_code' => $method, 'items' => $items,
                'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810,
            ], $extra));
    }

    private function placeOrder(User $konsumen, User $agen): Order
    {
        $product = $this->simpleProduct();
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);
        $id = $this->order($konsumen, [['product_id' => $product->id, 'quantity' => 1]])->assertCreated()->json('data.id');

        return Order::query()->withoutGlobalScopes()->findOrFail($id);
    }

    private function proof(User $actor, Order $order, array $extra = [])
    {
        return $this->actingAs($actor)->postJson("/api/v1/orders/{$order->id}/payment/proof", array_merge(['proof' => UploadedFile::fake()->image('p.jpg')], $extra));
    }

    // ================= STOCK =================

    public function test_variation_stock_page_lists_warehouse_stock_and_matches_product_aggregate_and_storefront(): void
    {
        $b = $this->branch();
        [$product, $vars] = $this->variationProduct($b['agen']->id, [100, 80, 36]); // 216 like Kastangel

        $rows = $this->actingAs($b['admin'])->getJson('/api/v1/stock/variations')->assertOk()->json('data');
        $this->assertCount(3, $rows, 'every warehouse-stocked variation appears in Stok Produk → Varian');
        $this->assertSame(216, collect($rows)->sum('quantity_on_hand'));
        $this->assertSame(0, collect($rows)->sum('quantity_reserved'));
        $this->assertSame(216, collect($rows)->sum('quantity_available'));

        // Produk & Kategori / storefront aggregate = same canonical truth.
        $list = $this->actingAs($b['admin'])->getJson('/api/v1/products')->assertOk()->json('data');
        $item = collect($list)->firstWhere('id', $product->id);
        $this->assertSame(216, collect($item['variations'])->sum(fn ($v) => $v['stock']['available']));
    }

    public function test_another_agents_stock_is_never_leaked_into_variation_page_or_availability(): void
    {
        $a = $this->branch('A');
        $b = $this->branch('B');
        [$product, $vars] = $this->variationProduct($a['agen']->id, [40]);
        WarehouseStock::create(['agent_id' => $b['agen']->id, 'product_variation_id' => $vars[0]->id, 'stock_type' => 'transit', 'quantity' => 999]);

        $this->assertSame(40, collect($this->actingAs($a['admin'])->getJson('/api/v1/stock/variations')->json('data'))->sum('quantity_available'));
        $this->assertSame(999, collect($this->actingAs($b['admin'])->getJson('/api/v1/stock/variations')->json('data'))->sum('quantity_available'));

        $c = $this->branch('C');
        $this->assertSame([], $this->actingAs($c['admin'])->getJson('/api/v1/stock/variations')->json('data'));
        $item = collect($this->actingAs($c['admin'])->getJson('/api/v1/products')->json('data'))->firstWhere('id', $product->id);
        $this->assertSame(0, collect($item['variations'])->sum(fn ($v) => $v['stock']['available']));
    }

    public function test_in_stock_warehouse_only_variation_can_be_ordered_and_reserved_then_blocks_when_exhausted(): void
    {
        $b = $this->branch();
        [$product, $vars] = $this->variationProduct($b['agen']->id, [5]);
        $line = fn (int $q) => [['product_id' => $product->id, 'product_variation_id' => $vars[0]->id, 'quantity' => $q]];

        $this->order($b['konsumen'], $line(3))->assertCreated();
        $row = $this->actingAs($b['admin'])->getJson('/api/v1/stock/variations')->json('data.0');
        $this->assertSame(5, $row['quantity_on_hand']);
        $this->assertSame(3, $row['quantity_reserved']);
        $this->assertSame(2, $row['quantity_available']);

        $this->order($b['konsumen'], $line(3))->assertStatus(422); // only 2 left → blocked
        $this->assertSame(3, (int) ProductVariationStock::withoutGlobalScopes()->where('product_variation_id', $vars[0]->id)->value('quantity_reserved'));
    }

    public function test_out_of_stock_variation_remains_blocked_and_no_fake_stock_is_created(): void
    {
        $b = $this->branch();
        $product = Product::create(['name' => 'Zero', 'slug' => 'zero-'.uniqid(), 'has_variations' => true, 'status' => 'active']);
        $v = $product->variations()->create(['sku' => 'Z-'.Str::uuid(), 'price' => 1000, 'weight_grams' => 100, 'is_active' => true]);

        $this->order($b['konsumen'], [['product_id' => $product->id, 'product_variation_id' => $v->id, 'quantity' => 1]])->assertStatus(422);
        $this->assertSame(0, (int) ProductVariationStock::withoutGlobalScopes()->where('product_variation_id', $v->id)->sum('quantity_on_hand'));
    }

    public function test_simple_product_stock_page_is_canonical(): void
    {
        $b = $this->branch();
        $p = $this->simpleProduct();
        WarehouseStock::create(['agent_id' => $b['agen']->id, 'product_id' => $p->id, 'stock_type' => 'transit', 'quantity' => 12]);
        $rows = $this->actingAs($b['admin'])->getJson('/api/v1/stock/products')->assertOk()->json('data');
        $this->assertSame(12, collect($rows)->firstWhere('product_id', $p->id)['quantity_available']);
    }

    // ================= PAYMENT AUTHORITY + AUDIT =================

    public function test_sales_and_korsal_cannot_approve_or_reject_but_keuangan_can_and_audit_is_separate(): void
    {
        $b = $this->branch();
        $order = $this->placeOrder($b['konsumen'], $b['agen']);

        // Sales uploads; consumer paid.
        $this->proof($b['sales'], $order, ['paid_by' => 'konsumen'])->assertOk()
            ->assertJsonPath('data.transaction.bank_transfer_verification.paid_by', 'konsumen')
            ->assertJsonPath('data.transaction.bank_transfer_verification.submitted_by.id', $b['sales']->id)
            ->assertJsonPath('data.transaction.bank_transfer_verification.verified_by', null);

        foreach ([$b['sales'], $b['korsal']] as $actor) {
            $this->actingAs($actor)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertForbidden();
            $this->actingAs($actor)->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => false, 'rejection_reason' => 'x'])->assertForbidden();
        }

        $this->actingAs($b['keuangan'])->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])
            ->assertOk()
            ->assertJsonPath('data.transaction.bank_transfer_verification.verified_by.id', $b['keuangan']->id)
            ->assertJsonPath('data.transaction.bank_transfer_verification.submitted_by.id', $b['sales']->id)
            ->assertJsonPath('data.transaction.bank_transfer_verification.paid_by', 'konsumen');

        $v = BankTransferVerification::query()->firstOrFail();
        $this->assertNotNull($v->submitted_at);
        $this->assertNotNull($v->verified_at);
    }

    public function test_payer_can_be_sales_or_korsal_and_is_validated_against_the_uploader_role(): void
    {
        $b = $this->branch();
        $o1 = $this->placeOrder($b['konsumen'], $b['agen']);
        $this->proof($b['sales'], $o1, ['paid_by' => 'sales'])->assertOk()
            ->assertJsonPath('data.transaction.bank_transfer_verification.paid_by', 'sales');

        $o2 = $this->placeOrder($b['konsumen'], $b['agen']);
        $this->proof($b['korsal'], $o2, ['paid_by' => 'korsal'])->assertOk()
            ->assertJsonPath('data.transaction.bank_transfer_verification.paid_by', 'korsal');

        // A Sales cannot claim Korsal paid; a konsumen cannot claim Sales paid.
        $o3 = $this->placeOrder($b['konsumen'], $b['agen']);
        $this->proof($b['sales'], $o3, ['paid_by' => 'korsal'])->assertStatus(422);
        $this->proof($b['konsumen'], $o3, ['paid_by' => 'sales'])->assertStatus(422);

        // Default (historical-compatible) is konsumen.
        $this->proof($b['konsumen'], $o3)->assertOk()
            ->assertJsonPath('data.transaction.bank_transfer_verification.paid_by', 'konsumen')
            ->assertJsonPath('data.transaction.bank_transfer_verification.submitted_on_behalf', false);
    }

    public function test_out_of_scope_sales_cannot_upload_and_historical_rows_without_payer_stay_readable(): void
    {
        $b = $this->branch();
        $order = $this->placeOrder($b['konsumen'], $b['agen']);
        $other = User::factory()->sales()->create(['agent_id' => $b['agen']->id, 'parent_id' => $b['korsal']->id, 'korsal_id' => $b['korsal']->id]);
        $this->proof($other, $order)->assertForbidden();

        $this->proof($b['konsumen'], $order)->assertOk();
        BankTransferVerification::query()->update(['paid_by_role' => null, 'submitted_at' => null]); // historical shape
        $this->actingAs($b['keuangan'])->getJson("/api/v1/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.payment_transaction.bank_transfer_verification.paid_by', null);
    }

    // ================= FILTERS =================

    private function orderRow(array $b, array $attrs): Order
    {
        return Order::withoutGlobalScopes()->create(array_merge([
            'order_no' => 'PC-'.Str::upper(Str::random(12)), 'konsumen_id' => $b['konsumen']->id,
            'sales_id' => $b['sales']->id, 'korsal_id' => $b['korsal']->id, 'agent_id' => $b['agen']->id,
            'status' => 'diterima', 'payment_status' => 'unpaid', 'subtotal_amount' => 1000, 'total_amount' => 1000,
            'recipient_name_snapshot' => 'Budi', 'recipient_phone_snapshot' => '0811', 'address_snapshot' => 'Test',
        ], $attrs));
    }

    private function ids(array $payload): array
    {
        return collect($payload['data'])->pluck('order_no')->sort()->values()->all();
    }

    public function test_admin_status_order_and_dispatch_equivalent_filters_work_server_side(): void
    {
        $b = $this->branch();
        $a = $this->orderRow($b, ['status' => 'diproses', 'payment_status' => 'paid', 'province_id' => '32']);
        $c = $this->orderRow($b, ['status' => 'diterima', 'payment_status' => 'unpaid', 'province_id' => '33']);
        $d = $this->orderRow($b, ['status' => 'diproses', 'payment_status' => 'partially_paid', 'province_id' => '32']);

        $get = fn (string $q) => $this->ids($this->actingAs($b['admin'])->getJson('/api/v1/orders?'.$q)->assertOk()->json());
        $this->assertSame(collect([$a->order_no, $d->order_no])->sort()->values()->all(), $get('status=diproses'));
        $this->assertSame([$c->order_no], $get('status=diterima'));
        $this->assertSame([$a->order_no], $get('paid=paid'));                    // Dispatch "Lunas"
        $this->assertSame(collect([$c->order_no, $d->order_no])->sort()->values()->all(), $get('paid=unpaid')); // Dispatch "Belum lunas"
        $this->assertSame(collect([$a->order_no, $d->order_no])->sort()->values()->all(), $get('province_id=32'));
        $this->assertSame([$d->order_no], $get('province_id=32&paid=unpaid&status=diproses'));

        $this->assertSame(3, count($get('status=bogus')), 'unknown status is ignored, never an SQL/shape error');
    }

    public function test_delivery_date_filter_matches_the_dispatch_interpretation(): void
    {
        $b = $this->branch();
        $o = $this->orderRow($b, ['status' => 'diproses']);
        $other = $this->orderRow($b, ['status' => 'diproses']);
        $product = $this->simpleProduct();
        foreach ([[$o, '2026-10-20'], [$other, '2026-10-25']] as [$order, $date]) {
            \App\Models\OrderItem::withoutGlobalScopes()->create([
                'order_id' => $order->id, 'product_id' => $product->id, 'original_quantity' => 1, 'fulfilled_quantity' => 1,
                'status' => 'diproses', 'requested_delivery_date' => $date,
                'product_name_snapshot' => 'Kue', 'sku_snapshot' => 'S', 'unit_price_snapshot' => 1000, 'subtotal_snapshot' => 1000,
            ]);
        }
        $res = $this->ids($this->actingAs($b['admin'])->getJson('/api/v1/orders?delivery_date=2026-10-20')->assertOk()->json());
        $this->assertSame([$o->order_no], $res);
    }

    public function test_keuangan_status_pelunasan_filter_uses_canonical_payment_states(): void
    {
        $b = $this->branch();
        $rows = [];
        foreach (['unpaid', 'pending_verification', 'partially_paid', 'paid', 'failed'] as $st) {
            $rows[$st] = $this->orderRow($b, ['payment_status' => $st]);
        }
        foreach ($rows as $st => $order) {
            $res = $this->ids($this->actingAs($b['keuangan'])->getJson('/api/v1/orders?payment_status='.$st)->assertOk()->json());
            $this->assertSame([$order->order_no], $res, "settlement filter {$st}");
        }
        $this->assertCount(5, $this->ids($this->actingAs($b['keuangan'])->getJson('/api/v1/orders')->json()));
    }

    public function test_sales_and_korsal_filters_never_widen_their_scope(): void
    {
        $b = $this->branch();
        $mine = $this->orderRow($b, ['status' => 'diproses', 'payment_status' => 'paid']);

        // Another Sales/Korsal in the same branch with their own consumer + order.
        $otherKorsal = User::factory()->korsal()->create(['agent_id' => $b['agen']->id, 'parent_id' => $b['agen']->id]);
        $otherSales = User::factory()->sales()->create(['agent_id' => $b['agen']->id, 'parent_id' => $otherKorsal->id, 'korsal_id' => $otherKorsal->id]);
        $otherKonsumen = User::factory()->konsumen()->create(['agent_id' => $b['agen']->id, 'parent_id' => $otherSales->id, 'korsal_id' => $otherKorsal->id, 'sales_id' => $otherSales->id]);
        $foreign = $this->orderRow(['agen' => $b['agen'], 'konsumen' => $otherKonsumen, 'sales' => $otherSales, 'korsal' => $otherKorsal], ['status' => 'diproses', 'payment_status' => 'paid']);

        foreach (['sales', 'korsal'] as $role) {
            $actor = $b[$role];
            $all = $this->ids($this->actingAs($actor)->getJson('/api/v1/orders')->json());
            $this->assertSame([$mine->order_no], $all, "{$role} baseline scope");
            foreach (['status=diproses', 'payment_status=paid', 'paid=paid', 'search='.$foreign->order_no, 'status=diproses&payment_status=paid&paid=paid'] as $q) {
                $res = $this->ids($this->actingAs($actor)->getJson('/api/v1/orders?'.$q)->assertOk()->json());
                $this->assertNotContains($foreign->order_no, $res, "{$role} filter [{$q}] must not leak another scope");
            }
            $this->assertSame([$mine->order_no], $this->ids($this->actingAs($actor)->getJson('/api/v1/orders?status=diproses&payment_status=paid')->json()));
            $this->assertSame([], $this->ids($this->actingAs($actor)->getJson('/api/v1/orders?status=dibatalkan')->json()));
        }

        // Region options are derived from the caller's own scoped orders only.
        $this->actingAs($b['sales'])->getJson('/api/v1/orders/regions')->assertOk();
    }

    public function test_operational_roles_cannot_probe_payment_state_through_filters(): void
    {
        $b = $this->branch();
        $this->orderRow($b, ['status' => 'diproses', 'payment_status' => 'paid']);
        $this->orderRow($b, ['status' => 'diproses', 'payment_status' => 'unpaid']);
        $gudang = User::factory()->gudang()->create(['agent_id' => $b['agen']->id, 'parent_id' => $b['agen']->id]);

        $this->assertCount(2, $this->ids($this->actingAs($gudang)->getJson('/api/v1/orders?payment_status=paid&paid=paid')->assertOk()->json()));
    }
}
