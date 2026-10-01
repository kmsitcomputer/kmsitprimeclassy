<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Courier;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

/**
 * R-03 / migration verification: the three additive migrations apply cleanly, enforce the intended
 * invariants on MariaDB, and roll back to a clean pre-R-03 schema.
 */
class MigrationVerificationTest extends TestCase
{
    use RestoresIsolatedTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_schema_is_present_and_invariants_are_enforced(): void
    {
        $this->assertTrue(Schema::hasTable('delivery_verifications'));
        $this->assertTrue(Schema::hasColumn('shipments', 'delivery_mode'));
        $this->assertTrue(Schema::hasColumn('shipments', 'self_delivered_by_user_id'));
        $this->assertTrue(Schema::hasColumn('order_items', 'split_from_order_item_id'));
        $this->assertNotEmpty($this->selfSubTriggers());

        [$order, $user, $courier] = $this->context();

        // Valid standard shipment.
        $this->insertShipment($order->id, 'standard', null, null);
        // Valid self_sub shipment (owner set, courier NULL).
        $this->insertShipment($order->id, 'self_sub', $user->id, null);

        // self_sub must never carry a courier (trigger).
        $this->expectQueryException(fn () => $this->insertShipment($order->id, 'self_sub', $user->id, $courier->id));
        // self_sub requires a self-delivered actor (CHECK).
        $this->expectQueryException(fn () => $this->insertShipment($order->id, 'self_sub', null, null));
        // standard must not carry a self-delivered actor (CHECK).
        $this->expectQueryException(fn () => $this->insertShipment($order->id, 'standard', $user->id, null));
    }

    public function test_rollback_removes_everything_and_migrate_restores_it(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 3, '--force' => true]);

        $this->assertFalse(Schema::hasTable('delivery_verifications'));
        $this->assertFalse(Schema::hasColumn('shipments', 'delivery_mode'));
        $this->assertFalse(Schema::hasColumn('shipments', 'self_delivered_by_user_id'));
        $this->assertFalse(Schema::hasColumn('order_items', 'split_from_order_item_id'));
        $this->assertEmpty($this->selfSubTriggers(), 'rollback must drop the self_sub courier triggers');

        Artisan::call('migrate', ['--force' => true]);

        $this->assertTrue(Schema::hasTable('delivery_verifications'));
        $this->assertTrue(Schema::hasColumn('shipments', 'delivery_mode'));
        $this->assertTrue(Schema::hasColumn('order_items', 'split_from_order_item_id'));
        $this->assertNotEmpty($this->selfSubTriggers(), 're-migrate must recreate the triggers');
    }

    /** @return array{0: Order, 1: User, 2: Courier} */
    private function context(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Migration Branch', 'address' => 'Test', 'latitude' => -6.2, 'longitude' => 106.8]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        $order = Order::create([
            'order_no' => 'MIG-'.bin2hex(random_bytes(5)), 'konsumen_id' => $konsumen->id, 'agent_id' => $agen->id,
            'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'),
            'status' => 'diproses', 'payment_status' => 'unpaid',
            'subtotal_amount' => 0, 'total_amount' => 0,
            'recipient_name_snapshot' => 'Test', 'recipient_phone_snapshot' => '0811', 'address_snapshot' => 'Test',
        ]);
        $courier = Courier::create(['type' => 'internal', 'user_id' => $agen->id, 'agent_id' => $agen->id, 'name' => 'Courier', 'is_active' => true]);

        return [$order, $agen, $courier];
    }

    private function insertShipment(int $orderId, string $mode, ?int $selfDeliveredBy, ?int $courierId): void
    {
        DB::table('shipments')->insert([
            'order_id' => $orderId,
            'delivery_mode' => $mode,
            'self_delivered_by_user_id' => $selfDeliveredBy,
            'courier_id' => $courierId,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function expectQueryException(callable $callback): void
    {
        $thrown = false;

        try {
            $callback();
        } catch (QueryException $e) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'Expected the database to reject the invalid shipment row.');
    }

    /** @return array<int, object> */
    private function selfSubTriggers(): array
    {
        return array_values(array_filter(
            DB::select("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()"),
            fn ($t) => str_contains($t->TRIGGER_NAME, 'shipments_self_sub_no_courier'),
        ));
    }
}
