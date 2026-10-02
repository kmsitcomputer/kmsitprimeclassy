<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\AgentProfile;
use App\Models\Courier;
use App\Models\DeliveryVerification;
use App\Models\Media;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shipment;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Models\User;
use App\Models\WarehouseSetting;
use App\Models\WarehouseStock;
use App\Services\Order\ShipmentGroupingService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * Package C — PRODUCTION UAT round-3 remediation (independent Codex F02 + F04, non-concurrency parts).
 *
 *  F02 — conflicting historical shipping-fee snapshots: classify carriers, dedupe only equivalent ones,
 *        FAIL CLOSED (422, atomic) when two nonzero carriers disagree. Never guess by id, never sum, never
 *        silently discard; command/API rollback.
 *  F04 — historical shipment CONTENTS are immutable: delivered / proof / verification / in-transit reject
 *        both full reschedule and partial split, driven by SHIPMENT-level evidence (inconsistent item status
 *        must not bypass it).
 */
class PackageCProductionUatRound3Test extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    /** @return array{agen:User, admin:User, gudang:User, konsumen:User} */
    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'UAT3', 'address' => 'x', 'latitude' => -6.2, 'longitude' => 106.8166]);

        return [
            'agen' => $agen,
            'admin' => User::factory()->admin()->create(['agent_id' => $agen->id]),
            'gudang' => User::factory()->gudang()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]),
            'konsumen' => User::factory()->konsumen()->create(['agent_id' => $agen->id]),
        ];
    }

    private function product(User $agen, string $name, int $price = 10000, int $stock = 50): Product
    {
        $product = Product::create(['sku' => 'UAT3-'.Str::uuid(), 'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(), 'has_variations' => false, 'base_price' => $price, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => $stock, 'quantity_reserved' => 0]);

        return $product;
    }

    private function transit(User $agen, Product $product, int $qty = 50): void
    {
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'transit', 'quantity' => $qty]);
        WarehouseSetting::firstOrCreate(['agent_id' => $agen->id], ['factory_plan_enabled' => false]);
    }

    /** @param array<int, array{0:Product,1:int}> $lines */
    private function order(User $konsumen, array $lines, ?string $date = null): Order
    {
        $response = $this->actingAs($konsumen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', array_filter([
            'payment_method_code' => 'cod',
            'items' => array_map(fn ($l) => ['product_id' => $l[0]->id, 'quantity' => $l[1]], $lines),
            'delivery_date' => $date,
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ]));
        $response->assertCreated();

        return Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
    }

    private function item(Order $order, Product $product): OrderItem
    {
        return OrderItem::where('order_id', $order->id)->where('product_id', $product->id)->firstOrFail();
    }

    private function firstShipment(Order $order): Shipment
    {
        return Shipment::where('order_id', $order->id)->orderBy('id')->firstOrFail();
    }

    /** A fresh mutable shell replicating the order's first shipment's provider/destination metadata. */
    private function shell(Order $order, float $fee, ?float $rate = 5000): Shipment
    {
        $base = $this->firstShipment($order);
        $shell = $base->replicate(['shipping_fee_snapshot', 'rate_per_km']);
        $shell->save();
        $shell->fresh()->update(['shipping_fee_snapshot' => $fee, 'rate_per_km' => $rate]);

        return $shell->fresh();
    }

    private function setFee(Shipment $shipment, float $fee, ?float $rate = 5000): Shipment
    {
        $shipment->update(['shipping_fee_snapshot' => $fee, 'rate_per_km' => $rate]);

        return $shipment->fresh();
    }

    private function feeTotal(Order $order): float
    {
        return (float) Shipment::where('order_id', $order->id)->sum('shipping_fee_snapshot');
    }

    private function courier(User $agen): Courier
    {
        return Courier::create(['type' => 'internal', 'user_id' => User::factory()->kurir()->create(['agent_id' => $agen->id])->id, 'agent_id' => $agen->id, 'name' => 'K', 'is_active' => true]);
    }

    /**
     * Full pre/post state used to prove atomic preservation on refused operations.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Order $order): array
    {
        $items = OrderItem::where('order_id', $order->id)->orderBy('id')->get();

        return [
            'shipments' => Shipment::where('order_id', $order->id)->orderBy('id')->get()->map(fn (Shipment $s) => [
                'id' => $s->id, 'status' => $s->status, 'courier_id' => $s->courier_id, 'tracking' => $s->tracking_number,
                'fee' => (float) $s->shipping_fee_snapshot, 'rate' => $s->rate_per_km, 'proof' => $s->proof_media_id,
                'shipped_at' => (string) $s->shipped_at, 'delivered_at' => (string) $s->delivered_at,
            ])->all(),
            'items' => $items->map(fn (OrderItem $i) => [
                'id' => $i->id, 'shipment_id' => $i->shipment_id, 'date' => $i->requested_delivery_date?->toDateString(),
                'fulfilled' => $i->fulfilled_quantity, 'original' => $i->original_quantity, 'status' => $i->status,
            ])->all(),
            'request_items' => StockRequestItem::whereIn('stock_request_id', StockRequest::withoutGlobalScopes()->where('order_id', $order->id)->pluck('id'))
                ->orderBy('id')->get()->map(fn (StockRequestItem $r) => [$r->order_item_id, $r->requested_qty, $r->fulfilled_qty, $r->remaining_qty])->all(),
            'order' => Order::withoutGlobalScopes()->find($order->id)->only(['total_amount', 'shipping_fee_amount', 'paid_amount', 'remaining_amount', 'payment_status']),
            'reserved' => ProductStock::withoutGlobalScopes()->where('agent_id', $order->agent_id)->orderBy('product_id')->pluck('quantity_reserved')->all(),
        ];
    }

    private function makeHistorical(Shipment $shipment, string $kind, int $adminId): void
    {
        switch ($kind) {
            case 'delivered':
                $shipment->update(['status' => 'delivered', 'delivered_at' => now()]);
                break;
            case 'in_transit':
                $shipment->update(['status' => 'in_transit', 'shipped_at' => now()]);
                break;
            case 'proof':
                $media = Media::create([
                    'disk' => 'public', 'path' => 'shipment_proof/'.Str::uuid().'.jpg', 'collection' => 'shipment_proof',
                    'original_filename' => 'proof.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size' => 10, 'created_by' => $adminId,
                ]);
                $shipment->update(['proof_media_id' => $media->id]);
                break;
            case 'verification':
                DeliveryVerification::create(['shipment_id' => $shipment->id, 'outcome' => 'received', 'verified_by' => $adminId, 'verified_at' => now(), 'idempotency_key' => (string) Str::uuid()]);
                break;
        }
    }

    // ============================== F02 ==============================

    public function test_conflicting_nonzero_carriers_fail_closed_and_change_nothing(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b] = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $order = $this->order($konsumen, [[$a, 1], [$b, 1]], now()->addDays(3)->toDateString());
        $s1 = $this->setFee($this->firstShipment($order), 10000, 5000);
        $s2 = $this->shell($order, 25000, 5000);
        $this->item($order, $b)->update(['shipment_id' => $s2->id]);
        Order::withoutGlobalScopes()->whereKey($order->id)->update(['shipping_fee_amount' => 25000]);
        $before = $this->snapshot($order);

        try {
            app(ShipmentGroupingService::class)->reconcileOrder($order, $admin);
            $this->fail('conflicting historical carriers must fail closed');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status());
        }

        $this->assertSame($before, $this->snapshot($order), 'conflict must change nothing');
        $this->assertSame(2, Shipment::where('order_id', $order->id)->count());
        $this->assertSame(35000.0, $this->feeTotal($order), 'both historical carriers preserved');
    }

    public function test_conflicting_carriers_also_fail_via_regroup_command_with_nonzero_exit(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b] = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $order = $this->order($konsumen, [[$a, 1], [$b, 1]], now()->addDays(3)->toDateString());
        $this->setFee($this->firstShipment($order), 25000, 5000);
        $s2 = $this->shell($order, 10000, 5000);
        $this->item($order, $b)->update(['shipment_id' => $s2->id]);
        $before = $this->snapshot($order);

        $this->artisan('shipments:regroup', ['--apply' => true])->assertExitCode(1);

        $this->assertSame($before, $this->snapshot($order));
        $this->assertSame(2, Shipment::where('order_id', $order->id)->count());
    }

    public function test_equivalent_nonzero_carriers_dedupe_to_one_and_stay_idempotent(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b] = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $order = $this->order($konsumen, [[$a, 1], [$b, 1]], now()->addDays(3)->toDateString());
        $s1 = $this->setFee($this->firstShipment($order), 25000, 5000);
        // Round-5: a paid RajaOngkir quote carries its material identity (courier + service); the shell
        // replicates it, so the two carriers are genuinely equivalent and may dedupe.
        $s1->update(['shipping_provider_code' => 'rajaongkir', 'provider_meta' => ['courier' => 'jne', 'service' => 'REG']]);
        $s2 = $this->shell($order, 25000, 5000); // identical fee + metadata (replicated provider/distance)
        $this->item($order, $b)->update(['shipment_id' => $s2->id]);

        $result = app(ShipmentGroupingService::class)->reconcileOrder($order, null);
        $this->assertSame(1, $result['merged']);
        $this->assertSame(1, Shipment::where('order_id', $order->id)->count());
        $this->assertSame(25000.0, $this->feeTotal($order), 'deduplicated, not summed');
        $this->assertSame($s1->id, (int) Shipment::where('order_id', $order->id)->value('id'), 'lowest-id carrier survives');

        // Repeated apply is idempotent and still conserves the single carrier.
        $this->artisan('shipments:regroup', ['--apply' => true])->assertExitCode(0);
        $this->assertSame(1, Shipment::where('order_id', $order->id)->count());
        $this->assertSame(25000.0, $this->feeTotal($order));
    }

    public function test_equal_value_but_conflicting_metadata_fails_closed(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b] = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $order = $this->order($konsumen, [[$a, 1], [$b, 1]], now()->addDays(3)->toDateString());
        $this->setFee($this->firstShipment($order), 25000, 5000);
        $s2 = $this->shell($order, 25000, 9000); // same fee, different rate metadata
        $this->item($order, $b)->update(['shipment_id' => $s2->id]);
        $before = $this->snapshot($order);

        try {
            app(ShipmentGroupingService::class)->reconcileOrder($order, null);
            $this->fail('conflicting fee metadata must fail closed');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status());
        }

        $this->assertSame($before, $this->snapshot($order));
    }

    public function test_zero_and_one_nonzero_carrier_are_safe(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b] = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $order = $this->order($konsumen, [[$a, 1], [$b, 1]], now()->addDays(3)->toDateString());
        $this->setFee($this->firstShipment($order), 0, null);
        $s2 = $this->shell($order, 25000, 5000);
        $this->item($order, $b)->update(['shipment_id' => $s2->id]);

        app(ShipmentGroupingService::class)->reconcileOrder($order, null);

        $this->assertSame(1, Shipment::where('order_id', $order->id)->count());
        $this->assertSame(25000.0, $this->feeTotal($order));
        $this->assertSame($s2->id, (int) Shipment::where('order_id', $order->id)->value('id'), 'the single carrier survives');
    }

    public function test_release_if_empty_conflict_on_reschedule_fails_closed_atomically(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b] = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $d1 = now()->addDays(3)->toDateString();
        $d2 = now()->addDays(9)->toDateString();
        $order = $this->order($konsumen, [[$a, 1], [$b, 1]], $d1);

        // Item B already sits on d2 in its own shipment carrying a conflicting fee.
        $s1 = $this->firstShipment($order);            // holds item A on d1
        $this->setFee($s1, 10000, 5000);
        $s2 = $this->shell($order, 25000, 5000);
        $this->item($order, $b)->update(['requested_delivery_date' => $d2, 'shipment_id' => $s2->id]);
        $before = $this->snapshot($order);

        // Rescheduling A to d2 empties S1 -> releaseIfEmpty(S1, S2) would consolidate two conflicting carriers.
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$this->item($order, $a)->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'x'])->assertStatus(422);

        $this->assertSame($before, $this->snapshot($order));
        $this->assertSame(2, Shipment::where('order_id', $order->id)->count());
        $this->assertSame(35000.0, $this->feeTotal($order));
    }

    public function test_partial_split_that_would_merge_conflicting_carriers_fails_closed(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        [$p, $q, $r] = [$this->product($agen, 'P'), $this->product($agen, 'Q'), $this->product($agen, 'R')];
        $d1 = now()->addDays(3)->toDateString();
        $d2 = now()->addDays(9)->toDateString();
        $order = $this->order($konsumen, [[$p, 3], [$q, 1], [$r, 1]], $d1);

        // Q and R already sit on the SAME d2 date in two mutable shipments with conflicting fees.
        $sp = $this->firstShipment($order);
        $sq = $this->shell($order, 10000, 5000);
        $sr = $this->shell($order, 25000, 5000);
        $this->item($order, $q)->update(['requested_delivery_date' => $d2, 'shipment_id' => $sq->id]);
        $this->item($order, $r)->update(['requested_delivery_date' => $d2, 'shipment_id' => $sr->id]);
        $before = $this->snapshot($order);

        // Splitting 1 of P onto d2 triggers the lazy regroup of the d2 group -> conflicting carriers -> refuse.
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$this->item($order, $p)->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'x', 'quantity' => 1])->assertStatus(422);

        $this->assertSame($before, $this->snapshot($order));
        $this->assertSame(3, OrderItem::where('order_id', $order->id)->count());
    }

    public function test_partial_split_onto_a_zero_shell_is_safe_and_conserves_the_fee(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $p = $this->product($agen, 'P');
        $d1 = now()->addDays(3)->toDateString();
        $d2 = now()->addDays(9)->toDateString();
        $order = $this->order($konsumen, [[$p, 3]], $d1);
        // Round-5: a reschedulable paid order is Kurir Online (openroute) with a consistent Order-level fee.
        $this->setFee($this->firstShipment($order), 25000, 5000)->update(['shipping_provider_code' => 'openroute']);
        Order::withoutGlobalScopes()->whereKey($order->id)->update(['shipping_fee_amount' => 25000]);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$this->item($order, $p)->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'pecah', 'quantity' => 1])->assertOk();

        // Round-5 equal allocation: no false conflict, the Order fee is conserved exactly across the groups.
        $this->assertSame(25000.0, $this->feeTotal($order), 'no fee lost or double counted');
        $this->assertSame(2, Shipment::where('order_id', $order->id)->count());
    }

    // ============================== F04 ==============================

    /**
     * Every historical lifecycle must reject BOTH a full reschedule and a partial split, on both single-item
     * and shared shipments, driven by shipment-level evidence even when the item status is stale.
     *
     * @return array<string, array{0:string}>
     */
    public static function historicalKinds(): array
    {
        return ['delivered' => ['delivered'], 'in_transit' => ['in_transit'], 'proof' => ['proof'], 'verification' => ['verification']];
    }

    #[DataProvider('historicalKinds')]
    public function test_historical_shared_shipment_rejects_reschedule_and_split_atomically(string $kind): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b] = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $d2 = now()->addDays(9)->toDateString();

        // Shared shipment + inconsistent item status (both items stay 'diproses').
        $order = $this->order($konsumen, [[$a, 3], [$b, 1]], now()->addDays(3)->toDateString());
        $this->makeHistorical($this->firstShipment($order), $kind, $admin->id);
        $before = $this->snapshot($order);

        // Full reschedule of one member -> membership must not change.
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$this->item($order, $a)->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'x'])->assertStatus(422);
        $this->assertSame($before, $this->snapshot($order));

        // Partial split -> represented quantity must not change.
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$this->item($order, $a)->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'x', 'quantity' => 1])->assertStatus(422);
        $this->assertSame($before, $this->snapshot($order));
        $this->assertSame(2, OrderItem::where('order_id', $order->id)->count());
    }

    #[DataProvider('historicalKinds')]
    public function test_historical_single_item_shipment_rejects_reschedule_and_split_atomically(string $kind): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        $d2 = now()->addDays(9)->toDateString();

        $order = $this->order($konsumen, [[$a, 3]], now()->addDays(3)->toDateString());
        $this->makeHistorical($this->firstShipment($order), $kind, $admin->id);
        $before = $this->snapshot($order);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$this->item($order, $a)->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'x'])->assertStatus(422);
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$this->item($order, $a)->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'x', 'quantity' => 1])->assertStatus(422);

        $this->assertSame($before, $this->snapshot($order));
        $this->assertSame(1, OrderItem::where('order_id', $order->id)->count());
    }

    public function test_assigned_and_tracked_boundary_is_preserved(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        [$a, $b] = [$this->product($agen, 'A'), $this->product($agen, 'B')];
        $d2 = now()->addDays(9)->toDateString();

        // Single-item assigned shipment: redating rejected (F04 round-2 boundary).
        $order1 = $this->order($konsumen, [[$a, 1]], now()->addDays(3)->toDateString());
        $shipment1 = $this->firstShipment($order1);
        $courier = $this->courier($agen);
        $shipment1->update(['courier_id' => $courier->id]);
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order1->id}/items/{$this->item($order1, $a)->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'x'])->assertStatus(422);
        $this->assertSame($shipment1->id, (int) $this->item($order1, $a)->fresh()->shipment_id);
        $this->assertSame($courier->id, (int) $shipment1->fresh()->courier_id);

        // Shared assigned shipment: a pre-delivery item may still move off; the committed shipment is untouched.
        $order2 = $this->order($konsumen, [[$a, 1], [$b, 1]], now()->addDays(3)->toDateString());
        $shipment2 = $this->firstShipment($order2);
        $shipment2->update(['courier_id' => $courier->id]);
        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order2->id}/items/{$this->item($order2, $b)->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'x'])->assertOk();
        $this->assertSame($shipment2->id, (int) $this->item($order2, $a)->fresh()->shipment_id);
        $this->assertSame($courier->id, (int) $shipment2->fresh()->courier_id);
        $this->assertNotSame($shipment2->id, (int) $this->item($order2, $b)->fresh()->shipment_id);
    }

    public function test_tracked_single_item_shipment_is_not_redated(): void
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $this->branch();
        $a = $this->product($agen, 'A');
        $d2 = now()->addDays(9)->toDateString();
        $order = $this->order($konsumen, [[$a, 1]], now()->addDays(3)->toDateString());
        $shipment = $this->firstShipment($order);
        $shipment->update(['tracking_number' => 'RESI-1']);

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/items/{$this->item($order, $a)->id}/reschedule", ['requested_delivery_date' => $d2, 'reason' => 'x'])->assertStatus(422);
        $this->assertSame('RESI-1', $shipment->fresh()->tracking_number);
        $this->assertSame($shipment->id, (int) $this->item($order, $a)->fresh()->shipment_id);
    }
}
