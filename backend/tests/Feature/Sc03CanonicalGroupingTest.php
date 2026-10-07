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
 * LOCKED UAT-005 grouping rule applies to EVERY path that can put a NEW item on a shipment:
 * within ONE order, compatible delivery items for the SAME requested delivery date share ONE
 * canonical Shipment.
 *
 * The SC-03 (Admin add-line) path always created a brand-new shipment, and an added line's
 * `requested_delivery_date` DEFAULTS to the order's own estimate — i.e. exactly the date the
 * existing lines already share. The result was two canonical shipments for one physical delivery:
 * duplicate dispatch cards, duplicate "Ambil & Kirim", duplicate resi rows. The Human's original
 * UAT-005 symptom, reintroduced through the SC-03 door.
 */
class Sc03CanonicalGroupingTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    /** @var array{agen:User, admin:User, konsumen:User, products:array<string, Product>} */
    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        $this->b = $this->branch();
    }

    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko SC', 'address' => 'Jl. SC',
            'latitude' => -6.2, 'longitude' => 106.8166,
        ]);

        $products = [];
        foreach (['A', 'B', 'C'] as $tag) {
            $p = Product::create([
                'sku' => 'SCG-'.$tag.'-'.Str::uuid(), 'name' => 'Produk '.$tag,
                'slug' => 'produk-scg-'.strtolower($tag).'-'.uniqid(),
                'has_variations' => false, 'base_price' => 25000, 'weight_grams' => 400, 'status' => 'active',
            ]);
            ProductStock::create(['agent_id' => $agen->id, 'product_id' => $p->id, 'quantity_on_hand' => 200, 'quantity_reserved' => 0]);
            $products[$tag] = $p;
        }

        return [
            'agen' => $agen,
            'admin' => User::factory()->admin()->create(['agent_id' => $agen->id]),
            'konsumen' => User::factory()->konsumen()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]),
            'products' => $products,
        ];
    }

    private function placeOrder(array $lines, ?string $deliveryDate = '2026-10-20'): Order
    {
        $payload = [
            'payment_method_code' => 'cod',
            'items' => $lines,
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810,
        ];
        if ($deliveryDate !== null) {
            $payload['delivery_date'] = $deliveryDate;
        }

        $this->actingAs($this->b['konsumen'])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', $payload)->assertCreated();

        $order = Order::withoutGlobalScopes()->latest('id')->firstOrFail();

        // These tests exercise reschedule, which is only allowed for the canonical Kurir-Online
        // (openroute) provider. This fixture seeds no shipping-provider infrastructure, so the
        // shipment snapshot is put into that canonical state directly (as the other reschedule
        // suites do) instead of weakening the LOCKED provider rule.
        Shipment::where('order_id', $order->id)->update(['shipping_provider_code' => 'openroute']);

        return $order;
    }

    private function addLine(Order $order, Product $product, ?string $date, array $extra = []): OrderItem
    {
        $key = (string) Str::uuid();

        $payload = array_merge([
            'product_id' => $product->id,
            'quantity' => 1,
            'reason' => 'penambahan admin',
        ], $extra);

        if ($date !== null) {
            $payload['requested_delivery_date'] = $date;
        }

        $this->actingAs($this->b['admin'])
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson("/api/v1/orders/{$order->id}/items", $payload)->assertCreated();

        return OrderItem::query()->where('order_id', $order->id)->where('idempotency_key', $key)->firstOrFail();
    }

    /** shipment id => the distinct requested delivery dates riding it. */
    private function grouping(int $orderId): array
    {
        $out = [];
        foreach (Shipment::where('order_id', $orderId)->orderBy('id')->get() as $shipment) {
            $out[$shipment->id] = OrderItem::query()->where('shipment_id', $shipment->id)
                ->pluck('requested_delivery_date')->map(fn ($d) => $d?->toDateString())->unique()->values()->all();
        }

        return $out;
    }

    public function test_added_line_on_the_default_date_joins_the_existing_canonical_shipment(): void
    {
        $order = $this->placeOrder([['product_id' => $this->b['products']['A']->id, 'quantity' => 2]]);
        $existing = OrderItem::query()->where('order_id', $order->id)->firstOrFail();

        // No requested date on the request at all — the line inherits the order's own estimate, which
        // is exactly the date the existing line already carries.
        $added = $this->addLine($order, $this->b['products']['B'], null);

        $this->assertSame(
            '2026-10-20',
            $added->requested_delivery_date->toDateString(),
            'precondition: the added line inherits the order estimate',
        );
        $this->assertSame($existing->shipment_id, $added->shipment_id, 'one date => ONE canonical shipment');
        $this->assertSame(1, Shipment::where('order_id', $order->id)->count(), 'no duplicate shipment was created');

        $grouping = $this->grouping($order->id);
        $this->assertCount(1, $grouping);
        $this->assertSame(['2026-10-20'], array_values($grouping)[0]);
    }

    public function test_added_line_on_a_new_date_gets_its_own_canonical_shipment(): void
    {
        $order = $this->placeOrder([['product_id' => $this->b['products']['A']->id, 'quantity' => 2]]);

        $added = $this->addLine($order, $this->b['products']['B'], '2026-10-25');

        $existing = OrderItem::query()->where('order_id', $order->id)
            ->where('idempotency_key', null)->firstOrFail();

        $this->assertNotSame($existing->shipment_id, $added->shipment_id, 'a different date is a different delivery');
        $this->assertSame(2, Shipment::where('order_id', $order->id)->count());
        $this->assertSame(
            ['2026-10-20', '2026-10-25'],
            collect($this->grouping($order->id))->flatten()->unique()->sort()->values()->all(),
        );
    }

    public function test_repeated_additions_on_the_same_date_never_multiply_shipments(): void
    {
        $order = $this->placeOrder([['product_id' => $this->b['products']['A']->id, 'quantity' => 1]]);

        $b = $this->addLine($order, $this->b['products']['B'], '2026-10-25');
        $c = $this->addLine($order, $this->b['products']['C'], '2026-10-25');

        $this->assertSame($b->shipment_id, $c->shipment_id, 'two added lines on one date share one shipment');
        $this->assertSame(2, Shipment::where('order_id', $order->id)->count(), 'exactly one shipment per distinct date');

        $grouping = $this->grouping($order->id);
        $this->assertCount(2, $grouping);
        foreach ($grouping as $dates) {
            $this->assertCount(1, $dates, 'no shipment ever mixes two delivery dates');
        }
    }

    public function test_added_line_lands_on_a_pending_unassigned_canonical_shipment(): void
    {
        $order = $this->placeOrder([['product_id' => $this->b['products']['A']->id, 'quantity' => 1]]);

        $added = $this->addLine($order, $this->b['products']['B'], null);

        $shipment = Shipment::whereKey($added->shipment_id)->firstOrFail();
        // SC-03's own contract: the line it lands on must never carry someone else's executor, and
        // must be a mutable standard unit the warehouse/dispatch flow can still work on.
        $this->assertSame('pending', $shipment->status);
        $this->assertSame(Shipment::DELIVERY_MODE_STANDARD, $shipment->delivery_mode);
        $this->assertNull($shipment->courier_id);
        $this->assertNull($shipment->self_delivered_by_user_id);
    }

    public function test_partial_quantity_reschedule_split_joins_an_existing_unit_for_the_target_date(): void
    {
        // The same rule applies to the OTHER new-item path: a partial-quantity reschedule splits the
        // quantity into a child item on a fresh shipment. That child must join an existing canonical
        // unit for its target date instead of becoming a second one.
        $order = $this->placeOrder([['product_id' => $this->b['products']['A']->id, 'quantity' => 4]]);
        $item = OrderItem::query()->where('order_id', $order->id)->firstOrFail();

        // First move the whole line to 2026-10-25 so a canonical 25-Oct unit exists.
        $this->actingAs($this->b['admin'])
            ->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/reschedule", [
                'requested_delivery_date' => '2026-10-25', 'reason' => 'pindah tanggal',
            ])->assertOk();
        $this->assertSame(1, Shipment::where('order_id', $order->id)->count());

        // Now add a line on 25 Oct too, then split 2 units of the original onto 25 Oct as well.
        $this->addLine($order, $this->b['products']['B'], '2026-10-25');
        $parent = OrderItem::query()->where('order_id', $order->id)->whereNull('idempotency_key')->firstOrFail();

        $this->actingAs($this->b['admin'])
            ->patchJson("/api/v1/orders/{$order->id}/items/{$parent->id}/reschedule", [
                'requested_delivery_date' => '2026-10-25', 'reason' => 'partial', 'quantity' => 2,
            ])->assertOk();

        $this->assertSame(
            1,
            Shipment::where('order_id', $order->id)->count(),
            'a split onto an already-canonical date must not create a duplicate unit',
        );
        $grouping = $this->grouping($order->id);
        $this->assertCount(1, $grouping);
        $this->assertSame(['2026-10-25'], array_values($grouping)[0]);
    }
}