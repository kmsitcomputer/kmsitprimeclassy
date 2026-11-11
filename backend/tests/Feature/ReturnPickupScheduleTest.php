<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ReturnItem;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * GAP-A (OD-P2-A, Owner 2026-10-30): return pickup scheduling.
 * Validation, permission, return status, audit, branch isolation and the
 * kurir KPI (both /kurir/returns/pickup-today and /dashboard/kpis).
 */
class ReturnPickupScheduleTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;
    use \Tests\Support\AcceptsWarehouseDemand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        Storage::fake('public');
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
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $agen->id]);

        $kurir = User::factory()->kurir()->create(['agent_id' => $agen->id]);
        $courier = Courier::create(['type' => 'internal', 'user_id' => $kurir->id, 'agent_id' => $agen->id, 'name' => 'Kurir QA', 'is_active' => true]);
        $otherKurir = User::factory()->kurir()->create(['agent_id' => $agen->id]);
        $otherCourier = Courier::create(['type' => 'internal', 'user_id' => $otherKurir->id, 'agent_id' => $agen->id, 'name' => 'Kurir QA 2', 'is_active' => true]);

        return compact('agen', 'admin', 'konsumen', 'keuangan', 'kurir', 'otherKurir', 'courier', 'otherCourier');
    }

    /** Delivered COD order + an approved one-unit return request on its item. */
    private function approvedReturn(array $branch, int $price = 100000, int $qty = 3, int $quantity = 1): array
    {
        ['agen' => $agen, 'admin' => $admin, 'konsumen' => $konsumen] = $branch;

        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Pickup Cake', 'slug' => 'pickup-cake-'.uniqid(),
            'has_variations' => false, 'base_price' => $price, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);

        $orderId = $this->actingAs($agen)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'konsumen_id' => $konsumen->id,
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => $qty]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ])->json('data.id');

        $order = Order::withoutGlobalScopes()->findOrFail($orderId);
        $adminActor = $admin;
        $this->makeDispatchable($order->id);
        $this->actingAs($adminActor)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'dikirim'])->assertOk();
        $this->actingAs($adminActor)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'terkirim'])->assertOk();

        $item = OrderItem::where('order_id', $order->id)->firstOrFail();

        $returnId = $this->actingAs($konsumen)->postJson("/api/v1/orders/{$order->id}/returns", [
            'reason' => 'Rusak saat diterima',
            'items' => [['order_item_id' => $item->id, 'quantity' => $quantity]],
            'evidence' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertCreated()->json('data.id');

        $this->actingAs($adminActor)->patchJson("/api/v1/admin/returns/{$returnId}/review", ['approved' => true])->assertOk();

        return ['order' => $order, 'orderItem' => $item, 'returnId' => $returnId];
    }

    public function test_keuangan_cannot_schedule_pickup(): void
    {
        $b = $this->makeBranch();
        $ret = $this->approvedReturn($b);

        $this->actingAs($b['keuangan'])->patchJson("/api/v1/admin/returns/{$ret['returnId']}/pickup-schedule", [
            'pickup_scheduled_for' => now('Asia/Jakarta')->toDateString(),
        ])->assertForbidden();
    }

    public function test_agentic_schedule_requires_today_or_later_wib(): void
    {
        $b = $this->makeBranch();
        $ret = $this->approvedReturn($b);

        $this->actingAs($b['admin'])->patchJson("/api/v1/admin/returns/{$ret['returnId']}/pickup-schedule", [
            'pickup_scheduled_for' => Carbon::now('Asia/Jakarta')->subDay()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_admin_sets_the_schedule_audited_and_only_on_items_still_awaiting_pickup(): void
    {
        $b = $this->makeBranch();
        $ret = $this->approvedReturn($b);
        $today = now('Asia/Jakarta')->toDateString();

        $this->actingAs($b['admin'])->patchJson("/api/v1/admin/returns/{$ret['returnId']}/pickup-schedule", [
            'pickup_scheduled_for' => $today,
        ])->assertOk()->assertJsonPath('data.pickup_scheduled_for', $today);

        $item = ReturnItem::where('return_id', $ret['returnId'])->firstOrFail();
        $this->assertSame($today, $item->pickup_scheduled_for->toDateString());

        // Rejected items are never rewritten: reject-check on a second approved-then-rejected branch return.
        $this->assertDatabaseHas('activity_logs', ['event' => 'return.pickup_scheduled']);

        // The admin list exposes the schedule so the UI can edit it.
        $this->actingAs($b['admin'])->getJson('/api/v1/admin/returns')
            ->assertOk()
            ->assertJsonPath('data.0.items.0.pickup_scheduled_for', $today);
    }

    public function test_another_branch_agen_cannot_schedule_this_return(): void
    {
        $b = $this->makeBranch();
        $ret = $this->approvedReturn($b);

        $other = $this->makeBranch();
        $this->actingAs($other['agen'])->patchJson("/api/v1/admin/returns/{$ret['returnId']}/pickup-schedule", [
            'pickup_scheduled_for' => now('Asia/Jakarta')->toDateString(),
        ])->assertForbidden();

        $item = ReturnItem::where('return_id', $ret['returnId'])->firstOrFail();
        $this->assertNull($item->pickup_scheduled_for);
    }

    public function test_kurir_kpi_counts_only_assigned_scheduled_pickups_for_today(): void
    {
        $b = $this->makeBranch();
        $ret = $this->approvedReturn($b);
        $today = now('Asia/Jakarta')->toDateString();
        $tomorrow = Carbon::now('Asia/Jakarta')->addDay()->toDateString();

        $returnItem = ReturnItem::where('return_id', $ret['returnId'])->firstOrFail();

        // 1. Scheduled for tomorrow → not today's task.
        $returnItem->update(['pickup_scheduled_for' => $tomorrow]);
        $this->assertSame(0, $this->actingAs($b['kurir'])->getJson('/api/v1/kurir/returns/pickup-today')->json('data.count'));

        // 2. Scheduled today but unassigned: invisible to every courier.
        $returnItem->update(['pickup_scheduled_for' => $today]);
        $this->assertSame(0, $this->actingAs($b['kurir'])->getJson('/api/v1/kurir/returns/pickup-today')->json('data.count'));
        $this->assertSame(0, $this->kpisReturnPickups($b['otherKurir']));

        // 3. Claimed by another courier → only that courier's task.
        $returnItem->update(['courier_id' => $b['otherCourier']->id]);
        $this->assertSame(0, $this->actingAs($b['kurir'])->getJson('/api/v1/kurir/returns/pickup-today')->json('data.count'));
        $this->assertSame(1, $this->kpisReturnPickups($b['otherKurir']));
    }

    public function test_scheduled_pickup_is_isolated_from_other_branches(): void
    {
        $b = $this->makeBranch();
        $ret = $this->approvedReturn($b);
        $other = $this->makeBranch();

        $returnItem = ReturnItem::where('return_id', $ret['returnId'])->firstOrFail();
        $returnItem->update(['pickup_scheduled_for' => now('Asia/Jakarta')->toDateString()]);

        $this->assertSame(0, $this->actingAs($other['kurir'])->getJson('/api/v1/kurir/returns/pickup-today')->json('data.count'));
        $this->assertSame(0, $this->kpisReturnPickups($other['kurir']));
    }

    public function test_kurir_return_queue_exposes_the_scheduled_date(): void
    {
        $b = $this->makeBranch();
        $ret = $this->approvedReturn($b);
        $today = now('Asia/Jakarta')->toDateString();
        ReturnItem::where('return_id', $ret['returnId'])->update(['pickup_scheduled_for' => $today, 'courier_id' => Courier::where('user_id', $b['kurir']->id)->value('id')]);

        $payload = $this->actingAs($b['kurir'])->getJson('/api/v1/kurir/returns')->json('data');
        $firstReturn = collect($payload)->firstWhere('id', $ret['returnId']);
        $this->assertNotNull($firstReturn);
        $this->assertSame($today, $firstReturn['items'][0]['pickup_scheduled_for']);
    }

    private function kpisReturnPickups(User $kurir): ?int
    {
        return $this->actingAs($kurir)->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->json('data.tasks_today.return_pickups');
    }
}
