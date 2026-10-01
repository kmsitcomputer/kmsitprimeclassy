<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\DeliveryVerification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;
use Tests\Support\ConcurrencyHarness;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

/**
 * R-03 / MAJOR-4: two concurrent EXACT replays of one delivery verification (same actor, key,
 * shipment, outcome, note) must produce exactly one append-only row on separate connections — the
 * loser replays the winner instead of erroring or duplicating history.
 */
class DeliveryVerificationConcurrencyTest extends TestCase
{
    use RestoresIsolatedTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_concurrent_exact_replay_creates_exactly_one_verification(): void
    {
        $fixture = $this->createFixture();

        for ($iteration = 1; $iteration <= 3; $iteration++) {
            $harness = new ConcurrencyHarness;

            $report = $harness->runServiceRace(
                ['op' => 'delivery-verify', 'actor_id' => $fixture['admin']->id, 'subject_id' => $fixture['shipment']->id, 'extra' => ['outcome' => 'received', 'note' => 'race note', 'key' => 'race-verify-key']],
                ['op' => 'delivery-verify', 'actor_id' => $fixture['admin']->id, 'subject_id' => $fixture['shipment']->id, 'extra' => ['outcome' => 'received', 'note' => 'race note', 'key' => 'race-verify-key']],
            );

            $outcomes = [(string) ($report['a']['outcome'] ?? ''), (string) ($report['b']['outcome'] ?? '')];

            $this->assertTrue($report['different_connections']);
            $this->assertTrue($report['true_overlap']);
            $this->assertSame(1, DeliveryVerification::query()->where('shipment_id', $fixture['shipment']->id)->count(), 'exactly one append-only verification row');
            $this->assertSame(['success', 'success'], $outcomes, 'both actors resolve successfully (one creates, one replays)');
        }
    }

    private function createFixture(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'DV Race Branch', 'address' => 'Test', 'latitude' => -6.2, 'longitude' => 106.8]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);

        $product = Product::create(['sku' => 'DVCONC-'.Str::uuid(), 'name' => 'DV Race Cake', 'slug' => 'dv-race-'.Str::uuid(), 'has_variations' => false, 'base_price' => 1000, 'weight_grams' => 100, 'status' => 'active']);
        $order = Order::create([
            'order_no' => 'DVC-'.Str::random(10), 'konsumen_id' => $konsumen->id, 'agent_id' => $agen->id,
            'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'),
            'status' => 'terkirim', 'payment_status' => 'unpaid',
            'subtotal_amount' => 1000, 'total_amount' => 1000,
            'recipient_name_snapshot' => 'Test', 'recipient_phone_snapshot' => '0811', 'address_snapshot' => 'Test',
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku,
            'unit_price_snapshot' => 1000, 'subtotal_snapshot' => 1000,
            'original_quantity' => 1, 'fulfilled_quantity' => 1, 'status' => 'terkirim',
        ]);
        $shipment = Shipment::create([
            'order_id' => $order->id, 'status' => 'delivered', 'delivered_at' => now(),
        ]);
        $item->update(['shipment_id' => $shipment->id]);

        return compact('agen', 'admin', 'konsumen', 'product', 'order', 'item', 'shipment');
    }
}
