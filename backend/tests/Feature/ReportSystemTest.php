<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Report\OrderTransactionReportService;
use App\Support\HumanDate;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/** Management reports (Blueprint §Reports) — transactions, sales/korsal fee hierarchy, cancellations/refunds, courier fee, agent fee totals. */
class ReportSystemTest extends TestCase
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

    private function makeAgentBranch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko QA', 'address' => 'Jl. QA',
            'latitude' => -6.2, 'longitude' => 106.8166,
        ]);
        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id]);
        $sales = User::factory()->sales()->create([
            'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'referral_code' => 'S-'.uniqid(),
        ]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create([
            'agent_id' => $agen->id, 'korsal_id' => $korsal->id, 'sales_id' => $sales->id,
        ]);
        $superAdmin = User::factory()->superAdmin()->create();
        $kurir = User::factory()->kurir()->create(['agent_id' => $agen->id, 'name' => 'Budi Kurir']);
        Courier::create(['type' => 'internal', 'user_id' => $kurir->id, 'agent_id' => $agen->id, 'name' => $kurir->name, 'is_active' => true]);

        return compact('agen', 'korsal', 'sales', 'admin', 'konsumen', 'superAdmin', 'kurir');
    }

    private function makeProduct(User $agen, string $name, int $price, int $stockQty): Product
    {
        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(),
            'has_variations' => false, 'base_price' => $price, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => $stockQty, 'quantity_reserved' => 0]);

        return $product;
    }

    private function placeOrder(User $konsumen, Product $product, int $qty): Order
    {
        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => $qty]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
            'delivery_date' => now()->addDays(3)->toDateString(),
        ]);
        $response->assertCreated();

        return Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
    }

    private function deliverOrder(array $branch, Order $order): void
    {
        // The order (always COD here — see placeOrder) is already 'diproses' on creation.
        $shipmentId = Shipment::query()->where('order_id', $order->id)->value('id');
        $this->actingAs($branch['kurir'])->patchJson("/api/v1/shipments/{$shipmentId}/status", ['status' => 'dikirim'])->assertOk();
        $this->actingAs($branch['kurir'])->patch("/api/v1/shipments/{$shipmentId}/status", ['status' => 'terkirim', 'proof' => UploadedFile::fake()->image('proof.jpg')])->assertOk();
    }

    /* ---------------------------------------------------------------
     * Role gating
     * ------------------------------------------------------------- */

    public function test_only_super_admin_agen_and_admin_may_reach_report_endpoints(): void
    {
        $branch = $this->makeAgentBranch();

        foreach (['sales', 'kurir', 'konsumen'] as $role) {
            $this->actingAs($branch[$role])->getJson('/api/v1/reports/transactions')->assertStatus(403);
            $this->actingAs($branch[$role])->getJson('/api/v1/reports/sales')->assertStatus(403);
            $this->actingAs($branch[$role])->getJson('/api/v1/reports/fees/sales-korsal')->assertStatus(403);
            $this->actingAs($branch[$role])->getJson('/api/v1/reports/cancellations-refunds')->assertStatus(403);
            $this->actingAs($branch[$role])->getJson('/api/v1/reports/fees/courier')->assertStatus(403);
        }

        // korsal is deliberately opened to transactions + sales roster only
        // (Blueprint §Korsal dashboard) — every other report stays forbidden.
        $this->actingAs($branch['korsal'])->getJson('/api/v1/reports/fees/sales-korsal')->assertStatus(403);
        $this->actingAs($branch['korsal'])->getJson('/api/v1/reports/cancellations-refunds')->assertStatus(403);
        $this->actingAs($branch['korsal'])->getJson('/api/v1/reports/fees/courier')->assertStatus(403);
        $this->actingAs($branch['korsal'])->getJson('/api/v1/reports/korsal')->assertStatus(403);
        $this->actingAs($branch['korsal'])->getJson('/api/v1/reports/customers')->assertStatus(403);

        foreach (['super_admin' => $branch['superAdmin'], 'agen' => $branch['agen'], 'admin' => $branch['admin'], 'korsal' => $branch['korsal']] as $actor) {
            $this->actingAs($actor)->getJson('/api/v1/reports/transactions')->assertOk();
        }
    }

    public function test_admin_is_forbidden_from_the_agent_fee_report_but_agen_and_super_admin_are_not(): void
    {
        $branch = $this->makeAgentBranch();

        $this->actingAs($branch['admin'])->getJson('/api/v1/reports/fees/agent')->assertStatus(403);
        $this->actingAs($branch['agen'])->getJson('/api/v1/reports/fees/agent')->assertOk();
        $this->actingAs($branch['superAdmin'])->getJson('/api/v1/reports/fees/agent')->assertOk();
    }

    /* ---------------------------------------------------------------
     * Transactions report
     * ------------------------------------------------------------- */

    public function test_transactions_report_shows_one_row_per_item_with_sales_korsal_courier_and_delivery_date(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Laporan', 40000, 10);
        $order = $this->placeOrder($branch['konsumen'], $product, 2);
        $this->deliverOrder($branch, $order);

        $response = $this->actingAs($branch['agen'])->getJson('/api/v1/reports/transactions');
        $response->assertOk();
        $response->assertJsonStructure(['meta' => ['current_page', 'last_page', 'total']]);

        $row = collect($response->json('data'))->firstWhere('order_id', $order->id);
        $this->assertNotNull($row);
        $this->assertSame([
            'order_no', 'order_date', 'sku', 'product', 'unit_price', 'quantity',
            'item_status', 'subtotal', 'customer', 'delivery_date', 'courier',
            'order_status', 'sales', 'korsal',
            'payment_method', 'grand_total', 'dp_paid', 'total_paid', 'remaining_balance', 'payment_status',
        ], array_keys(OrderTransactionReportService::COLUMNS));
        $this->assertSame($order->order_no, $row['order_no']);
        $this->assertSame('Kue Laporan', $row['product']);
        $this->assertSame(40000.0, (float) $row['unit_price']);
        $this->assertSame(2, (int) $row['quantity']);
        $this->assertSame(80000.0, (float) $row['subtotal']);
        $this->assertSame('Budi', $row['customer']);
        $this->assertSame($branch['sales']->name, $row['sales']);
        $this->assertSame($branch['korsal']->name, $row['korsal']);
        $this->assertSame('Budi Kurir', $row['courier']);
        $this->assertSame('terkirim', $row['item_status']);
        $this->assertSame('terkirim', $row['order_status']);
        $this->assertMatchesRegularExpression('/^\d{2}\/\d{2}\/\d{4}$/', $row['order_date']);
        $this->assertMatchesRegularExpression('/^\d{2}\/\d{2}\/\d{4}$/', $row['delivery_date']);
    }

    /**
     * The item-level transaction report must show DP-paid/total-paid/
     * remaining-balance sourced from Order (PaymentSummaryService formula),
     * repeated identically on every row of the same order — never summed
     * across rows here (that's the financial-summary endpoints' job).
     */
    public function test_transaction_report_shows_dp_paid_total_paid_and_remaining_balance_columns(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue DP Report', 1000000, 10);

        \App\Models\AgentPaymentGatewayConfig::create([
            'agent_id' => $branch['agen']->id,
            'payment_method_id' => \App\Models\PaymentMethod::where('code', 'bank_transfer')->value('id'),
            'environment' => 'sandbox',
            'config' => ['bank_name' => 'BCA', 'account_name' => 'PT Prime', 'account_number' => '123456'],
        ]);

        $orderResponse = $this->actingAs($branch['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'down_payment', 'dp_amount' => 200000,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);
        $orderResponse->assertCreated();
        $order = Order::withoutGlobalScopes()->findOrFail($orderResponse->json('data.id'));

        $this->actingAs($branch['konsumen'])->postJson("/api/v1/orders/{$order->id}/payment/proof", [
            'proof' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertOk();
        $this->actingAs($branch['superAdmin'])->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertOk();

        $rows = collect($this->actingAs($branch['agen'])->getJson('/api/v1/reports/transactions')->json('data'));
        $row = $rows->firstWhere('order_id', $order->id);
        $this->assertNotNull($row);
        $this->assertSame('DP / Down Payment', $row['payment_method']);
        $this->assertSame(1000000.0, (float) $row['grand_total']);
        $this->assertSame(200000.0, (float) $row['dp_paid']);
        $this->assertSame(200000.0, (float) $row['total_paid']);
        $this->assertSame(800000.0, (float) $row['remaining_balance']);
        $this->assertSame('partially_paid', $row['payment_status']);
    }

    public function test_transactions_report_uses_direct_agent_and_korsal_self_purchase_as_sales_referrer(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Referral Langsung', 40000, 10);

        $agentOrder = $this->placeOrder($branch['agen'], $product, 1);
        $korsalOrder = $this->placeOrder($branch['korsal'], $product, 1);

        $response = $this->actingAs($branch['agen'])->getJson('/api/v1/reports/transactions');
        $response->assertOk();
        $rows = collect($response->json('data'));

        $agentRow = $rows->firstWhere('order_id', $agentOrder->id);
        $this->assertSame($branch['agen']->name, $agentRow['sales']);
        $this->assertSame('-', $agentRow['korsal']);

        $korsalRow = $rows->firstWhere('order_id', $korsalOrder->id);
        $this->assertSame($branch['korsal']->name, $korsalRow['sales']);
        $this->assertSame($branch['korsal']->name, $korsalRow['korsal']);
    }

    public function test_transactions_report_is_scoped_to_the_actors_own_agent_branch(): void
    {
        $branchA = $this->makeAgentBranch();
        $branchB = $this->makeAgentBranch();
        $productA = $this->makeProduct($branchA['agen'], 'Kue A', 40000, 10);
        $orderA = $this->placeOrder($branchA['konsumen'], $productA, 1);

        $agenBView = $this->actingAs($branchB['agen'])->getJson('/api/v1/reports/transactions');
        $agenBView->assertOk();
        $this->assertFalse(collect($agenBView->json('data'))->contains('order_id', $orderA->id));

        $superAdminView = $this->actingAs($branchA['superAdmin'])->getJson('/api/v1/reports/transactions');
        $superAdminView->assertOk();
        $this->assertTrue(collect($superAdminView->json('data'))->contains('order_id', $orderA->id));
    }

    public function test_transactions_report_filters_by_status_and_delivery_date(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Filter', 40000, 10);
        $order = $this->placeOrder($branch['konsumen'], $product, 1);

        $deliveryDate = OrderItem::where('order_id', $order->id)->value('requested_delivery_date');

        // COD orders (used by placeOrder) are created straight into 'diproses' — no 'diterima' step to filter on here.
        $matching = $this->actingAs($branch['agen'])->getJson('/api/v1/reports/transactions?status=diproses&delivery_date_from='.$deliveryDate.'&delivery_date_to='.$deliveryDate);
        $matching->assertOk();
        $this->assertTrue(collect($matching->json('data'))->contains('order_id', $order->id));

        $nonMatching = $this->actingAs($branch['agen'])->getJson('/api/v1/reports/transactions?status=terkirim');
        $nonMatching->assertOk();
        $this->assertFalse(collect($nonMatching->json('data'))->contains('order_id', $order->id));
    }

    public function test_transactions_report_can_be_exported_as_xlsx(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Export', 40000, 10);
        $this->placeOrder($branch['konsumen'], $product, 1);

        $response = $this->actingAs($branch['agen'])->get('/api/v1/reports/transactions?export=xlsx');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('laporan-transaksi.xlsx', $response->headers->get('Content-Disposition'));
    }

    /* ---------------------------------------------------------------
     * Sales/korsal fee hierarchy report
     * ------------------------------------------------------------- */

    public function test_sales_korsal_fee_report_aggregates_sales_fee_and_rolls_up_to_korsal(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Fee Hierarki', 40000, 10);
        $this->actingAs($branch['superAdmin'])->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 5000, 'sales_fee' => 3000, 'courier_fee' => 1000,
        ])->assertOk();

        $this->placeOrder($branch['konsumen'], $product, 1);
        $this->placeOrder($branch['konsumen'], $product, 1);

        $response = $this->actingAs($branch['agen'])->getJson('/api/v1/reports/fees/sales-korsal');
        $response->assertOk();

        $salesRow = collect($response->json('data.sales'))->firstWhere('sales_id', $branch['sales']->id);
        $this->assertNotNull($salesRow);
        $this->assertSame(6000.0, (float) $salesRow['total_fee']);
        $this->assertSame(2, $salesRow['transaction_count']);

        $korsalRow = collect($response->json('data.korsal'))->firstWhere('korsal_id', $branch['korsal']->id);
        $this->assertNotNull($korsalRow);
        $this->assertSame(6000.0, (float) $korsalRow['total_fee']);
    }

    /* ---------------------------------------------------------------
     * Cancellations / refunds report
     * ------------------------------------------------------------- */

    public function test_cancellations_refunds_report_lists_cancelled_orders_and_shows_courier_on_returns(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Batal', 40000, 10);

        // A straightforwardly cancelled order.
        $cancelledOrder = $this->placeOrder($branch['konsumen'], $product, 1);
        $this->actingAs($branch['konsumen'])->postJson("/api/v1/orders/{$cancelledOrder->id}/cancel", ['reason' => 'Berubah pikiran'])->assertOk();

        // A delivered + returned item, so the report can show which kurir delivered it.
        $returnedOrder = $this->placeOrder($branch['konsumen'], $product, 1);
        $this->deliverOrder($branch, $returnedOrder);
        $returnedItem = OrderItem::where('order_id', $returnedOrder->id)->firstOrFail();
        $this->actingAs($branch['konsumen'])->post("/api/v1/orders/{$returnedOrder->id}/returns", [
            'items' => [['order_item_id' => $returnedItem->id, 'quantity' => 1, 'restock' => true]],
            'reason' => 'Rusak',
            'evidence' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertCreated();

        $response = $this->actingAs($branch['agen'])->getJson('/api/v1/reports/cancellations-refunds');
        $response->assertOk();

        $this->assertTrue(collect($response->json('data.cancellations'))->contains('id', $cancelledOrder->id));

        $returnRow = collect($response->json('data.returns'))->firstWhere('order_id', $returnedOrder->id);
        $this->assertNotNull($returnRow);
        $this->assertSame('Budi Kurir', $returnRow['courier_name']);
        // Freshly requested, not yet reviewed by admin — refund_status only becomes 'pending' on approval.
        $this->assertSame('not_required', $returnRow['refund_status']);
    }

    /* ---------------------------------------------------------------
     * Courier fee report
     * ------------------------------------------------------------- */

    public function test_courier_fee_report_aggregates_per_courier_and_is_scoped_per_branch(): void
    {
        $branch = $this->makeAgentBranch();
        $otherBranch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Fee Kurir', 40000, 10);
        $this->actingAs($branch['superAdmin'])->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 5000, 'sales_fee' => 3000, 'courier_fee' => 2500,
        ])->assertOk();

        $order = $this->placeOrder($branch['konsumen'], $product, 1);
        $this->deliverOrder($branch, $order);

        $response = $this->actingAs($branch['agen'])->getJson('/api/v1/reports/fees/courier');
        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('courier_name', 'Budi Kurir');
        $this->assertNotNull($row);
        $this->assertSame(2500.0, (float) $row['total_fee']);
        $this->assertSame(1, $row['delivery_count']);

        // The other branch's agen never sees this courier's fee at all.
        $otherView = $this->actingAs($otherBranch['agen'])->getJson('/api/v1/reports/fees/courier');
        $otherView->assertOk();
        $this->assertFalse(collect($otherView->json('data'))->contains('courier_name', 'Budi Kurir'));

        // Nor does the other branch's admin.
        $otherAdminView = $this->actingAs($otherBranch['admin'])->getJson('/api/v1/reports/fees/courier');
        $otherAdminView->assertOk();
        $this->assertFalse(collect($otherAdminView->json('data'))->contains('courier_name', 'Budi Kurir'));

        // This branch's own admin DOES see it.
        $ownAdminView = $this->actingAs($branch['admin'])->getJson('/api/v1/reports/fees/courier');
        $ownAdminView->assertOk();
        $this->assertTrue(collect($ownAdminView->json('data'))->contains('courier_name', 'Budi Kurir'));
    }

    /**
     * "Fee Kurir bukan per Order" — a two-product order delivered by two
     * different couriers must produce one transaction-report row per item,
     * each with ITS OWN courier, never the same courier stamped on every
     * row. Locks OrderTransactionReportService's `leftJoin('shipments',
     * 'sh.id','=','i.shipment_id')` (item-scoped, not order-scoped).
     */
    public function test_transaction_report_shows_the_item_specific_courier_not_the_orders_first_courier(): void
    {
        $branch = $this->makeAgentBranch();
        $secondKurir = User::factory()->kurir()->create(['agent_id' => $branch['agen']->id, 'name' => 'Andi Kurir']);
        Courier::create(['type' => 'internal', 'user_id' => $secondKurir->id, 'agent_id' => $branch['agen']->id, 'name' => $secondKurir->name, 'is_active' => true]);

        $productA = $this->makeProduct($branch['agen'], 'Brownies', 40000, 10);
        $productB = $this->makeProduct($branch['agen'], 'Cake', 60000, 10);

        $response = $this->actingAs($branch['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [
                ['product_id' => $productA->id, 'quantity' => 1],
                ['product_id' => $productB->id, 'quantity' => 1],
            ],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);
        $response->assertCreated();
        $order = Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
        $itemA = OrderItem::where('order_id', $order->id)->where('product_id', $productA->id)->firstOrFail();
        $itemB = OrderItem::where('order_id', $order->id)->where('product_id', $productB->id)->firstOrFail();

        // Item A picked up by Budi, item B by Andi — two independent shipments.
        $this->actingAs($branch['kurir'])->patchJson("/api/v1/shipments/{$itemA->shipment_id}/status", ['status' => 'dikirim'])->assertOk();
        $this->actingAs($secondKurir)->patchJson("/api/v1/shipments/{$itemB->shipment_id}/status", ['status' => 'dikirim'])->assertOk();

        $rows = collect($this->actingAs($branch['agen'])->getJson('/api/v1/reports/transactions')->json('data'))
            ->filter(fn ($r) => $r['order_id'] === $order->id);
        $this->assertCount(2, $rows);

        $rowA = $rows->firstWhere('order_item_id', $itemA->id);
        $rowB = $rows->firstWhere('order_item_id', $itemB->id);
        $this->assertSame('Budi Kurir', $rowA['courier']);
        $this->assertSame('Andi Kurir', $rowB['courier']);
        $this->assertNotSame($rowA['courier'], $rowB['courier'], 'each item must carry its OWN courier, not the order\'s first one');
    }

    /**
     * "Fee Kurir harus PER ITEM" — one Commission row per order_item, each
     * crediting that item's OWN courier; never a single Rp15.000 lump sum
     * on the order.
     */
    public function test_courier_fee_ledger_is_per_item_not_per_order(): void
    {
        $branch = $this->makeAgentBranch();
        $secondKurir = User::factory()->kurir()->create(['agent_id' => $branch['agen']->id, 'name' => 'Andi Kurir']);
        Courier::create(['type' => 'internal', 'user_id' => $secondKurir->id, 'agent_id' => $branch['agen']->id, 'name' => $secondKurir->name, 'is_active' => true]);

        $productA = $this->makeProduct($branch['agen'], 'Brownies Fee', 40000, 10);
        $productB = $this->makeProduct($branch['agen'], 'Cake Fee', 60000, 10);
        $this->actingAs($branch['superAdmin'])->putJson("/api/v1/products/{$productA->id}/fees", [
            'agent_fee' => 1000, 'sales_fee' => 1000, 'courier_fee' => 5000,
        ])->assertOk();
        $this->actingAs($branch['superAdmin'])->putJson("/api/v1/products/{$productB->id}/fees", [
            'agent_fee' => 1000, 'sales_fee' => 1000, 'courier_fee' => 4000,
        ])->assertOk();

        $response = $this->actingAs($branch['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod',
            'items' => [
                ['product_id' => $productA->id, 'quantity' => 1],
                ['product_id' => $productB->id, 'quantity' => 1],
            ],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);
        $response->assertCreated();
        $order = Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
        $itemA = OrderItem::where('order_id', $order->id)->where('product_id', $productA->id)->firstOrFail();
        $itemB = OrderItem::where('order_id', $order->id)->where('product_id', $productB->id)->firstOrFail();

        foreach ([['kurir' => $branch['kurir'], 'item' => $itemA], ['kurir' => $secondKurir, 'item' => $itemB]] as $leg) {
            $this->actingAs($leg['kurir'])->patchJson("/api/v1/shipments/{$leg['item']->shipment_id}/status", ['status' => 'dikirim'])->assertOk();
            $this->actingAs($leg['kurir'])->patch("/api/v1/shipments/{$leg['item']->shipment_id}/status", ['status' => 'terkirim', 'proof' => UploadedFile::fake()->image('p.jpg')])->assertOk();
        }

        // Two distinct item-level fee records, never one order-level Rp9.000 (or Rp15.000-style) lump sum.
        $this->assertDatabaseHas('commissions', [
            'order_id' => $order->id, 'order_item_id' => $itemA->id, 'beneficiary_role' => 'courier',
            'beneficiary_user_id' => $branch['kurir']->id, 'amount' => 5000,
        ]);
        $this->assertDatabaseHas('commissions', [
            'order_id' => $order->id, 'order_item_id' => $itemB->id, 'beneficiary_role' => 'courier',
            'beneficiary_user_id' => $secondKurir->id, 'amount' => 4000,
        ]);
        $this->assertSame(2, \App\Models\Commission::where('order_id', $order->id)->where('beneficiary_role', 'courier')->count());

        $feeReport = $this->actingAs($branch['agen'])->getJson('/api/v1/reports/fees/courier');
        $budiRow = collect($feeReport->json('data'))->firstWhere('courier_name', 'Budi Kurir');
        $andiRow = collect($feeReport->json('data'))->firstWhere('courier_name', 'Andi Kurir');
        $this->assertSame(5000.0, (float) $budiRow['total_fee']);
        $this->assertSame(4000.0, (float) $andiRow['total_fee']);
    }

    /**
     * Retrying/refreshing the same terkirim transition (status re-saved,
     * report refresh, resi reprint) must never double-post the same item's
     * courier fee.
     */
    public function test_courier_fee_is_idempotent_and_never_duplicated_on_retry(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Idempotent', 40000, 10);
        $this->actingAs($branch['superAdmin'])->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 1000, 'sales_fee' => 1000, 'courier_fee' => 5000,
        ])->assertOk();

        $order = $this->placeOrder($branch['konsumen'], $product, 1);
        $this->deliverOrder($branch, $order);

        $item = OrderItem::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(1, \App\Models\Commission::where('order_item_id', $item->id)->where('beneficiary_role', 'courier')->count());

        // Directly invoke the same fee-posting path a second time (simulating a retried
        // job/callback/report refresh) — CourierService::recordCommissionsForItems must
        // no-op, never insert a second Rp5.000 row.
        app(\App\Services\Order\CourierService::class)->recordCommissionsForItems(
            collect([$item->fresh()]), \App\Models\Courier::where('user_id', $branch['kurir']->id)->first()
        );

        $this->assertSame(1, \App\Models\Commission::where('order_item_id', $item->id)->where('beneficiary_role', 'courier')->count());
        $this->assertEquals(5000.0, \App\Models\Commission::where('order_item_id', $item->id)->where('beneficiary_role', 'courier')->sum('amount'));
    }

    /* ---------------------------------------------------------------
     * Agent fee totals
     * ------------------------------------------------------------- */

    public function test_agent_fee_report_shows_super_admin_all_agents_but_agen_only_their_own(): void
    {
        $branchA = $this->makeAgentBranch();
        $branchB = $this->makeAgentBranch();
        $productA = $this->makeProduct($branchA['agen'], 'Kue Agen A', 40000, 10);
        $productB = $this->makeProduct($branchB['agen'], 'Kue Agen B', 40000, 10);

        $this->actingAs($branchA['superAdmin'])->putJson("/api/v1/products/{$productA->id}/fees", [
            'agent_fee' => 7000, 'sales_fee' => 1000, 'courier_fee' => 500,
        ])->assertOk();
        $this->actingAs($branchB['superAdmin'])->putJson("/api/v1/products/{$productB->id}/fees", [
            'agent_fee' => 9000, 'sales_fee' => 1000, 'courier_fee' => 500,
        ])->assertOk();

        $this->placeOrder($branchA['konsumen'], $productA, 1);
        $this->placeOrder($branchB['konsumen'], $productB, 1);

        $superAdminView = $this->actingAs($branchA['superAdmin'])->getJson('/api/v1/reports/fees/agent');
        $superAdminView->assertOk();
        $this->assertTrue(collect($superAdminView->json('data'))->contains('agent_id', $branchA['agen']->id));
        $this->assertTrue(collect($superAdminView->json('data'))->contains('agent_id', $branchB['agen']->id));

        $agenAView = $this->actingAs($branchA['agen'])->getJson('/api/v1/reports/fees/agent');
        $agenAView->assertOk();
        $this->assertCount(1, $agenAView->json('data'));
        $this->assertSame(7000.0, (float) $agenAView->json('data.0.total_fee'));

        // super_admin can also narrow this roster down to one agent via ?agent_id=.
        $filtered = $this->actingAs($branchA['superAdmin'])->getJson('/api/v1/reports/fees/agent?agent_id='.$branchA['agen']->id);
        $filtered->assertOk();
        $this->assertCount(1, $filtered->json('data'));
        $this->assertSame($branchA['agen']->id, $filtered->json('data.0.agent_id'));
    }

    /**
     * "Jumlah sales yang menjual" must be a distinct-seller count, never an
     * accumulation of order/transaction counts — one sales placing 2 orders
     * and another placing 1 must report active_sales_count = 2, even though
     * transaction_count is 3.
     */
    public function test_agent_and_korsal_reports_count_distinct_active_sales_not_transactions(): void
    {
        $branch = $this->makeAgentBranch();
        $secondSales = User::factory()->sales()->create([
            'agent_id' => $branch['agen']->id, 'korsal_id' => $branch['korsal']->id, 'referral_code' => 'S-'.uniqid(),
        ]);
        $secondKonsumen = User::factory()->konsumen()->create([
            'agent_id' => $branch['agen']->id, 'korsal_id' => $branch['korsal']->id, 'sales_id' => $secondSales->id,
        ]);
        $product = $this->makeProduct($branch['agen'], 'Kue Distinct Sales', 40000, 10);
        $this->actingAs($branch['superAdmin'])->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 5000, 'sales_fee' => 3000, 'courier_fee' => 1000,
        ])->assertOk();

        $this->placeOrder($branch['konsumen'], $product, 1);
        $this->placeOrder($branch['konsumen'], $product, 1);
        $this->placeOrder($secondKonsumen, $product, 1);

        $agentFees = $this->actingAs($branch['superAdmin'])->getJson('/api/v1/reports/fees/agent');
        $agentFees->assertOk();
        $agentRow = collect($agentFees->json('data'))->firstWhere('agent_id', $branch['agen']->id);
        $this->assertNotNull($agentRow);
        $this->assertSame(3, $agentRow['transaction_count']);
        $this->assertSame(2, $agentRow['active_sales_count']);

        $salesKorsal = $this->actingAs($branch['agen'])->getJson('/api/v1/reports/fees/sales-korsal');
        $salesKorsal->assertOk();
        $korsalRow = collect($salesKorsal->json('data.korsal'))->firstWhere('korsal_id', $branch['korsal']->id);
        $this->assertNotNull($korsalRow);
        $this->assertSame(3, $korsalRow['transaction_count']);
        $this->assertSame(2, $korsalRow['active_sales_count']);
    }

    /* ---------------------------------------------------------------
     * Payment status report
     * ------------------------------------------------------------- */

    public function test_payment_status_report_groups_orders_by_agent_and_status(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Status Bayar', 40000, 10);

        $this->placeOrder($branch['konsumen'], $product, 1); // cod -> unpaid until delivered/paid

        $response = $this->actingAs($branch['agen'])->getJson('/api/v1/reports/payment-status');
        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('agent_id', $branch['agen']->id);
        $this->assertNotNull($row);
        $this->assertGreaterThanOrEqual(1, $row['order_count']);
    }

    /* ---------------------------------------------------------------
     * Finance summary
     * ------------------------------------------------------------- */

    public function test_finance_summary_reports_platform_wide_totals_for_super_admin_and_scoped_for_agen(): void
    {
        $branchA = $this->makeAgentBranch();
        $branchB = $this->makeAgentBranch();
        $productA = $this->makeProduct($branchA['agen'], 'Kue Finance A', 40000, 10);
        $productB = $this->makeProduct($branchB['agen'], 'Kue Finance B', 40000, 10);

        $this->placeOrder($branchA['konsumen'], $productA, 1);
        $this->placeOrder($branchB['konsumen'], $productB, 1);

        $superAdminView = $this->actingAs($branchA['superAdmin'])->getJson('/api/v1/reports/finance-summary');
        $superAdminView->assertOk();
        $this->assertSame(2, $superAdminView->json('data.total_orders'));

        $agenAView = $this->actingAs($branchA['agen'])->getJson('/api/v1/reports/finance-summary');
        $agenAView->assertOk();
        $this->assertSame(1, $agenAView->json('data.total_orders'));
    }

    /**
     * "Total pembayaran yang sudah diterima" must include verified-but-
     * partial DP money, not only orders that reached full 'paid' status —
     * financeSummary.total_received sums Order.paid_amount across every
     * status, distinct from total_transactions ("Lunas" value only).
     */
    public function test_finance_summary_total_received_includes_partially_paid_dp_money(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue DP Summary', 1000000, 10);

        \App\Models\AgentPaymentGatewayConfig::create([
            'agent_id' => $branch['agen']->id,
            'payment_method_id' => \App\Models\PaymentMethod::where('code', 'bank_transfer')->value('id'),
            'environment' => 'sandbox',
            'config' => ['bank_name' => 'BCA', 'account_name' => 'PT Prime', 'account_number' => '123456'],
        ]);

        $orderResponse = $this->actingAs($branch['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'down_payment', 'dp_amount' => 300000,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ]);
        $orderResponse->assertCreated();
        $order = Order::withoutGlobalScopes()->findOrFail($orderResponse->json('data.id'));

        $this->actingAs($branch['konsumen'])->postJson("/api/v1/orders/{$order->id}/payment/proof", [
            'proof' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertOk();
        $this->actingAs($branch['superAdmin'])->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertOk();

        $this->assertSame('partially_paid', $order->fresh()->payment_status);

        $summary = $this->actingAs($branch['agen'])->getJson('/api/v1/reports/finance-summary');
        $summary->assertOk();
        // The DP order never reached 'paid', so total_transactions ("Lunas" only) stays 0...
        $this->assertSame(0.0, (float) $summary->json('data.total_transactions'));
        // ...but total_received must show the real Rp300.000 already collected.
        $this->assertSame(300000.0, (float) $summary->json('data.total_received'));
        $this->assertSame(700000.0, (float) $summary->json('data.total_outstanding'));
        $this->assertSame(700000.0, (float) $summary->json('data.total_dp_outstanding'));
    }

    /* ---------------------------------------------------------------
     * Couriers per agent
     * ------------------------------------------------------------- */

    public function test_couriers_per_agent_report_counts_only_that_agents_couriers(): void
    {
        $branchA = $this->makeAgentBranch();
        $branchB = $this->makeAgentBranch();
        $extraKurir = User::factory()->kurir()->create(['agent_id' => $branchA['agen']->id, 'name' => 'Kurir Kedua']);
        Courier::create(['type' => 'internal', 'user_id' => $extraKurir->id, 'agent_id' => $branchA['agen']->id, 'name' => $extraKurir->name, 'is_active' => true]);

        $response = $this->actingAs($branchA['superAdmin'])->getJson('/api/v1/reports/couriers-per-agent');
        $response->assertOk();
        $rowA = collect($response->json('data'))->firstWhere('agent_id', $branchA['agen']->id);
        $rowB = collect($response->json('data'))->firstWhere('agent_id', $branchB['agen']->id);
        $this->assertSame(2, $rowA['courier_count']);
        $this->assertSame(1, $rowB['courier_count']);
    }

    /* ---------------------------------------------------------------
     * Agen dashboard roster reports (customers/korsal/sales/couriers)
     * ------------------------------------------------------------- */

    /**
     * Super_admin can now narrow every cross-agent report to one agent via
     * ?agent_id=; a non-super_admin sending the same param must stay locked
     * to their own branch regardless (never a bypass).
     */
    public function test_super_admin_can_filter_every_report_by_agent_id_and_non_super_admin_cannot_use_it_to_escape_their_branch(): void
    {
        $branchA = $this->makeAgentBranch();
        $branchB = $this->makeAgentBranch();
        $productA = $this->makeProduct($branchA['agen'], 'Kue Filter Agen A', 40000, 10);
        $productB = $this->makeProduct($branchB['agen'], 'Kue Filter Agen B', 40000, 10);
        $this->placeOrder($branchA['konsumen'], $productA, 1);
        $this->placeOrder($branchB['konsumen'], $productB, 1);

        // customersReport
        $customers = $this->actingAs($branchA['superAdmin'])->getJson('/api/v1/reports/customers?agent_id='.$branchA['agen']->id);
        $customers->assertOk();
        $this->assertTrue(collect($customers->json('data'))->contains('konsumen_id', $branchA['konsumen']->id));
        $this->assertFalse(collect($customers->json('data'))->contains('konsumen_id', $branchB['konsumen']->id));

        // korsalReport
        $korsal = $this->actingAs($branchA['superAdmin'])->getJson('/api/v1/reports/korsal?agent_id='.$branchA['agen']->id);
        $korsal->assertOk();
        $this->assertTrue(collect($korsal->json('data'))->contains('korsal_id', $branchA['korsal']->id));
        $this->assertFalse(collect($korsal->json('data'))->contains('korsal_id', $branchB['korsal']->id));

        // salesReport
        $sales = $this->actingAs($branchA['superAdmin'])->getJson('/api/v1/reports/sales?agent_id='.$branchA['agen']->id);
        $sales->assertOk();
        $this->assertTrue(collect($sales->json('data'))->contains('sales_id', $branchA['sales']->id));
        $this->assertFalse(collect($sales->json('data'))->contains('sales_id', $branchB['sales']->id));

        // courierReport
        $couriers = $this->actingAs($branchA['superAdmin'])->getJson('/api/v1/reports/couriers?agent_id='.$branchA['agen']->id);
        $couriers->assertOk();
        $this->assertTrue(collect($couriers->json('data'))->contains('courier_id', $branchA['kurir']->courierProfile->id));

        // transactions
        $transactions = $this->actingAs($branchA['superAdmin'])->getJson('/api/v1/reports/transactions?agent_id='.$branchA['agen']->id);
        $transactions->assertOk();
        $this->assertTrue(collect($transactions->json('data'))->every(fn ($r) => $r['agent_id'] === $branchA['agen']->id));

        // dashboardSummary
        $summary = $this->actingAs($branchA['superAdmin'])->getJson('/api/v1/dashboard/summary?agent_id='.$branchA['agen']->id);
        $summary->assertOk();
        $this->assertSame(1, $summary->json('data.total_orders'));

        // A non-super_admin sending ?agent_id=<other branch> stays locked to their own branch.
        $escapeAttempt = $this->actingAs($branchA['agen'])->getJson('/api/v1/reports/customers?agent_id='.$branchB['agen']->id);
        $escapeAttempt->assertOk();
        $this->assertFalse(collect($escapeAttempt->json('data'))->contains('konsumen_id', $branchB['konsumen']->id));
    }

    public function test_customers_report_lists_only_konsumen_who_transacted_in_this_branch(): void
    {
        $branch = $this->makeAgentBranch();
        $otherBranch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Pelanggan', 40000, 10);

        $this->placeOrder($branch['konsumen'], $product, 1);
        $this->placeOrder($branch['konsumen'], $product, 2);

        $response = $this->actingAs($branch['agen'])->getJson('/api/v1/reports/customers');
        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('konsumen_id', $branch['konsumen']->id);
        $this->assertNotNull($row);
        $this->assertSame(2, $row['order_count']);

        $otherAgentView = $this->actingAs($otherBranch['agen'])->getJson('/api/v1/reports/customers');
        $otherAgentView->assertOk();
        $this->assertFalse(collect($otherAgentView->json('data'))->contains('konsumen_id', $branch['konsumen']->id));
    }

    public function test_korsal_report_is_a_full_roster_including_korsal_with_zero_transactions(): void
    {
        $branch = $this->makeAgentBranch();
        // No orders placed at all in this branch — the korsal must still appear, with zeros.
        $response = $this->actingAs($branch['agen'])->getJson('/api/v1/reports/korsal');
        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('korsal_id', $branch['korsal']->id);
        $this->assertNotNull($row, 'a korsal with zero activity must still be listed');
        $this->assertSame(0, $row['transaction_count']);
        $this->assertSame(0, $row['active_sales_count']);
    }

    public function test_sales_report_is_a_full_roster_and_supports_search_filter(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Sales', 40000, 10);
        $this->actingAs($branch['superAdmin'])->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 5000, 'sales_fee' => 3000, 'courier_fee' => 1000,
        ])->assertOk();
        $this->placeOrder($branch['konsumen'], $product, 1);

        $response = $this->actingAs($branch['agen'])->getJson('/api/v1/reports/sales');
        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('sales_id', $branch['sales']->id);
        $this->assertNotNull($row);
        $this->assertSame(1, $row['transaction_count']);
        $this->assertSame(1, $row['customer_count']);
        $this->assertSame(3000.0, (float) $row['total_fee']);
        $this->assertSame($branch['korsal']->name, $row['korsal_name']);

        $searchResponse = $this->actingAs($branch['agen'])->getJson('/api/v1/reports/sales?search='.urlencode($branch['sales']->name));
        $searchResponse->assertOk();
        $this->assertCount(1, $searchResponse->json('data'));
    }

    public function test_korsal_can_reach_sales_report_and_transactions_but_only_for_their_own_sales_never_a_sibling_korsals(): void
    {
        $branch = $this->makeAgentBranch();
        // A second korsal in the SAME agen branch, with its own sales — the
        // scenario BelongsToAgentScope alone (agent_id only) cannot protect.
        $otherKorsal = User::factory()->korsal()->create(['agent_id' => $branch['agen']->id]);
        $otherSales = User::factory()->sales()->create([
            'agent_id' => $branch['agen']->id, 'korsal_id' => $otherKorsal->id, 'referral_code' => 'S-'.uniqid(),
        ]);
        User::factory()->konsumen()->create([
            'agent_id' => $branch['agen']->id, 'korsal_id' => $otherKorsal->id, 'sales_id' => $otherSales->id,
        ]);

        $product = $this->makeProduct($branch['agen'], 'Kue Korsal Isolasi', 40000, 10);
        $this->placeOrder($branch['konsumen'], $product, 1);

        $salesReport = $this->actingAs($branch['korsal'])->getJson('/api/v1/reports/sales');
        $salesReport->assertOk();
        $ids = collect($salesReport->json('data'))->pluck('sales_id');
        $this->assertTrue($ids->contains($branch['sales']->id), 'must see its own sales');
        $this->assertFalse($ids->contains($otherSales->id), 'must never see a sibling korsals sales');

        $transactions = $this->actingAs($branch['korsal'])->getJson('/api/v1/reports/transactions');
        $transactions->assertOk();
        $korsalIds = collect($transactions->json('data'))->pluck('korsal_id')->unique()->filter();
        $this->assertEqualsCanonicalizing([$branch['korsal']->id], $korsalIds->values()->all());
    }

    public function test_courier_report_includes_delivery_fee_and_return_count(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Kurir Roster', 40000, 10);
        $this->actingAs($branch['superAdmin'])->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 5000, 'sales_fee' => 3000, 'courier_fee' => 1500,
        ])->assertOk();

        $order = $this->placeOrder($branch['konsumen'], $product, 1);
        $this->deliverOrder($branch, $order);
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();
        $this->actingAs($branch['konsumen'])->post("/api/v1/orders/{$order->id}/returns", [
            'items' => [['order_item_id' => $item->id, 'quantity' => 1, 'restock' => true]],
            'reason' => 'Rusak', 'evidence' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertCreated();

        $response = $this->actingAs($branch['agen'])->getJson('/api/v1/reports/couriers');
        $response->assertOk();
        $row = collect($response->json('data'))->first();
        $this->assertNotNull($row);
        $this->assertSame(1, $row['delivery_count']);
        $this->assertSame(1500.0, (float) $row['total_fee']);
        $this->assertSame(1, $row['return_count']);
    }

    public function test_dashboard_summary_is_scoped_to_the_actors_own_branch(): void
    {
        $branchA = $this->makeAgentBranch();
        $branchB = $this->makeAgentBranch();
        $productA = $this->makeProduct($branchA['agen'], 'Kue Summary A', 40000, 10);
        $this->placeOrder($branchA['konsumen'], $productA, 1);

        $agenAView = $this->actingAs($branchA['agen'])->getJson('/api/v1/dashboard/summary');
        $agenAView->assertOk();
        $this->assertSame(1, $agenAView->json('data.total_orders'));
        $this->assertSame(1, $agenAView->json('data.total_customers'));
        $this->assertSame(1, $agenAView->json('data.total_korsal'));
        $this->assertSame(1, $agenAView->json('data.total_sales'));

        $agenBView = $this->actingAs($branchB['agen'])->getJson('/api/v1/dashboard/summary');
        $agenBView->assertOk();
        $this->assertSame(0, $agenBView->json('data.total_orders'));
    }

    public function test_dashboard_summary_narrows_further_for_korsal_and_sales_never_the_whole_agent_branch(): void
    {
        $branch = $this->makeAgentBranch();
        $otherKorsal = User::factory()->korsal()->create(['agent_id' => $branch['agen']->id]);
        $otherSales = User::factory()->sales()->create([
            'agent_id' => $branch['agen']->id, 'korsal_id' => $otherKorsal->id, 'referral_code' => 'S-'.uniqid(),
        ]);
        $otherKonsumen = User::factory()->konsumen()->create([
            'agent_id' => $branch['agen']->id, 'korsal_id' => $otherKorsal->id, 'sales_id' => $otherSales->id,
        ]);
        $product = $this->makeProduct($branch['agen'], 'Kue Summary Korsal', 40000, 10);
        $this->placeOrder($otherKonsumen, $product, 1);

        // The ORIGINAL branch's korsal/sales placed zero orders of their own —
        // seeing the sibling korsal/sales's order above would be a leak.
        $korsalView = $this->actingAs($branch['korsal'])->getJson('/api/v1/dashboard/summary');
        $korsalView->assertOk();
        $this->assertSame(0, $korsalView->json('data.total_orders'));
        $this->assertSame(0, $korsalView->json('data.total_korsal'), 'korsal must never see a branch-wide headcount');
        $this->assertSame(0, $korsalView->json('data.total_sales'));

        $salesView = $this->actingAs($branch['sales'])->getJson('/api/v1/dashboard/summary');
        $salesView->assertOk();
        $this->assertSame(0, $salesView->json('data.total_orders'));

        // The super_admin/agen view still sees the whole branch's real total.
        $agenView = $this->actingAs($branch['agen'])->getJson('/api/v1/dashboard/summary');
        $this->assertSame(1, $agenView->json('data.total_orders'));
    }

    /* ---------------------------------------------------------------
     * Sales' own customer roster + fee ("Konsumen Saya")
     * ------------------------------------------------------------- */

    public function test_sales_customers_report_lists_only_this_sales_own_konsumen_with_fee_earned(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Konsumen Sales', 40000, 10);
        $this->actingAs($branch['superAdmin'])->putJson("/api/v1/products/{$product->id}/fees", [
            'agent_fee' => 5000, 'sales_fee' => 3000, 'courier_fee' => 1000,
        ])->assertOk();

        $this->placeOrder($branch['konsumen'], $product, 1);
        $this->placeOrder($branch['konsumen'], $product, 1);

        $response = $this->actingAs($branch['sales'])->getJson('/api/v1/reports/my-customers');
        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('konsumen_id', $branch['konsumen']->id);
        $this->assertNotNull($row);
        $this->assertSame(2, $row['order_count']);
        $this->assertSame(6000.0, (float) $row['total_fee']);
        // "jumlah konsumen" is a summary stat (meta.total from the grouped
        // query), never a repeating per-row column — exactly 1 distinct konsumen here.
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame(6000.0, (float) $response->json('meta.total_fee'));
    }

    public function test_sales_customers_report_never_shows_another_sales_konsumen(): void
    {
        $branch = $this->makeAgentBranch();
        $otherSales = User::factory()->sales()->create([
            'agent_id' => $branch['agen']->id, 'korsal_id' => $branch['korsal']->id, 'referral_code' => 'S-'.uniqid(),
        ]);
        $otherKonsumen = User::factory()->konsumen()->create([
            'agent_id' => $branch['agen']->id, 'korsal_id' => $branch['korsal']->id, 'sales_id' => $otherSales->id,
        ]);
        $product = $this->makeProduct($branch['agen'], 'Kue Isolasi Sales', 40000, 10);
        $this->placeOrder($otherKonsumen, $product, 1);

        $response = $this->actingAs($branch['sales'])->getJson('/api/v1/reports/my-customers');
        $response->assertOk();
        $this->assertFalse(collect($response->json('data'))->contains('konsumen_id', $otherKonsumen->id));
        $this->assertSame(0, $response->json('meta.total'));
    }

    public function test_only_sales_role_may_reach_my_customers_report(): void
    {
        $branch = $this->makeAgentBranch();

        foreach (['agen', 'korsal', 'admin', 'superAdmin', 'kurir', 'konsumen'] as $role) {
            $this->actingAs($branch[$role])->getJson('/api/v1/reports/my-customers')->assertStatus(403);
        }
    }

    /* ---------------------------------------------------------------
     * Product + SKU presentation (report/dashboard standardisation)
     * ------------------------------------------------------------- */

    public function test_transactions_report_row_includes_historical_sku_snapshot(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue SKU Laporan', 40000, 10);
        $this->placeOrder($branch['konsumen'], $product, 1);

        $response = $this->actingAs($branch['agen'])->getJson('/api/v1/reports/transactions');
        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('product', 'Kue SKU Laporan');
        $this->assertNotNull($row);
        $this->assertSame($product->sku, $row['sku']);
    }

    /**
     * A live catalog SKU change must never rewrite a past transaction — the
     * report reads the frozen sku_snapshot, not the current product row.
     */
    public function test_transactions_report_uses_sku_snapshot_not_the_live_catalog_sku(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue SKU Beku', 40000, 10);
        $order = $this->placeOrder($branch['konsumen'], $product, 1);
        $frozenSku = OrderItem::where('order_id', $order->id)->value('sku_snapshot');

        $product->update(['sku' => 'KATALOG-BARU-999']);

        $response = $this->actingAs($branch['agen'])->getJson('/api/v1/reports/transactions');
        $row = collect($response->json('data'))->firstWhere('order_id', $order->id);
        $this->assertSame($frozenSku, $row['sku']);
        $this->assertNotSame('KATALOG-BARU-999', $row['sku']);
    }

    /** Historical rows with no SKU must not break the report or leave an ambiguous blank cell. */
    public function test_transactions_report_returns_dash_for_legacy_rows_without_sku(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue SKU Lama', 40000, 10);
        $order = $this->placeOrder($branch['konsumen'], $product, 1);
        OrderItem::where('order_id', $order->id)->update(['sku_snapshot' => null]);

        $response = $this->actingAs($branch['agen'])->getJson('/api/v1/reports/transactions');
        $row = collect($response->json('data'))->firstWhere('order_id', $order->id);
        $this->assertNotNull($row);
        $this->assertSame('-', $row['sku']);
    }

    public function test_courier_order_items_include_sku_snapshot(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Kurir SKU', 40000, 10);
        $order = $this->placeOrder($branch['konsumen'], $product, 1);

        $response = $this->actingAs($branch['kurir'])->getJson('/api/v1/kurir/orders');
        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $order->id);
        $this->assertNotNull($row);
        $this->assertSame($product->sku, $row['items'][0]['sku']);
    }

    public function test_return_request_resource_includes_sku_snapshot(): void
    {
        $branch = $this->makeAgentBranch();
        $product = $this->makeProduct($branch['agen'], 'Kue Retur SKU', 40000, 10);
        $order = $this->placeOrder($branch['konsumen'], $product, 1);
        $this->deliverOrder($branch, $order);
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();
        $this->actingAs($branch['konsumen'])->post("/api/v1/orders/{$order->id}/returns", [
            'items' => [['order_item_id' => $item->id, 'quantity' => 1, 'restock' => true]],
            'reason' => 'Rusak',
            'evidence' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertCreated();

        $response = $this->actingAs($branch['admin'])->getJson('/api/v1/admin/returns');
        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('order_id', $order->id);
        $this->assertNotNull($row);
        $this->assertSame($product->sku, $row['items'][0]['sku']);
    }

    public function test_human_date_formats_as_indonesian_day_month_year_without_time(): void
    {
        $this->assertSame('13 September 2026', HumanDate::date('2026-09-13 14:30:00'));
        $this->assertSame('1 Januari 2026', HumanDate::date('2026-01-01'));
        $this->assertNull(HumanDate::date(null));
    }
}
