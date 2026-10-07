<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * Human UAT follow-up: the Dispatch delivery-date filter MUST operate on the canonical
 * Shipment delivery date — not merely on the parent Order.
 *
 * An order may hold a 10-Oct, a 15-Oct and a 17-Oct shipment; filtering 10 Oct must return
 * ONLY the 10-Oct shipment of that order, never its 15/17-Oct siblings. The frontend sends the
 * canonical YYYY-MM-DD value from its date input; the backend compares canonical dates only,
 * never localized labels.
 */
class DispatchDateFilterTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    /** @return array{agen:User, admin:User, koordinator:User, konsumen:User} */
    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko Filter', 'address' => 'Jl. Filter',
            'latitude' => -6.2, 'longitude' => 106.8166,
        ]);

        return [
            'agen' => $agen,
            'admin' => User::factory()->admin()->create(['agent_id' => $agen->id]),
            'koordinator' => User::factory()->koordinatorKurir()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]),
            'konsumen' => User::factory()->konsumen()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]),
        ];
    }

    private function product(User $agen, string $tag): Product
    {
        $p = Product::create([
            'sku' => 'DF-'.strtoupper($tag).'-'.Str::uuid(), 'name' => 'Produk '.$tag,
            'slug' => 'produk-df-'.strtolower($tag).'-'.uniqid(),
            'has_variations' => false, 'base_price' => 25000, 'weight_grams' => 400, 'status' => 'active',
        ]);
        ProductStock::create([
            'agent_id' => $agen->id, 'product_id' => $p->id, 'quantity_on_hand' => 200, 'quantity_reserved' => 0,
        ]);

        return $p;
    }

    /**
     * One COD order whose three lines are moved onto 10 / 15 / 17 Oct (courier-online so the
     * reschedule is legitimate). Returns the order with item ids keyed by label.
     *
     * @return array{order:Order, items:array<string, OrderItem>}
     */
    private function threeDateOrder(array $b): array
    {
        $products = [];
        foreach (['A', 'B', 'C'] as $tag) {
            $products[$tag] = $this->product($b['agen'], $tag);
        }

        $response = $this->actingAs($b['konsumen'])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => array_map(fn (Product $p) => ['product_id' => $p->id, 'quantity' => 1], array_values($products)),
                'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810,
            ]);
        $response->assertCreated();

        $order = Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
        // Rescheduling is Kurir-Online-only; this file seeds no provider infra.
        Shipment::where('order_id', $order->id)->update(['shipping_provider_code' => 'openroute']);

        $service = app(\App\Services\Order\OrderFulfillmentService::class);
        $items = [];
        foreach (['A' => '2026-10-10', 'B' => '2026-10-15', 'C' => '2026-10-17'] as $tag => $date) {
            $item = OrderItem::where('order_id', $order->id)->where('product_id', $products[$tag]->id)->firstOrFail();
            $service->rescheduleItemDeliveryDate($item, $date, $b['admin'], 'Spread dates');
            $items[$tag] = $item->fresh();
        }

        $this->assertSame(3, Shipment::where('order_id', $order->id)->count(), 'precondition: three canonical date units');

        return ['order' => $order->fresh(), 'items' => $items];
    }

    private function queue(User $actor, array $filters = [])
    {
        return $this->actingAs($actor)->getJson('/api/v1/dispatch'.($filters ? '?'.http_build_query($filters) : ''));
    }

    /** @return array<int, array> */
    private function cards(User $actor, array $filters = []): array
    {
        return $this->queue($actor, $filters)->assertOk()->json('data');
    }

    // ---------- 1 + 2. Three units exist; no filter returns all three ----------

    public function test_unfiltered_dispatch_returns_all_three_date_units(): void
    {
        $b = $this->branch();
        ['order' => $order] = $this->threeDateOrder($b);

        $cards = $this->cards($b['koordinator']);
        $mine = collect($cards)->where('order_id', $order->id);
        $this->assertCount(3, $mine);
        $this->assertSame(
            ['2026-10-10', '2026-10-15', '2026-10-17'],
            $mine->pluck('delivery_date')->map(fn ($d) => $d[0])->sort()->values()->all(),
        );
    }

    // ---------- 3 + 4 + 5 + 9. Each date returns ONLY its own shipment ----------

    public function test_each_date_filter_returns_only_its_own_shipment(): void
    {
        $b = $this->branch();
        ['order' => $order, 'items' => $items] = $this->threeDateOrder($b);

        foreach (['2026-10-10' => 'A', '2026-10-15' => 'B', '2026-10-17' => 'C'] as $date => $tag) {
            $cards = collect($this->cards($b['koordinator'], ['delivery_date' => $date]))
                ->where('order_id', $order->id);

            $this->assertCount(1, $cards, "filter {$date} must return exactly one shipment");
            $card = $cards->first();
            $this->assertSame($items[$tag]->shipment_id, $card['shipment_id']);
            $this->assertSame([$date], $card['delivery_date']);
            $this->assertSame(
                [$items[$tag]->id],
                collect($card['items'])->pluck('id')->all(),
                'no sibling item from another date leaks in',
            );
        }
    }

    // ---------- 6. A date with nothing returns zero ----------

    public function test_nonexistent_date_returns_zero_results(): void
    {
        $b = $this->branch();
        $this->threeDateOrder($b);

        $this->assertSame([], $this->cards($b['koordinator'], ['delivery_date' => '2026-11-30']));
    }

    // ---------- 7 + 8. Date composes with region and payment filters ----------

    public function test_date_filter_composes_with_region_and_payment_filters(): void
    {
        $b = $this->branch();
        ['order' => $order, 'items' => $items] = $this->threeDateOrder($b);

        $order->update([
            'province_id' => '32', 'province_snapshot' => 'Jawa Barat',
            'regency_id' => '3273', 'regency_snapshot' => 'Kota Bandung',
            'district_id' => '327301', 'district_snapshot' => 'Rancasari',
            'village_id' => '32730101', 'village_snapshot' => 'Cipamokolan',
        ]);

        $cards = collect($this->cards($b['koordinator'], [
            'delivery_date' => '2026-10-10', 'province_id' => '32', 'regency_id' => '3273',
            'district_id' => '327301', 'village_id' => '32730101',
        ]))->where('order_id', $order->id);
        $this->assertCount(1, $cards, 'all five constraints satisfied by the 10-Oct unit');
        $this->assertSame($items['A']->shipment_id, $cards->first()['shipment_id']);

        // A region that does not match excludes it, even with the right date.
        $this->assertSame(
            [],
            collect($this->cards($b['koordinator'], ['delivery_date' => '2026-10-10', 'province_id' => '33']))
                ->where('order_id', $order->id)->all(),
        );

        // Payment status composes too: these COD orders are unpaid, so the paid bucket hides them.
        $this->assertSame([], $this->cards($b['koordinator'], ['delivery_date' => '2026-10-10', 'paid' => 'paid']));
        $unpaid = collect($this->cards($b['koordinator'], ['delivery_date' => '2026-10-10', 'paid' => 'unpaid']))
            ->where('order_id', $order->id);
        $this->assertCount(1, $unpaid);
    }

    // ---------- 10. Authorization remains enforced ----------

    public function test_date_filtered_dispatch_stays_branch_scoped(): void
    {
        // Guests never reach the endpoint (before any actingAs in this test).
        $this->getJson('/api/v1/dispatch?delivery_date=2026-10-10')->assertUnauthorized();

        $b = $this->branch();
        $this->threeDateOrder($b);

        // A coordinator from another branch sees none of this branch's shipments, filtered or not.
        $other = $this->branch('B');
        $foreign = User::factory()->koordinatorKurir()->create(['agent_id' => $other['agen']->id, 'parent_id' => $other['agen']->id]);
        $this->assertSame([], $this->cards($foreign, ['delivery_date' => '2026-10-10']));
    }
}