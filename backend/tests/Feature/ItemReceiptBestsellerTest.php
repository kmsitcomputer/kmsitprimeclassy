<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\DeliveryVerification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemReceipt;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use App\Models\ShippingConfiguration;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/** REM-07 / D-06 A: per-item receipt events + verified-paid Agent-scoped bestseller. */
class ItemReceiptBestsellerTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;
    use \Tests\Support\AcceptsWarehouseDemand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true]);
    }

    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id, 'referral_code' => 'AG'.strtoupper(Str::random(6))]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Toko', 'address' => 'Jl. X', 'latitude' => -6.2, 'longitude' => 106.8]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);

        return compact('agen', 'admin', 'konsumen');
    }

    private function product(array $branch, string $name): Product
    {
        $p = Product::create(['sku' => 'BS-'.Str::uuid(), 'name' => $name, 'slug' => 'bs-'.uniqid(), 'has_variations' => false, 'base_price' => 50000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $branch['agen']->id, 'product_id' => $p->id, 'quantity_on_hand' => 100, 'quantity_reserved' => 0]);

        return $p;
    }

    /** A real order walked through the canonical office path to terkirim (payment untouched: COD, unpaid). */
    private function deliveredOrder(array $branch, Product $product, int $qty): OrderItem
    {
        $id = $this->actingAs($branch['agen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'konsumen_id' => $branch['konsumen']->id, 'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => $qty]],
            'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ])->assertCreated()->json('data.id');
        $this->makeDispatchable($id);
        $this->actingAs($branch['admin'])->patchJson("/api/v1/orders/{$id}/status", ['status' => 'dikirim'])->assertOk();
        $this->actingAs($branch['admin'])->patchJson("/api/v1/orders/{$id}/status", ['status' => 'terkirim'])->assertOk();

        return OrderItem::where('order_id', $id)->firstOrFail();
    }

    /** Fixture shortcut for the PAID state; real payment verification is covered by the payment suites. */
    private function markPaid(OrderItem $item): void
    {
        $total = Order::withoutGlobalScopes()->whereKey($item->order_id)->value('total_amount');
        Order::withoutGlobalScopes()->whereKey($item->order_id)->update(['payment_status' => 'paid', 'paid_amount' => $total, 'remaining_amount' => 0]);
    }

    private function receive(User $as, OrderItem $item, int $qty, ?string $on = null, ?string $key = null, array $extra = [])
    {
        return $this->actingAs($as)->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()])
            ->postJson("/api/v1/order-items/{$item->id}/receipts", ['quantity' => $qty, 'received_on' => $on ?? now()->toDateString()] + $extra);
    }

    private function feed(?User $as, array $q = [])
    {
        if (! $as) {
            \Illuminate\Support\Facades\Auth::forgetGuards(); // actingAs() is sticky; a guest request must really be anonymous
        }
        $r = $as ? $this->actingAs($as) : $this;

        return $r->getJson('/api/v1/bestsellers?'.http_build_query($q));
    }

    public function test_receipt_events_are_partial_bounded_idempotent_scoped_and_do_not_touch_shipment_verification(): void
    {
        $b = $this->branch();
        $other = $this->branch();
        $item = $this->deliveredOrder($b, $this->product($b, 'A'), 5);
        $verificationsBefore = DeliveryVerification::count();
        $deliveredAt = $item->shipment->fresh()->delivered_at;

        $first = $this->receive($b['admin'], $item, 2, null, 'receipt-key-0001')->assertCreated()->json('data.id');
        $this->receive($b['admin'], $item, 2)->assertCreated();                       // second event, same item
        $this->receive($b['admin'], $item, 2)->assertStatus(422);                     // 2+2+2 > 5
        $this->receive($b['admin'], $item, 1)->assertCreated();                       // exactly fills 5
        $this->receive($b['admin'], $item, 1)->assertStatus(422);

        // replay: same key + same body ⇒ same row; changed body ⇒ 409; nothing duplicated
        $this->assertSame($first, $this->receive($b['admin'], $item, 2, null, 'receipt-key-0001')->json('data.id'));
        $this->receive($b['admin'], $item, 3, null, 'receipt-key-0001')->assertStatus(409);
        $this->assertSame(3, OrderItemReceipt::where('order_item_id', $item->id)->count());

        // validation / authority
        $this->receive($b['admin'], $item, 0)->assertStatus(422);
        $this->receive($b['admin'], $item, 1, now()->addDay()->toDateString())->assertStatus(422);
        $this->receive($other['admin'], $item, 1)->assertStatus(403);
        $this->receive($b['konsumen'], $item, 1)->assertStatus(403);
        $this->actingAs($other['admin'])->getJson("/api/v1/order-items/{$item->id}/receipts")->assertStatus(403);

        // legacy delivery-verification history and shipment aggregate are untouched
        $this->assertSame($verificationsBefore, DeliveryVerification::count());
        $this->assertEquals($deliveredAt, $item->shipment->fresh()->delivered_at);
    }

    public function test_an_item_that_has_not_arrived_cannot_be_received(): void
    {
        $b = $this->branch();
        $p = $this->product($b, 'N');
        $id = $this->actingAs($b['agen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'konsumen_id' => $b['konsumen']->id, 'payment_method_code' => 'cod', 'items' => [['product_id' => $p->id, 'quantity' => 1]],
            'recipient_name' => 'B', 'recipient_phone' => '0811', 'address_line' => 'J', 'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ])->assertCreated()->json('data.id');
        $this->receive($b['admin'], OrderItem::where('order_id', $id)->firstOrFail(), 1)->assertStatus(422);
    }

    public function test_bestseller_counts_only_fully_paid_actual_receipts_by_agent_period_and_limit(): void
    {
        $b = $this->branch();
        $other = $this->branch();
        $pA = $this->product($b, 'A');   // 5 recent units, paid
        $pB = $this->product($b, 'B');   // 3 units, paid, old (outside 7d)
        $pC = $this->product($b, 'C');   // delivered + received but UNPAID (COD)
        $pD = $this->product($b, 'D');   // never received (legacy-like)
        $pX = $this->product($other, 'X'); // other Agent

        $iA = $this->deliveredOrder($b, $pA, 5);
        $iB = $this->deliveredOrder($b, $pB, 3);
        $iC = $this->deliveredOrder($b, $pC, 9);
        $this->deliveredOrder($b, $pD, 7);
        $iX = $this->deliveredOrder($other, $pX, 8);
        foreach ([$iA, $iB, $iX] as $i) {
            $this->markPaid($i);
        }
        $this->receive($b['admin'], $iA, 5)->assertCreated();
        $this->receive($b['admin'], $iB, 3)->assertCreated();
        $this->receive($b['admin'], $iC, 9)->assertCreated();
        $this->receive($other['admin'], $iX, 8)->assertCreated();
        OrderItemReceipt::where('order_item_id', $iB->id)->update(['received_on' => now()->subDays(20)->toDateString()]);

        $names = fn ($r) => array_column($r->json('data'), 'name');

        $this->assertSame(['A'], $names($this->feed($b['konsumen'], ['period' => '7', 'limit' => 4])));
        $this->assertSame(['A', 'B'], $names($this->feed($b['konsumen'], ['period' => '30', 'limit' => 4])));
        $this->assertSame(['A', 'B'], $names($this->feed($b['konsumen'], ['period' => 'all', 'limit' => 12])));
        // unpaid COD (C), never-received (D) and the other Agent's X never appear
        $all = $names($this->feed($b['konsumen'], ['period' => 'all', 'limit' => 12]));
        $this->assertNotContains('C', $all);
        $this->assertNotContains('D', $all);
        $this->assertNotContains('X', $all);
        // limits/periods are an allow-list
        $this->feed($b['konsumen'], ['limit' => 5])->assertStatus(422);
        $this->feed($b['konsumen'], ['period' => '14'])->assertStatus(422);
        // PII / quantities are not in the public payload
        $card = $this->feed($b['konsumen'], ['period' => 'all'])->json('data.0');
        $this->assertArrayNotHasKey('units', $card);
        $this->assertArrayNotHasKey('konsumen_id', $card);

        // late payment: C paid AFTER delivery enters under its ORIGINAL receipt date
        $this->markPaid($iC);
        $this->assertContains('C', $names($this->feed($b['konsumen'], ['period' => '7', 'limit' => 12])));
    }

    public function test_agent_resolution_is_server_side_super_admin_explicit_guest_by_referral_else_hidden(): void
    {
        $b = $this->branch();
        $other = $this->branch();
        $i = $this->deliveredOrder($b, $this->product($b, 'A'), 2);
        $this->markPaid($i);
        $this->receive($b['admin'], $i, 2)->assertCreated();
        $sa = User::factory()->superAdmin()->create();

        $this->assertSame(['A'], array_column($this->feed($sa, ['agent_id' => $b['agen']->id])->json('data'), 'name'));
        $this->assertSame([], $this->feed($sa)->json('data'));                          // no implicit global ranking
        $this->assertSame([], $this->feed($other['konsumen'])->json('data'));            // own Agent only
        $this->assertTrue($this->feed($other['konsumen'])->json('meta.hidden'));
        $this->assertSame(['A'], array_column($this->feed(null, ['ref' => $b['agen']->referral_code])->json('data'), 'name'));
        $this->assertSame([], $this->feed(null, ['ref' => 'NOPE-CODE'])->json('data'));
        $this->assertSame([], $this->feed(null)->json('data'));
        $b['agen']->delete();                                                           // deleted Agent ⇒ hidden
        $this->assertSame([], $this->feed($sa, ['agent_id' => $b['agen']->id])->json('data'));
    }

    public function test_accepted_physical_returns_are_deducted_once_requested_or_rejected_are_not(): void
    {
        $b = $this->branch();
        $pA = $this->product($b, 'A');
        $pB = $this->product($b, 'B');
        $pC = $this->product($b, 'C');
        $iA = $this->deliveredOrder($b, $pA, 4);
        $iB = $this->deliveredOrder($b, $pB, 4);
        $iC = $this->deliveredOrder($b, $pC, 6);
        foreach ([$iA, $iB, $iC] as $i) {
            $this->markPaid($i);
            $this->receive($b['admin'], $i, $i->fulfilled_quantity)->assertCreated();
        }
        $mk = function (OrderItem $i, array $attrs) use ($b) {
            $rr = ReturnRequest::create(['order_id' => $i->order_id, 'requested_by' => $b['konsumen']->id, 'reason' => 'x', 'status' => 'approved']);

            return ReturnItem::create(['return_id' => $rr->id, 'order_item_id' => $i->id, 'quantity_returned' => 1, 'refund_amount' => 0] + $attrs);
        };
        $mk($iA, ['status' => 'approved', 'quantity_received' => 3, 'good_quantity' => 1, 'damaged_quantity' => 2, 'inspected_at' => now()]); // 4-3 = 1 (damaged counts)
        $mk($iB, ['status' => 'pending', 'quantity_received' => 0]);                                                                         // requested only
        $mk($iC, ['status' => 'rejected', 'quantity_received' => 0]);                                                                        // rejected

        $r = $this->feed($b['konsumen'], ['period' => 'all', 'limit' => 12])->json('data');
        $order = array_column($r, 'name');
        $this->assertSame(['C', 'B', 'A'], $order); // C=6, B=4, A=1 net

        // a fully returned item drops out entirely (floor 0)
        $mk($iA, ['status' => 'approved', 'quantity_received' => 1, 'good_quantity' => 1, 'inspected_at' => now()]);
        $this->assertNotContains('A', array_column($this->feed($b['konsumen'], ['period' => 'all', 'limit' => 12])->json('data'), 'name'));
    }

    public function test_item_with_several_receipt_events_and_accepted_returns_is_excluded_not_guessed(): void
    {
        $b = $this->branch();
        $p = $this->product($b, 'M');
        $i = $this->deliveredOrder($b, $p, 4);
        $this->markPaid($i);
        $this->receive($b['admin'], $i, 2)->assertCreated();
        $this->receive($b['admin'], $i, 2)->assertCreated();
        $this->assertSame(['M'], array_column($this->feed($b['konsumen'], ['period' => 'all'])->json('data'), 'name'));
        $rr = ReturnRequest::create(['order_id' => $i->order_id, 'requested_by' => $b['konsumen']->id, 'reason' => 'x', 'status' => 'approved']);
        ReturnItem::create(['return_id' => $rr->id, 'order_item_id' => $i->id, 'quantity_returned' => 1, 'refund_amount' => 0, 'status' => 'approved', 'quantity_received' => 1, 'inspected_at' => now()]);
        $this->assertSame([], $this->feed($b['konsumen'], ['period' => 'all'])->json('data'));
    }

    public function test_recorded_allocation_resolves_multi_receipt_returns_without_fifo_and_is_guarded(): void
    {
        $b = $this->branch();
        $other = $this->branch();
        $i = $this->deliveredOrder($b, $this->product($b, 'M'), 6);
        $this->markPaid($i);
        $r1 = $this->receive($b['admin'], $i, 3)->assertCreated()->json('data.id');
        $r2 = $this->receive($b['admin'], $i, 3)->assertCreated()->json('data.id');
        $rr = ReturnRequest::create(['order_id' => $i->order_id, 'requested_by' => $b['konsumen']->id, 'reason' => 'x', 'status' => 'approved']);
        $ri = ReturnItem::create(['return_id' => $rr->id, 'order_item_id' => $i->id, 'quantity_returned' => 2, 'refund_amount' => 0, 'status' => 'approved', 'quantity_received' => 2, 'inspected_at' => now()]);
        $pending = ReturnItem::create(['return_id' => $rr->id, 'order_item_id' => $i->id, 'quantity_returned' => 1, 'refund_amount' => 0, 'status' => 'pending']);
        $feed = fn () => array_column($this->feed($b['konsumen'], ['period' => 'all'])->json('data'), 'name');
        $post = fn (User $u, $item, array $a) => $this->actingAs($u)->postJson("/api/v1/return-items/{$item->id}/receipt-allocations", ['allocations' => $a]);

        $this->assertSame([], $feed()); // unresolved ⇒ excluded, not guessed

        $post($b['admin'], $ri, [['receipt_id' => $r1, 'quantity' => 1]])->assertStatus(422);                       // must equal received qty (2)
        $post($b['admin'], $ri, [['receipt_id' => $r1, 'quantity' => 4]])->assertStatus(422);                       // sum mismatch/exceeds
        $post($b['admin'], $pending, [['receipt_id' => $r1, 'quantity' => 1]])->assertStatus(422);                  // requested ≠ accepted
        $post($other['admin'], $ri, [['receipt_id' => $r1, 'quantity' => 2]])->assertStatus(403);
        $post($b['konsumen'], $ri, [['receipt_id' => $r1, 'quantity' => 2]])->assertStatus(403);
        $this->assertSame([], $feed());

        $post($b['admin'], $ri, [['receipt_id' => $r1, 'quantity' => 2]])->assertCreated();
        $post($b['admin'], $ri, [['receipt_id' => $r1, 'quantity' => 2]])->assertCreated();                          // identical replay
        $post($b['admin'], $ri, [['receipt_id' => $r2, 'quantity' => 2]])->assertStatus(409);                        // different ⇒ conflict
        $this->assertSame(1, \App\Models\ReturnItemReceiptAllocation::count());

        $this->assertSame(['M'], $feed());
        // units: 6 received − 2 returned = 4, counted once; receipt cohorts unchanged
        $this->assertSame(3, OrderItemReceipt::find($r1)->quantity);
        $this->assertSame(3, OrderItemReceipt::find($r2)->quantity);
    }
    public function test_cms_bestseller_resolves_same_ranking_and_hides_without_agent_or_receipts(): void
    {
        $b = $this->branch();
        $other = $this->branch();
        $block = \App\Models\CmsHomepageBlock::create(['type' => 'bestseller', 'content' => ['heading' => 'Terlaris', 'period' => '7', 'limit' => 4], 'is_active' => true, 'sort_order' => 0]);
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->getJson('/api/v1/homepage')->assertOk()->assertJsonPath('data.0.content.products', []);
        $p = $this->product($b, 'Popular');
        $item = $this->deliveredOrder($b, $p, 3);
        $this->receive($b['admin'], $item, 3)->assertCreated();
        $this->markPaid($item);
        $ids = $this->feed($b['konsumen'], ['period' => '7', 'limit' => 4])->json('data.*.id');
        $home = $this->getJson('/api/v1/homepage?agent_id='.$other['agen']->id)->assertOk();
        $this->assertSame($ids, $home->json('data.0.content.products.*.id'), 'forged branch ignored for signed-in viewer');
        $this->assertSame('Popular', $home->json('data.0.content.products.0.name'));
        $this->assertArrayNotHasKey('paid_amount', $home->json('data.0.content.products.0'));
        $this->actingAs($other['konsumen'])->getJson('/api/v1/homepage')->assertOk()->assertJsonPath('data.0.content.products', []);
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->getJson('/api/v1/homepage?ref='.$b['agen']->referral_code)->assertOk()->assertJsonPath('data.0.content.products.0.id', $p->id);
    }

    public function test_cms_bestseller_settings_enforce_locked_periods_and_limits(): void
    {
        $sa = User::factory()->superAdmin()->create();
        $this->actingAs($sa)->postJson('/api/v1/cms/homepage-blocks', ['type' => 'bestseller', 'content' => ['heading' => 'Top', 'period' => '30', 'limit' => 8]])->assertCreated();
        $this->postJson('/api/v1/cms/homepage-blocks', ['type' => 'bestseller', 'content' => ['period' => '365', 'limit' => 6]])->assertUnprocessable()->assertJsonValidationErrors(['content.period', 'content.limit']);
    }
}
