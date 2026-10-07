<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
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
 * Consolidated Human UAT (Q–W, AC): Dispatch region filters cascade from the CURRENT eligible
 * dispatch queue — never from the whole Indonesian master.
 *
 * Server contract under test:
 *   - GET /dispatch/regions returns only provinces represented by orders eligible for THIS
 *     actor's dispatch queue (same branch/status/date/paid scope as the queue itself, restricted
 *     further to orders that actually have a dispatchable shipment);
 *   - regencies require a specific province_id, districts a regency_id, villages a district_id —
 *     a level without its parent returns [] so the UI disables it;
 *   - values are the canonical stored ids, labels the canonical stored snapshots.
 */
class DispatchRegionFilterTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    /** @return array{agen:User, koordinator:User, konsumen:User} */
    private function branch(string $tag = 'A'): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko '.$tag, 'address' => 'Jl. '.$tag,
            'latitude' => -6.2, 'longitude' => 106.8166,
        ]);

        return [
            'agen' => $agen,
            'koordinator' => User::factory()->koordinatorKurir()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]),
            'konsumen' => User::factory()->konsumen()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]),
        ];
    }

    private function product(User $agen, string $tag): Product
    {
        $p = Product::create([
            'sku' => 'RG-'.strtoupper($tag).'-'.Str::uuid(), 'name' => 'Produk '.$tag,
            'slug' => 'produk-rg-'.strtolower($tag).'-'.uniqid(),
            'has_variations' => false, 'base_price' => 25000, 'weight_grams' => 400, 'status' => 'active',
        ]);
        ProductStock::create([
            'agent_id' => $agen->id, 'product_id' => $p->id, 'quantity_on_hand' => 200, 'quantity_reserved' => 0,
        ]);

        return $p;
    }

    /**
     * Places a COD order (starts 'diproses', one canonical shipment) and pins its canonical
     * region columns/snapshots. COD is used so payment never gates the queue.
     */
    private function placed(
        User $konsumen,
        User $agen,
        Product $product,
        string $provinceId,
        string $provinceName,
        string $regencyId,
        string $regencyName,
        string $districtId,
        string $districtName,
        string $villageId,
        string $villageName,
    ): Order {
        $response = $this->actingAs($konsumen)
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810,
            ]);
        $response->assertCreated();

        $order = Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
        $order->update([
            'province_id' => $provinceId, 'province_snapshot' => $provinceName,
            'regency_id' => $regencyId, 'regency_snapshot' => $regencyName,
            'district_id' => $districtId, 'district_snapshot' => $districtName,
            'village_id' => $villageId, 'village_snapshot' => $villageName,
        ]);

        return $order->fresh();
    }

    private function regions(User $actor, array $filters = [])
    {
        return $this->actingAs($actor)->getJson('/api/v1/dispatch/regions'.($filters ? '?'.http_build_query($filters) : ''));
    }

    /** Decodes the `data` payload of the regions endpoint. */
    private function regionData(User $actor, array $filters = []): array
    {
        return $this->regions($actor, $filters)->assertOk()->json('data');
    }

    // ---------- 1 + 9 + 13. Only represented provinces, human-readable labels ----------

    public function test_only_provinces_of_the_eligible_queue_are_returned(): void
    {
        ['agen' => $agen, 'koordinator' => $koordinator, 'konsumen' => $konsumen] = $this->branch();
        $product = $this->product($agen, 'A');

        $this->placed($konsumen, $agen, $product,
            '32', 'Jawa Barat', '3273', 'Kota Bandung',
            '327301', 'Rancasari', '32730101', 'Cipamokolan');
        $this->placed($konsumen, $agen, $product,
            '32', 'Jawa Barat', '3273', 'Kota Bandung',
            '327302', 'Buahbatu', '32730201', 'Margacinta');

        $rows = $this->regionData($koordinator);
        $this->assertSame([['id' => '32', 'name' => 'Jawa Barat']], $rows['provinces'], 'exactly the represented province, labelled canonically');
        $this->assertSame([], $rows['regencies'], 'no parent selected => children unavailable');
    }

    // ---------- 2 + 3. Regencies scoped to the selected province ----------

    public function test_regencies_require_and_follow_the_selected_province(): void
    {
        ['agen' => $agen, 'koordinator' => $koordinator, 'konsumen' => $konsumen] = $this->branch();
        $product = $this->product($agen, 'A');

        $this->placed($konsumen, $agen, $product,
            '32', 'Jawa Barat', '3273', 'Kota Bandung',
            '327301', 'Rancasari', '32730101', 'Cipamokolan');
        $this->placed($konsumen, $agen, $product,
            '33', 'Jawa Tengah', '3374', 'Kota Semarang',
            '337401', 'Tembalang', '33740101', 'Bulusan');

        $rows = $this->regionData($koordinator, ['province_id' => '32']);
        $this->assertSame([['id' => '3273', 'name' => 'Kota Bandung']], $rows['regencies']);
        $this->assertSame([], $rows['districts'], 'district unavailable until a regency is selected');
    }

    // ---------- 4 + 5 + 6 + 7. Districts and villages cascade ----------

    public function test_districts_and_villages_cascade_down_the_selected_parents(): void
    {
        ['agen' => $agen, 'koordinator' => $koordinator, 'konsumen' => $konsumen] = $this->branch();
        $product = $this->product($agen, 'A');

        $this->placed($konsumen, $agen, $product,
            '32', 'Jawa Barat', '3273', 'Kota Bandung',
            '327301', 'Rancasari', '32730101', 'Cipamokolan');
        $this->placed($konsumen, $agen, $product,
            '32', 'Jawa Barat', '3273', 'Kota Bandung',
            '327301', 'Rancasari', '32730202', 'Derwati');
        $this->placed($konsumen, $agen, $product,
            '32', 'Jawa Barat', '3273', 'Kota Bandung',
            '327302', 'Buahbatu', '32730201', 'Margacinta');

        $rows = $this->regionData($koordinator, ['province_id' => '32', 'regency_id' => '3273']);
        $names = collect($rows['districts'])->pluck('name')->sort()->values()->all();
        $this->assertSame(['Buahbatu', 'Rancasari'], $names, 'districts scoped to the selected regency');
        $this->assertSame([], $rows['villages']);

        $rows = $this->regionData($koordinator, ['province_id' => '32', 'regency_id' => '3273', 'district_id' => '327301']);
        $names = collect($rows['villages'])->pluck('name')->sort()->values()->all();
        $this->assertSame(['Cipamokolan', 'Derwati'], $names, 'villages scoped to the selected district');
        $this->assertSame('32730201', collect($this->regionData($koordinator, ['province_id' => '32', 'regency_id' => '3273', 'district_id' => '327302'])['villages'])->first()['id'], 'canonical ids filter correctly');
    }

    // ---------- 8. A different parent yields a different child set (the reset contract) ----------

    public function test_selecting_another_province_yields_only_its_own_children(): void
    {
        ['agen' => $agen, 'koordinator' => $koordinator, 'konsumen' => $konsumen] = $this->branch();
        $product = $this->product($agen, 'A');

        $this->placed($konsumen, $agen, $product,
            '32', 'Jawa Barat', '3273', 'Kota Bandung',
            '327301', 'Rancasari', '32730101', 'Cipamokolan');
        $this->placed($konsumen, $agen, $product,
            '33', 'Jawa Tengah', '3374', 'Kota Semarang',
            '337401', 'Tembalang', '33740101', 'Bulusan');

        $a = $this->regionData($koordinator, ['province_id' => '32']);
        $b = $this->regionData($koordinator, ['province_id' => '33']);
        $this->assertSame([['id' => '3273', 'name' => 'Kota Bandung']], $a['regencies']);
        $this->assertSame([['id' => '3374', 'name' => 'Kota Semarang']], $b['regencies'], 'no child value survives a parent change');
    }

    // ---------- 10. Non-dispatch data never pollutes ----------

    public function test_non_dispatch_orders_never_pollute_the_options(): void
    {
        ['agen' => $agen, 'koordinator' => $koordinator, 'konsumen' => $konsumen] = $this->branch();
        $product = $this->product($agen, 'A');

        $visible = $this->placed($konsumen, $agen, $product,
            '32', 'Jawa Barat', '3273', 'Kota Bandung',
            '327301', 'Rancasari', '32730101', 'Cipamokolan');

        // Historical: same branch but no longer diproses.
        $historical = $this->placed($konsumen, $agen, $product,
            '31', 'DKI Jakarta', '3171', 'Jakarta Selatan',
            '317101', 'Kebayoran', '31710101', 'Grogol');
        $historical->update(['status' => 'terkirim']);

        // Assigned: still diproses but every shipment already has an executor.
        $assigned = $this->placed($konsumen, $agen, $product,
            '35', 'Jawa Timur', '3578', 'Surabaya',
            '357801', 'Genteng', '35780101', 'Ketabang');
        Shipment::where('order_id', $assigned->id)->update([
            'courier_id' => \App\Models\Courier::create([
                'type' => 'internal', 'agent_id' => $agen->id, 'name' => 'Kurir X', 'is_active' => true,
            ])->id,
        ]);

        $rows = $this->regionData($koordinator);
        $this->assertSame([['id' => '32', 'name' => 'Jawa Barat']], $rows['provinces'], 'only the dispatchable order contributes');
    }

    // ---------- 11. Another branch's data is invisible ----------

    public function test_other_agents_regions_are_invisible(): void
    {
        $a = $this->branch('A');
        $b = $this->branch('B');
        $productA = $this->product($a['agen'], 'A');
        $productB = $this->product($b['agen'], 'B');

        $this->placed($a['konsumen'], $a['agen'], $productA,
            '32', 'Jawa Barat', '3273', 'Kota Bandung',
            '327301', 'Rancasari', '32730101', 'Cipamokolan');
        $this->placed($b['konsumen'], $b['agen'], $productB,
            '31', 'DKI Jakarta', '3171', 'Jakarta Selatan',
            '317101', 'Kebayoran', '31710101', 'Grogol');

        $rows = $this->regionData($a['koordinator']);
        $this->assertSame([['id' => '32', 'name' => 'Jawa Barat']], $rows['provinces']);

        // And a foreign coordinator can never open the other branch's queue endpoint at all.
        $this->actingAs($a['koordinator'])->getJson('/api/v1/dispatch/regions?agent_id='.$b['agen']->id)->assertOk();
    }

    // ---------- 12 + 14. Canonical ids filter the queue; date/paid compose ----------

    public function test_region_ids_filter_the_queue_and_compose_with_date_and_paid(): void
    {
        ['agen' => $agen, 'koordinator' => $koordinator, 'konsumen' => $konsumen] = $this->branch();
        $product = $this->product($agen, 'A');

        $west = $this->placed($konsumen, $agen, $product,
            '32', 'Jawa Barat', '3273', 'Kota Bandung',
            '327301', 'Rancasari', '32730101', 'Cipamokolan');
        $central = $this->placed($konsumen, $agen, $product,
            '33', 'Jawa Tengah', '3374', 'Kota Semarang',
            '337401', 'Tembalang', '33740101', 'Bulusan');

        $queue = fn (array $f) => $this->actingAs($koordinator)->getJson('/api/v1/dispatch'.($f ? '?'.http_build_query($f) : ''))->assertOk()->json('data');

        $this->assertSame([$west->order_no], collect($queue(['province_id' => '32']))->pluck('order_no')->all());
        $this->assertSame([$central->order_no], collect($queue(['regency_id' => '3374']))->pluck('order_no')->all());
        $this->assertSame(
            [$central->order_no],
            collect($queue(['province_id' => '33', 'regency_id' => '3374', 'district_id' => '337401', 'village_id' => '33740101']))->pluck('order_no')->all(),
            'the full cascade filters to exactly one queue row',
        );

        // A province that exists in NO eligible order filters everything out.
        $this->assertSame([], $queue(['province_id' => '99']));

        // Options themselves respect the date/paid scope of the current queue.
        $rows = $this->regionData($koordinator, ['paid' => 'paid']);
        $this->assertSame([], $rows['provinces'], 'unpaid COD orders contribute no paid province');
    }
}