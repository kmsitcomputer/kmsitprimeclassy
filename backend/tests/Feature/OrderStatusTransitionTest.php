<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function makeOrder(string $status = 'diterima'): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko', 'address' => 'Jl. X',
            'latitude' => -6.2, 'longitude' => 106.8,
        ]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);

        // COD by default here — these tests exercise the diterima/diproses/
        // dikirim/terkirim state machine itself, not payment-gated advancement
        // (see PaymentGatewayTest for the manual-transfer/gateway gating rules).
        $order = Order::create([
            'order_no' => 'PC-TEST-'.uniqid(),
            'konsumen_id' => $konsumen->id, 'agent_id' => $agen->id,
            'payment_method_id' => PaymentMethod::where('code', 'cod')->value('id'),
            'status' => $status, 'payment_status' => 'unpaid',
            'subtotal_amount' => 100000, 'total_amount' => 100000,
            'recipient_name_snapshot' => 'A', 'recipient_phone_snapshot' => '0811',
            'address_snapshot' => 'Jl. A',
        ]);

        return compact('agen', 'konsumen', 'admin', 'order');
    }

    public function test_admin_can_advance_order_through_valid_transitions(): void
    {
        ['admin' => $admin, 'order' => $order] = $this->makeOrder('diterima');

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])
            ->assertOk()->assertJsonPath('data.status', 'diproses');

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'dikirim'])
            ->assertOk()->assertJsonPath('data.status', 'dikirim');

        $this->actingAs($admin)->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'terkirim'])
            ->assertOk()->assertJsonPath('data.status', 'terkirim');
    }

    public function test_invalid_transition_is_rejected_for_every_role_including_super_admin(): void
    {
        ['admin' => $admin, 'order' => $order] = $this->makeOrder('diterima');
        $superAdmin = User::factory()->superAdmin()->create();

        // Cannot skip straight from "diterima" to "terkirim".
        $this->actingAs($admin)
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'terkirim'])
            ->assertStatus(422);

        $this->actingAs($superAdmin)
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'terkirim'])
            ->assertStatus(422);
    }

    public function test_only_admin_agen_or_super_admin_may_change_order_status(): void
    {
        ['agen' => $agen, 'konsumen' => $konsumen, 'order' => $order] = $this->makeOrder('diterima');

        $this->actingAs($konsumen)
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])
            ->assertStatus(403);

        $this->actingAs($agen)
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])
            ->assertOk();
    }

    public function test_admin_from_another_agent_cannot_change_this_orders_status(): void
    {
        ['order' => $order] = $this->makeOrder('diterima');
        $otherAgen = User::factory()->agen()->create();
        $otherAgen->update(['agent_id' => $otherAgen->id]);
        $otherAdmin = User::factory()->admin()->create(['agent_id' => $otherAgen->id]);

        $this->actingAs($otherAdmin)
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])
            ->assertStatus(404); // BelongsToAgentScope hides the order entirely.
    }
}
