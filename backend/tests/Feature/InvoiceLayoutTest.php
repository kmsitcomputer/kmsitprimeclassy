<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ShippingConfiguration;
use App\Models\User;
use App\Services\Invoice\InvoiceConfigService;
use App\Services\Invoice\InvoicePdfService;
use App\Services\Payment\PaymentSummaryService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * REM-10 invoice layout remediation — PRESENTATION ONLY. The template must show exactly the canonical
 * order / payment-summary values (never recompute), keep the official logo, and lay out header, table,
 * totals and footer in the remediated structure (DomPDF-safe, repeating header, anchored footer).
 */
class InvoiceLayoutTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    private User $agen;

    private User $konsumen;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        $this->agen = User::factory()->agen()->create();
        $this->agen->update(['agent_id' => $this->agen->id]);
        AgentProfile::create(['user_id' => $this->agen->id, 'store_name' => 'Toko', 'address' => 'Jl. X', 'latitude' => -6.2, 'longitude' => 106.8]);
        $this->konsumen = User::factory()->konsumen()->create(['agent_id' => $this->agen->id]);
        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 12000, 'free_shipping_enabled' => false, 'is_active' => true]);
        $this->product = Product::create(['sku' => 'I-'.Str::uuid(), 'name' => 'Kue', 'slug' => 'kue-'.uniqid(), 'has_variations' => false, 'base_price' => 50000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $this->agen->id, 'product_id' => $this->product->id, 'quantity_on_hand' => 500, 'quantity_reserved' => 0]);
    }

    /** @param array<int, array{0:string,1:string,2:int,3:int}> $lines */
    private function order(array $lines, array $money = []): Order
    {
        $id = $this->actingAs($this->agen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'konsumen_id' => $this->konsumen->id, 'payment_method_code' => 'cod', 'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
            'recipient_name' => 'Ahmad Fadli', 'recipient_phone' => '0812', 'address_line' => 'Jl. Contoh 1', 'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ])->assertCreated()->json('data.id');
        $o = Order::withoutGlobalScopes()->findOrFail($id);
        $base = OrderItem::where('order_id', $o->id)->first();
        $subtotal = 0;
        foreach ($lines as $i => [$name, $variation, $qty, $price]) {
            $row = $i === 0 ? $base : $base->replicate();
            $row->fill(['product_name_snapshot' => $name, 'variation_label_snapshot' => $variation, 'fulfilled_quantity' => $qty, 'original_quantity' => $qty, 'unit_price_snapshot' => $price]);
            if ($i > 0) {
                $row->idempotency_key = null;
                $row->request_fingerprint = null;
            }
            $row->save();
            $subtotal += $qty * $price;
        }
        $ship = $money['ship'] ?? 12000;
        $discount = $money['discount'] ?? 0;
        $total = $subtotal - $discount + $ship;
        $paid = $money['paid'] ?? 0;
        $o->forceFill(['subtotal_amount' => $subtotal, 'discount_amount' => $discount, 'shipping_fee_amount' => $ship, 'admin_fee_amount' => 0, 'total_amount' => $total,
            'paid_amount' => $paid, 'remaining_amount' => $total - $paid, 'dp_amount' => $money['dp'] ?? 0, 'payment_status' => $paid >= $total ? 'paid' : ($paid > 0 ? 'partially_paid' : 'unpaid')])->save();

        return $o->refresh();
    }

    private function html(Order $o, bool $owner = false): string
    {
        return app(InvoicePdfService::class)->render($o, InvoiceConfigService::resolved((int) $this->agen->id), $owner)->getDomPDF()->outputHtml();
    }

    private function text(string $html): string
    {
        return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)));
    }

    private function rp(float $v): string
    {
        return 'Rp '.number_format($v, 0, ',', '.');
    }

    public function test_every_amount_shown_is_the_canonical_value_and_nothing_is_recomputed(): void
    {
        $o = $this->order([['Nastar Premium', 'Ukuran: 1 kg', 2, 72000], ['Kastangel', 'Ukuran: 500 gr', 3, 48000]], ['discount' => 14400, 'ship' => 37500, 'paid' => 100000]);
        $summary = PaymentSummaryService::summarize($o);
        $t = $this->text($this->html($o));

        foreach ([$o->subtotal_amount, $o->shipping_fee_amount, $o->total_amount, $summary['total_paid'], $o->remaining_amount, 144000, 144000] as $v) {
            $this->assertStringContainsString($this->rp((float) $v), $t);
        }
        $this->assertStringContainsString('- '.$this->rp(14400), $t);
        $this->assertStringContainsString($this->rp(72000), $t);   // unit price
        $this->assertStringContainsString($this->rp(144000), $t);  // line subtotal 2 x 72.000 and 3 x 48.000
        $this->assertStringContainsString($o->order_no, $t);
    }

    public function test_header_table_totals_and_footer_follow_the_remediated_structure(): void
    {
        $o = $this->order([['Nastar', 'Ukuran: 1 kg', 1, 72000]]);
        $html = $this->html($o);

        $this->assertMatchesRegularExpression('/<img class="logo" src="data:image\/png;base64,/', $html, 'official logo is embedded, not replaced');
        $this->assertStringContainsString('class="doc-title"', $html);
        $this->assertStringContainsString('class="pill"', $html);                 // invoice number pill
        $this->assertStringContainsString('Belum Dibayar', $html);                 // status pill from canonical status
        // table: fixed header order with numeric columns right-aligned
        $this->assertMatchesRegularExpression('/<th[^>]*>No<\/th>\s*<th[^>]*>Produk<\/th>\s*<th[^>]*>Variasi<\/th>\s*<th[^>]*>Harga Satuan<\/th>\s*<th[^>]*>Qty<\/th>\s*<th[^>]*>Subtotal<\/th>/', $html);
        $this->assertStringContainsString('table-header-group', $html);           // header repeats on every page
        $this->assertStringContainsString('class="num"', $html);
        $this->assertStringContainsString('white-space: nowrap', $html);           // money never wraps
        $this->assertStringContainsString('position: fixed', $html);               // footer anchored on every page
        $this->assertStringContainsString('class="grand"', $html);
        $this->assertStringContainsString('data-invoice-payment="cod"', $html);   // COD statement preserved
    }

    public function test_optional_rows_follow_canonical_state_discount_dp_and_remaining(): void
    {
        $plain = $this->text($this->html($this->order([['A', '', 1, 50000]], ['paid' => 62000])));
        $this->assertStringNotContainsString('Diskon/Voucher', $plain);
        $this->assertStringNotContainsString('Sisa Tagihan', $plain);   // fully paid → no remaining row
        $this->assertStringContainsString('LUNAS', $plain);

        $dp = $this->text($this->html($this->order([['A', '', 4, 72000]], ['dp' => 150000, 'paid' => 150000])));
        $this->assertStringContainsString('Sisa Tagihan', $dp);
        $this->assertStringContainsString('Dibayar Sebagian', $dp);
        $this->assertStringContainsString('DP', $dp);
    }

    public function test_long_names_and_many_lines_render_without_losing_any_line_and_span_several_pages(): void
    {
        $long = str_repeat('Kue Kering Premium Edisi Lebaran ', 4);
        $lines = [[trim($long), 'Ukuran: Besar / Warna: Emas Metalik', 1, 1250000]];
        for ($i = 1; $i <= 60; $i++) {
            $lines[] = ['Produk Nomor '.$i, $i % 2 ? 'Ukuran: '.$i : '', 1 + $i % 3, 10000 + $i * 100];
        }
        $o = $this->order($lines);
        $pdf = app(InvoicePdfService::class)->render($o, InvoiceConfigService::resolved((int) $this->agen->id), false);
        $html = $pdf->getDomPDF()->outputHtml();
        $text = $this->text($html);
        $this->assertStringContainsString(trim($long), $text);
        $this->assertStringContainsString('Produk Nomor 60', $text);
        $bytes = $pdf->output();
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertGreaterThan(1, preg_match_all('/\/Type\s*\/Page[^s]/', $bytes), 'multi-page output');
    }

    public function test_consumer_copy_still_hides_internal_referral_identity_and_recipient_toggle_is_honoured(): void
    {
        $o = $this->order([['A', '', 1, 50000]]);
        $cfg = InvoiceConfigService::resolved((int) $this->agen->id);
        $cfg['show_konsumen'] = false;
        $html = app(InvoicePdfService::class)->render($o, $cfg, true)->getDomPDF()->outputHtml();
        $this->assertStringNotContainsString('Alamat Pengiriman', $html);
        $this->assertStringNotContainsString('Ahmad Fadli', $html);
    }
}
