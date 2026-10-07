<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ShippingConfiguration;
use App\Models\User;
use App\Services\Invoice\InvoiceConfigService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * IMP-002 — Dynamic invoice.
 *
 * Covers: authorization (owner konsumen + same-branch financial roles only),
 * config scoping (super_admin global + agen own branch; admin/others denied),
 * and that the rendered PDF responds 200 with application/pdf (financial
 * truth flows from canonical Order snapshots, not the PDF view).
 */
class InvoiceTest extends TestCase
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
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true]);

        $product = Product::create(['sku' => 'I-'.Str::uuid(), 'name' => 'Kue', 'slug' => 'kue-'.uniqid(), 'has_variations' => false, 'base_price' => 50000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 50, 'quantity_reserved' => 0]);

        $this->b = compact('agen', 'admin', 'keuangan', 'konsumen', 'product');
    }

    private function placeOrder(): Order
    {
        $id = $this->actingAs($this->b['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $this->b['product']->id, 'quantity' => 1]],
                'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated()->json('data.id');

        return Order::withoutGlobalScopes()->findOrFail($id);
    }

    public function test_order_owner_and_same_branch_financial_roles_can_download_invoice(): void
    {
        $order = $this->placeOrder();

        $this->actingAs($this->b['konsumen'])->getJson("/api/v1/orders/{$order->id}/invoice")->assertOk();
        $this->actingAs($this->b['admin'])->getJson("/api/v1/orders/{$order->id}/invoice")->assertOk();
        $this->actingAs($this->b['keuangan'])->getJson("/api/v1/orders/{$order->id}/invoice")->assertOk();
        $this->actingAs($this->b['agen'])->getJson("/api/v1/orders/{$order->id}/invoice")->assertOk();
    }

    public function test_cross_agent_and_unauthorized_roles_cannot_download_invoice(): void
    {
        $order = $this->placeOrder();

        // Cross-agent: the canonical BelongsToAgentScope makes the order 404
        // (silently invisible) — the same protection every agent-scoped
        // endpoint uses. Same-branch kurir (no order-view authority) is
        // denied by the policy (403) — both are denial, just different
        // canonical forms.
        $other = User::factory()->agen()->create();
        $other->update(['agent_id' => $other->id]);
        $kurir = User::factory()->kurir()->create(['agent_id' => $this->b['agen']->id]);
        // IMP-003: a koordinator-kurir (operational dispatcher) may VIEW
        // the order but must never download the FINANCIAL invoice PDF.
        $koordinator = User::factory()->koordinatorKurir()->create(['agent_id' => $this->b['agen']->id]);

        $this->actingAs($other)->getJson("/api/v1/orders/{$order->id}/invoice")->assertNotFound();
        $this->actingAs($kurir)->getJson("/api/v1/orders/{$order->id}/invoice")->assertForbidden();
        $this->actingAs($koordinator)->getJson("/api/v1/orders/{$order->id}/invoice")->assertForbidden();
    }

    public function test_invoice_returns_pdf_content_type(): void
    {
        $order = $this->placeOrder();
        $response = $this->actingAs($this->b['konsumen'])->get("/api/v1/orders/{$order->id}/invoice");
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type') ?? '');
    }

    public function test_consumer_invoice_never_exposes_internal_referral_identities_even_when_toggles_are_on(): void
    {
        // Distinctive internal names — the OrderResource customer projection
        // hides these; the new PDF must not bypass that boundary (A1-13).
        $sales = User::factory()->sales()->create([
            'agent_id' => $this->b['agen']->id, 'name' => 'INTERNAL SALES ALPHA',
        ]);
        $korsal = User::factory()->korsal()->create([
            'agent_id' => $this->b['agen']->id, 'name' => 'INTERNAL KORSAL BETA',
        ]);
        $order = $this->placeOrder();
        $order->update(['sales_id' => $sales->id, 'korsal_id' => $korsal->id]);

        // Every show flag ON — the audit probe's exact scenario.
        InvoiceConfigService::upsert((int) $this->b['agen']->id, [
            'show_sales' => true, 'show_korsal' => true,
        ]);

        $config = InvoiceConfigService::resolved((int) $order->agent_id);
        $summary = \App\Services\Payment\PaymentSummaryService::summarize($order);
        $items = $order->items()->with('shipment')->get();

        $consumerHtml = view('pdf.invoice', [
            'order' => $order->fresh()->load('sales', 'korsal'),
            'config' => $config,
            'showInternalReferral' => false,
            'showRecipient' => (bool) ($config['show_konsumen'] ?? true),
            'logo_url' => null,
            'summary' => $summary,
            'items' => $items,
            'now' => now(),
        ])->render();
        $this->assertStringNotContainsString('INTERNAL SALES ALPHA', $consumerHtml);
        $this->assertStringNotContainsString('INTERNAL KORSAL BETA', $consumerHtml);

        // The consumer's own identity + totals remain.
        $this->assertStringContainsString($order->recipient_name_snapshot, $consumerHtml);

        // A staff viewer keeps the permitted content (controller passes
        // viewerIsOwner:false; InvoiceController encodes this exact gate).
        $staffHtml = view('pdf.invoice', [
            'order' => $order->fresh()->load('sales', 'korsal'),
            'config' => $config,
            'showInternalReferral' => true,
            'showRecipient' => (bool) ($config['show_konsumen'] ?? true),
            'logo_url' => null,
            'summary' => $summary,
            'items' => $items,
            'now' => now(),
        ])->render();
        $this->assertStringContainsString('INTERNAL SALES ALPHA', $staffHtml);
        $this->assertStringContainsString('INTERNAL KORSAL BETA', $staffHtml);

        // And the controller itself still serves the PDF to the owner.
        $this->actingAs($this->b['konsumen'])->get("/api/v1/orders/{$order->id}/invoice")->assertOk();
    }

    public function test_config_scoping_super_admin_global_and_agen_own_branch(): void
    {
        InvoiceConfigService::upsert(0, ['company_name' => 'PT Global', 'title' => 'INVOICE GLOBAL']);
        InvoiceConfigService::upsert((int) $this->b['agen']->id, ['company_name' => 'Toko Branch', 'title' => 'INVOICE BRANCH']);

        $global = $this->actingAs($this->b['agen'])->getJson('/api/v1/invoice/config')->assertOk()->json('data');
        $this->assertSame('Toko Branch', $global['company_name']);
    }

    public function test_agent_config_editor_shows_global_defaults_and_preserves_partial_branch_override(): void
    {
        InvoiceConfigService::upsert(0, ['company_name' => 'Global Company', 'footer' => 'Inherited Footer']);
        $this->actingAs($this->b['agen'])->getJson('/api/v1/invoice/config')->assertOk()->assertJsonPath('data.company_name', 'Global Company');
        $this->putJson('/api/v1/invoice/config', ['company_name' => 'Branch Company'])->assertOk()->assertJsonPath('data.footer', 'Inherited Footer');
        $this->getJson('/api/v1/invoice/config')->assertOk()->assertJsonPath('data.company_name', 'Branch Company')->assertJsonPath('data.footer', 'Inherited Footer');
    }

    public function test_only_super_admin_and_agen_can_configure_invoice(): void
    {
        $this->actingAs($this->b['agen'])->putJson('/api/v1/invoice/config', ['company_name' => 'Toko A', 'title' => 'INV'])->assertOk();
        $this->actingAs($this->b['admin'])->putJson('/api/v1/invoice/config', ['company_name' => 'Hack'])->assertForbidden();
        $this->actingAs($this->b['keuangan'])->getJson('/api/v1/invoice/config')->assertForbidden();

        // agen's change is scoped to its own branch.
        $other = User::factory()->agen()->create();
        $other->update(['agent_id' => $other->id]);
        $otherConfig = $this->actingAs($other)->getJson('/api/v1/invoice/config')->assertOk()->json('data');
        $this->assertNotSame('Toko A', $otherConfig['company_name']);
    }

    /* ------------- A1-22: accepted presentation settings actually render ------------- */

    public function test_recipient_toggle_show_konsumen_is_honored_in_the_rendered_pdf(): void
    {
        $order = $this->placeOrder();

        // show_konsumen=false → the recipient block must NOT render (previously
        // unconditional — the accepted config was inert).
        InvoiceConfigService::upsert((int) $this->b['agen']->id, [
            'show_konsumen' => false, 'show_sales' => true, 'show_korsal' => true,
        ]);

        $config = InvoiceConfigService::resolved((int) $order->agent_id);
        $summary = \App\Services\Payment\PaymentSummaryService::summarize($order);
        $items = $order->items()->with('shipment')->get();

        $hiddenHtml = view('pdf.invoice', [
            'order' => $order->fresh()->load('sales', 'korsal'),
            'config' => $config,
            'showInternalReferral' => true,
            'showRecipient' => (bool) ($config['show_konsumen'] ?? true),
            'logo_url' => null,
            'summary' => $summary,
            'items' => $items,
            'now' => now(),
        ])->render();
        $this->assertStringNotContainsString($order->recipient_name_snapshot, $hiddenHtml, 'recipient block must be hidden when show_konsumen=false');

        // Toggle back on → recipient renders again.
        InvoiceConfigService::upsert((int) $this->b['agen']->id, ['show_konsumen' => true]);
        $config = InvoiceConfigService::resolved((int) $order->agent_id);
        $shownHtml = view('pdf.invoice', [
            'order' => $order->fresh()->load('sales', 'korsal'),
            'config' => $config,
            'showInternalReferral' => true,
            'showRecipient' => (bool) ($config['show_konsumen'] ?? true),
            'logo_url' => null,
            'summary' => $summary,
            'items' => $items,
            'now' => now(),
        ])->render();
        $this->assertStringContainsString($order->recipient_name_snapshot, $shownHtml);
    }

    public function test_logo_is_embedded_only_for_same_branch_media_and_never_cross_agent(): void
    {
        $order = $this->placeOrder();
        $summary = \App\Services\Payment\PaymentSummaryService::summarize($order);
        $items = $order->items()->with('shipment')->get();
        $base = [
            'order' => $order->fresh()->load('sales', 'korsal'),
            'config' => InvoiceConfigService::resolved((int) $order->agent_id),
            'showInternalReferral' => true,
            'showRecipient' => true,
            'summary' => $summary,
            'items' => $items,
            'now' => now(),
        ];

        \Illuminate\Support\Facades\Storage::fake('public');
        $logo = \Illuminate\Http\UploadedFile::fake()->image('branch.png');
        \Illuminate\Support\Facades\Storage::disk('public')->put('logos/branch.png', file_get_contents($logo->getRealPath()));
        $agn = $this->b['agen'];
        // Own-branch uploader (agen): logo resolves to its public URL and renders.
        $media = \App\Models\Media::create([
            'disk' => 'public', 'path' => 'logos/branch.png', 'collection' => 'invoice_logo',
            'original_filename' => 'branch.png', 'mime_type' => 'image/png', 'extension' => 'png',
            'size' => 10, 'width' => 100, 'height' => 40, 'created_by' => $agn->id,
        ]);

        // Render through the REAL service: config carries logo_media_id, and the
        // service resolves it to a URL only when owned by this branch's agen.
        $pdfService = \App::make(\App\Services\Invoice\InvoicePdfService::class);
        $pdf = $pdfService->render($order, [...$base['config'], 'logo_media_id' => $media->id], viewerIsOwner: false);
        $this->assertNotNull($pdf);
        $dom = $pdf->getDom();
        $this->assertStringContainsString('data:image/png;base64,', $dom->saveHTML());
        $this->assertStringContainsString('/Subtype /Image', $pdf->output(), 'actual PDF must embed the approved logo image');

        // Cross-agent media (different agen uploader) → never resolved.
        $otherAgen = User::factory()->agen()->create();
        $otherAgen->update(['agent_id' => $otherAgen->id]);
        $foreign = \App\Models\Media::create([
            'disk' => 'public', 'path' => 'logos/foreign.png', 'collection' => 'invoice_logo',
            'original_filename' => 'foreign.png', 'mime_type' => 'image/png', 'extension' => 'png',
            'size' => 10, 'width' => 100, 'height' => 40, 'created_by' => $otherAgen->id,
        ]);
        $pdfForeign = $pdfService->render($order, [...$base['config'], 'logo_media_id' => $foreign->id], viewerIsOwner: false);
        $this->assertStringNotContainsString('logos/foreign.png', $pdfForeign->getDom()->saveHTML(), 'cross-agent media must never be embedded');

        // No logo configured → no <img> at all.
        $pdfNone = $pdfService->render($order, $base['config'], viewerIsOwner: false);
        $this->assertStringNotContainsString('<img', $pdfNone->getDom()->saveHTML());
    }
}