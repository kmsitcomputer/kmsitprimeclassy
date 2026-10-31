<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Commission;
use App\Models\CommissionAdjustment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductFee;
use App\Models\ProductStock;
use App\Models\ReturnItem;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * WS-8 (Owner 2026-10-30, FINAL): Refund dan Fee Sales.
 * fee dibatalkan = Σ(unit direfund × snapshot fee per unit), dari SNAPSHOT transaksi;
 * refund parsial/penuh; unit tidak direfund tidak kehilangan fee; BUKAN persentase dari
 * nominal refund; tidak ada double adjustment; refund ditolak tidak membatalkan fee;
 * ledger historis tetap utuh.
 */
class SalesFeeRefundAdjustmentTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function makeBranch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko QA', 'address' => 'Jl. QA',
            'latitude' => -6.2, 'longitude' => 106.8166,
        ]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $agen->id]);
        $sales = User::factory()->sales()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id, 'sales_id' => $sales->id]);

        return compact('agen', 'admin', 'keuangan', 'sales', 'konsumen');
    }

    /** Fully-paid COD order with a per-unit sales fee of `feePerUnit`, and an approved return of `units`. */
    private function approvedPaidReturn(array $b, int $qty, int $feePerUnit = 8000, int $units = 1): array
    {
        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Fee Cake', 'slug' => 'fee-cake-'.uniqid(),
            'has_variations' => false, 'base_price' => 100000, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'sales', 'amount' => $feePerUnit, 'is_active' => true]);
        ProductStock::create(['agent_id' => $b['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 20, 'quantity_reserved' => 0]);

        $orderId = $this->actingAs($b['agen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'konsumen_id' => $b['konsumen']->id,
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => $qty]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ])->json('data.id');

        $order = Order::withoutGlobalScopes()->findOrFail($orderId);
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'dikirim'])->assertOk();
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'terkirim'])->assertOk();
        // COD terkirim is still unpaid in cash — the refund gate enforces fully-paid, so mark the
        // COD cash as received through the CANONICAL toggle (keuangan-only markCod), not a fake path.
        $this->actingAs($b['keuangan'])->patchJson("/api/v1/orders/{$order->id}/payment/cod", ['paid' => true])->assertOk();

        $item = OrderItem::where('order_id', $order->id)->firstOrFail();

        $returnId = $this->actingAs($b['konsumen'])->postJson("/api/v1/orders/{$order->id}/returns", [
            'reason' => 'Rusak',
            'items' => [['order_item_id' => $item->id, 'quantity' => $units]],
            'evidence' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertCreated()->json('data.id');

        $this->actingAs($b['admin'])->patchJson("/api/v1/admin/returns/{$returnId}/review", ['approved' => true])->assertOk();

        $returnItemId = ReturnItem::where('return_id', $returnId)->value('id');

        return ['order' => $order->fresh(), 'item' => $item, 'returnItemId' => $returnItemId];
    }

    private function processRefund(array $b, int $returnItemId): void
    {
        // Keuangan marks the refund as actually sent (route gate = super_admin/keuangan; the
        // earlier summary's "second call returns 422" relied at the API level on the status guard).
        $this->actingAs($b['keuangan'])->patchJson("/api/v1/admin/return-items/{$returnItemId}/refund", [])->assertOk();
    }

    private function salesFee(OrderItem $item): Commission
    {
        return Commission::query()->where('order_item_id', $item->id)->where('beneficiary_role', 'sales')->firstOrFail();
    }

    private function assertOriginalRowUntouched(OrderItem $item, float $expectedFee): void
    {
        $commission = $this->salesFee($item);
        $this->assertSame($expectedFee, (float) $commission->amount, 'original ledger row harus immutable');
    }

    public function test_full_refund_cancels_the_whole_sales_fee_but_not_the_agent_fee(): void
    {
        $b = $this->makeBranch();
        $ctx = $this->approvedPaidReturn($b, qty: 3, units: 3);

        $this->processRefund($b, $ctx['returnItemId']);

        $this->assertOriginalRowUntouched($ctx['item'], 24000);
        $this->assertSame(-24000, (int) CommissionAdjustment::query()->where('beneficiary_role', 'sales')->sum('amount'));
        // Agent fee contract untouched.
        $agentRow = Commission::query()->where('order_item_id', $ctx['item']->id)->where('beneficiary_role', 'agent')->first();
        if ($agentRow) {
            $this->assertSame(0, (int) CommissionAdjustment::query()->where('commission_id', $agentRow->id)->count());
        }
        $this->assertDatabaseHas('commission_adjustments', ['policy_version' => 'ws8-owner-2026-10-30']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'commission.sales_fee_refund_adjusted']);
    }

    public function test_partial_refund_cancels_only_the_refunded_units(): void
    {
        $b = $this->makeBranch();
        $ctx = $this->approvedPaidReturn($b, qty: 3, units: 1);

        $this->processRefund($b, $ctx['returnItemId']);

        $this->assertOriginalRowUntouched($ctx['item'], 24000);
        $this->assertSame(-8000, (int) CommissionAdjustment::query()->sum('amount'));
    }

    public function test_partial_and_full_refunds_across_two_lines_in_one_order(): void
    {
        $b = $this->makeBranch();
        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Fee Cake', 'slug' => 'fee-cake-'.uniqid(),
            'has_variations' => false, 'base_price' => 100000, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'sales', 'amount' => 8000, 'is_active' => true]);
        ProductStock::create(['agent_id' => $b['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 20, 'quantity_reserved' => 0]);

        // 3 + 2 units = two order_item lines, each carrying its own fee snapshot.
        $orderId = $this->actingAs($b['agen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'konsumen_id' => $b['konsumen']->id,
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 3], ['product_id' => $product->id, 'quantity' => 2]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ])->json('data.id');
        $order = Order::withoutGlobalScopes()->findOrFail($orderId);
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'dikirim'])->assertOk();
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'terkirim'])->assertOk();
        $this->actingAs($b['keuangan'])->patchJson("/api/v1/orders/{$order->id}/payment/cod", ['paid' => true])->assertOk();

        [$lineA, $lineB] = OrderItem::where('order_id', $order->id)->orderBy('id')->get()->all(); // 3 units (fee 24000), 2 units (fee 16000)

        // SEPARATE return requests — a second return on the SAME item is refused by the system
        // (requestReturn flips the item to `pengembalian` immediately), so partial refunds fan out per line.
        $returnA = $this->actingAs($b['konsumen'])->postJson("/api/v1/orders/{$order->id}/returns", [
            'reason' => 'Rusak',
            'items' => [['order_item_id' => $lineA->id, 'quantity' => 2]], // PARTIAL: 2 of 3 units
            'evidence' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertCreated()->json('data.id');
        $this->actingAs($b['admin'])->patchJson("/api/v1/admin/returns/{$returnA}/review", ['approved' => true])->assertOk();
        $this->processRefund($b, ReturnItem::where('return_id', $returnA)->value('id'));
        // Existing WS-3 semantics: a settled payout reverses the applied payment, so the next
        // refund on the same order needs the finance re-settlement of the COD position first
        // (canonical markCod flip again — not a test shortcut).
        $this->actingAs($b['keuangan'])->patchJson("/api/v1/orders/{$order->id}/payment/cod", ['paid' => true])->assertOk();

        $returnB = $this->actingAs($b['konsumen'])->postJson("/api/v1/orders/{$order->id}/returns", [
            'reason' => 'Rusak',
            'items' => [['order_item_id' => $lineB->id, 'quantity' => 2]], // FULL refund of line B
            'evidence' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertCreated()->json('data.id');
        $this->actingAs($b['admin'])->patchJson("/api/v1/admin/returns/{$returnB}/review", ['approved' => true])->assertOk();
        $this->processRefund($b, ReturnItem::where('return_id', $returnB)->value('id'));

        // Line A: exact per-unit math (2 × snapshot per unit); original row untouched at 24000.
        $this->assertOriginalRowUntouched($lineA, 24000);
        $this->assertSame(-16000, (int) CommissionAdjustment::where('order_item_id', $lineA->id)->sum('amount'));
        // Line B: whole fee cancelled; row intact at 16000.
        $this->assertOriginalRowUntouched($lineB, 16000);
        $this->assertSame(-16000, (int) CommissionAdjustment::where('order_item_id', $lineB->id)->sum('amount'));
        $this->assertCount(2, CommissionAdjustment::query()->get());
    }

    public function test_double_processing_never_creates_a_second_adjustment(): void
    {
        $b = $this->makeBranch();
        $ctx = $this->approvedPaidReturn($b, qty: 2, units: 2);

        $this->processRefund($b, $ctx['returnItemId']);
        // A retried settle on the SAME return line must be refused by the status guard…
        $retry = $this->actingAs($b['keuangan'])->patchJson("/api/v1/admin/return-items/{$ctx['returnItemId']}/refund", []);
        $retry->assertStatus(422);
        // …and even a forced service replay is a no-op (replay safety exists at BOTH levels).
        $this->app->make(\App\Services\Commission\SalesFeeRefundAdjuster::class)
            ->adjustForProcessedReturn(ReturnItem::findOrFail($ctx['returnItemId']), $b['admin']);

        $this->assertSame(1, CommissionAdjustment::query()->count());
    }

    public function test_rejected_return_never_touches_the_fee(): void
    {
        $b = $this->makeBranch();
        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'No Fee Cake', 'slug' => 'nf-cake-'.uniqid(),
            'has_variations' => false, 'base_price' => 50000, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'sales', 'amount' => 8000, 'is_active' => true]);
        ProductStock::create(['agent_id' => $b['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 20, 'quantity_reserved' => 0]);

        $orderId = $this->actingAs($b['agen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'konsumen_id' => $b['konsumen']->id,
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ])->json('data.id');
        $order = Order::withoutGlobalScopes()->findOrFail($orderId);
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'dikirim'])->assertOk();
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'terkirim'])->assertOk();
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();

        $returnId = $this->actingAs($b['konsumen'])->postJson("/api/v1/orders/{$order->id}/returns", [
            'reason' => 'Rusak',
            'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
            'evidence' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertCreated()->json('data.id');

        $this->actingAs($b['admin'])->patchJson("/api/v1/admin/returns/{$returnId}/review", ['approved' => false, 'note' => 'Tidak memenuhi'])->assertOk();

        $item = $item->fresh();
        $salesRow = Commission::query()->where('order_item_id', $item->id)->where('beneficiary_role', 'sales')->first();
        $this->assertNotNull($salesRow);
        $this->assertSame(0, CommissionAdjustment::query()->count());
    }

    public function test_unpaid_order_refund_is_refused_and_writes_nothing(): void
    {
        $b = $this->makeBranch();
        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Unpaid Cake', 'slug' => 'up-cake-'.uniqid(),
            'has_variations' => false, 'base_price' => 50000, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductFee::create(['product_id' => $product->id, 'beneficiary_role' => 'sales', 'amount' => 8000, 'is_active' => true]);
        ProductStock::create(['agent_id' => $b['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 20, 'quantity_reserved' => 0]);

        $orderId = $this->actingAs($b['agen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'konsumen_id' => $b['konsumen']->id,
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ])->json('data.id');
        $order = Order::withoutGlobalScopes()->findOrFail($orderId);
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'dikirim'])->assertOk();
        $this->actingAs($b['admin'])->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'terkirim'])->assertOk();
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();

        $returnId = $this->actingAs($b['konsumen'])->postJson("/api/v1/orders/{$order->id}/returns", [
            'reason' => 'Rusak',
            'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
            'evidence' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertCreated()->json('data.id');
        $this->actingAs($b['admin'])->patchJson("/api/v1/admin/returns/{$returnId}/review", ['approved' => true])->assertOk();
        $returnItemId = ReturnItem::where('return_id', $returnId)->value('id');

        $this->actingAs($b['keuangan'])->patchJson("/api/v1/admin/return-items/{$returnItemId}/refund", [])->assertStatus(422);
        $this->assertSame(0, CommissionAdjustment::query()->count());
    }
}
