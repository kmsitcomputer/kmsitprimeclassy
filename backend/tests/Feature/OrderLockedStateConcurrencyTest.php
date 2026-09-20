<?php

namespace Tests\Feature;

use App\Models\InventoryCancellationReversal;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\StockMovement;
use App\Models\StockRequest;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ConcurrencyHarness;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

class OrderLockedStateConcurrencyTest extends TestCase
{
    use RestoresIsolatedTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_update_status_revalidates_transition_after_acquiring_the_order_lock(): void
    {
        $this->runLockedStateRace('update', 'diterima', 'diproses');
    }

    public function test_cancel_revalidates_transition_after_acquiring_the_order_lock(): void
    {
        $this->runLockedStateRace('cancel', 'diproses', 'dikirim');
    }

    private function runLockedStateRace(string $operation, string $initialStatus, string $finalStatus): void
    {
        $fixture = $this->createFixture($initialStatus);
        $harness = new ConcurrencyHarness;

        try {
            $report = $harness->runOrderLockedStateRace([
                'order_id' => $fixture['order']->id,
                'actor_id' => $fixture['admin']->id,
            ], $operation);

            $this->assertTrue($report['different_connections']);
            $this->assertTrue($report['stale_observation']);
            $this->assertSame($initialStatus, $report['service']['observed_status']);
            $this->assertSame($finalStatus, $report['writer']['committed_status']);
            $this->assertSame('rejected', $report['service']['outcome'], json_encode($report, JSON_UNESCAPED_SLASHES));
            $this->assertSame($finalStatus, $fixture['order']->fresh()->status);
            $this->assertSame(0, StockRequest::withoutGlobalScopes()->where('order_id', $fixture['order']->id)->count());
            $this->assertSame(0, InventoryCancellationReversal::where('order_id', $fixture['order']->id)->count());
            $this->assertSame(0, StockMovement::withoutGlobalScopes()
                ->where('reference_type', Order::class)
                ->where('reference_id', $fixture['order']->id)
                ->count());
            $this->assertSame([], $harness->staleBarrierPaths());
            $this->assertSame([], $harness->orphanProcesses());
        } finally {
            $this->cleanupFixture($fixture);
        }
    }

    private function createFixture(string $status): array
    {
        $agent = User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        $admin = User::factory()->admin()->create(['agent_id' => $agent->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agent->id]);
        $order = Order::create([
            'order_no' => 'LOCK-'.Str::random(12),
            'konsumen_id' => $konsumen->id,
            'agent_id' => $agent->id,
            'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'),
            'status' => $status,
            'payment_status' => 'unpaid',
            'subtotal_amount' => 0,
            'total_amount' => 0,
            'recipient_name_snapshot' => 'Concurrency Test',
            'recipient_phone_snapshot' => '0811',
            'address_snapshot' => 'Concurrency Test',
        ]);

        return compact('agent', 'admin', 'konsumen', 'order');
    }

    private function cleanupFixture(array $fixture): void
    {
        DB::table('activity_logs')
            ->where('subject_type', Order::class)
            ->where('subject_id', $fixture['order']->id)
            ->delete();
        $fixture['order']->delete();
        User::whereIn('id', [
            $fixture['admin']->id,
            $fixture['konsumen']->id,
            $fixture['agent']->id,
        ])->delete();
    }
}
