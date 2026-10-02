<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderFulfillmentChangeProposal;
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

class OrderFulfillmentChangeProposalTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function fixture(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Proposal test', 'address' => 'x', 'latitude' => -6.2, 'longitude' => 106.8]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $gudang = User::factory()->gudang()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]);
        $other = User::factory()->admin()->create(['agent_id' => User::factory()->agen()->create()->id]);
        $foreignGudang = User::factory()->gudang()->create(['agent_id' => $other->agent_id, 'parent_id' => $other->agent_id]);
        $buyer = User::factory()->konsumen()->create(['agent_id' => $agen->id]);
        $product = Product::create(['sku' => 'PROP-'.Str::uuid(), 'name' => 'Proposal item', 'slug' => 'proposal-'.uniqid(), 'has_variations' => false, 'base_price' => 10000, 'weight_grams' => 100, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 20, 'quantity_reserved' => 0]);
        $response = $this->actingAs($buyer)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'payment_method_code' => 'cod', 'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Street',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ])->assertCreated();
        $order = Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));

        return compact('agen', 'admin', 'gudang', 'other', 'foreignGudang', 'buyer', 'product', 'order');
    }

    private function setFreeShipping(Order $order): void
    {
        Shipment::where('order_id', $order->id)->update(['shipping_provider_code' => 'free', 'shipping_fee_snapshot' => 0]);
        Order::withoutGlobalScopes()->whereKey($order->id)->update(['shipping_fee_amount' => 0]);
    }

    public function test_stock_request_surface_is_gone_and_gudang_can_submit_without_mutating_order(): void
    {
        $f = $this->fixture();
        $item = $f['order']->items()->firstOrFail();
        $this->actingAs($f['gudang'])->getJson('/api/v1/warehouse/stock-requests')->assertNotFound();
        $this->actingAs($f['gudang'])->getJson('/api/v1/warehouse/orders/diproses')->assertOk()->assertJsonPath('data.0.id', $f['order']->id);
        $payload = ['fulfilled_quantity' => 2, 'requested_delivery_date' => $item->requested_delivery_date?->toDateString()];
        $this->actingAs($f['admin'])->postJson("/api/v1/warehouse/orders/{$f['order']->id}/items/{$item->id}/fulfillment-proposals", $payload)->assertForbidden();
        $this->actingAs($f['buyer'])->postJson("/api/v1/warehouse/orders/{$f['order']->id}/items/{$item->id}/fulfillment-proposals", $payload)->assertForbidden();
        $this->actingAs($f['foreignGudang'])->postJson("/api/v1/warehouse/orders/{$f['order']->id}/items/{$item->id}/fulfillment-proposals", $payload)->assertNotFound();
        $this->actingAs($f['gudang'])->postJson("/api/v1/warehouse/orders/{$f['order']->id}/items/{$item->id}/fulfillment-proposals", [])->assertUnprocessable();
        $this->actingAs($f['admin'])->patchJson("/api/v1/orders/{$f['order']->id}/items/{$item->id}/fulfillment", ['fulfilled_quantity' => 2, 'reason' => 'Direct change'])->assertForbidden();
        $this->actingAs($f['admin'])->patchJson("/api/v1/orders/{$f['order']->id}/items/{$item->id}/reschedule", ['requested_delivery_date' => now()->addDay()->toDateString(), 'reason' => 'Direct change'])->assertForbidden();
        $proposalId = $this->actingAs($f['gudang'])->postJson("/api/v1/warehouse/orders/{$f['order']->id}/items/{$item->id}/fulfillment-proposals", [
            'fulfilled_quantity' => 2, 'requested_delivery_date' => $item->requested_delivery_date?->toDateString(), 'reason' => 'Transit belum lengkap',
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->actingAs($f['gudang'])->postJson("/api/v1/warehouse/fulfillment-change-proposals/{$proposalId}/approve")->assertForbidden();
        $this->assertSame(3, $item->fresh()->fulfilled_quantity);
        $this->assertDatabaseHas('activity_logs', ['event' => 'fulfillment_change_proposal.created']);
        $audit = ActivityLog::where('event', 'fulfillment_change_proposal.created')->firstOrFail();
        $this->assertSame(['fulfilled_quantity' => 3, 'requested_delivery_date' => $item->requested_delivery_date?->toDateString()], $audit->properties['current']);
        $this->assertSame(['fulfilled_quantity' => 2, 'requested_delivery_date' => $item->requested_delivery_date?->toDateString()], $audit->properties['proposed']);
    }

    public function test_date_only_proposal_is_pending_until_admin_approval_and_decision_history_is_visible(): void
    {
        $f = $this->fixture();
        $item = $f['order']->items()->firstOrFail();
        $this->setFreeShipping($f['order']);
        $currentDate = $item->requested_delivery_date?->toDateString();
        $proposedDate = now()->addDays(20)->toDateString();
        $originalTotal = (int) $f['order']->fresh()->total_amount;
        $proposalId = $this->actingAs($f['gudang'])->postJson("/api/v1/warehouse/orders/{$f['order']->id}/items/{$item->id}/fulfillment-proposals", [
            'requested_delivery_date' => $proposedDate,
            'reason' => 'Tanggal operasional berubah',
        ])->assertCreated()->assertJsonPath('data.current.requested_delivery_date', $currentDate)
            ->assertJsonPath('data.proposed.requested_delivery_date', $proposedDate)
            ->assertJsonPath('data.proposed.fulfilled_quantity', $item->fulfilled_quantity)->json('data.id');

        $this->assertSame($currentDate, $item->fresh()->requested_delivery_date?->toDateString());
        $this->assertSame($originalTotal, (int) $f['order']->fresh()->total_amount);
        $this->actingAs($f['admin'])->getJson('/api/v1/warehouse/fulfillment-change-proposals?status=pending')
            ->assertOk()->assertJsonPath('data.0.proposer.name', $f['gudang']->name)
            ->assertJsonPath('data.0.order_item.product_name', $f['product']->name);
        $this->actingAs($f['admin'])->postJson("/api/v1/warehouse/fulfillment-change-proposals/{$proposalId}/approve")->assertOk();

        $this->assertSame($proposedDate, $item->fresh()->requested_delivery_date?->toDateString());
        $this->assertSame('approved', OrderFulfillmentChangeProposal::findOrFail($proposalId)->status);
        $this->actingAs($f['admin'])->getJson('/api/v1/warehouse/fulfillment-change-proposals?status=all')
            ->assertOk()->assertJsonPath('data.0.status', 'approved')->assertJsonPath('data.0.decider.name', $f['admin']->name);
    }

    public function test_quantity_and_date_proposal_applies_both_changes_atomically(): void
    {
        $f = $this->fixture();
        $item = $f['order']->items()->firstOrFail();
        $this->setFreeShipping($f['order']);
        $proposedDate = now()->addDays(25)->toDateString();
        $originalTotal = (int) $f['order']->fresh()->total_amount;
        $originalReserved = (int) ProductStock::where('agent_id', $f['agen']->id)->where('product_id', $f['product']->id)->value('quantity_reserved');
        $proposalId = $this->actingAs($f['gudang'])->postJson("/api/v1/warehouse/orders/{$f['order']->id}/items/{$item->id}/fulfillment-proposals", [
            'fulfilled_quantity' => 2,
            'requested_delivery_date' => $proposedDate,
            'reason' => 'Penyesuaian jumlah dan tanggal',
        ])->assertCreated()->json('data.id');

        $this->assertSame(3, $item->fresh()->fulfilled_quantity);
        $this->assertNotSame($proposedDate, $item->fresh()->requested_delivery_date?->toDateString());
        $this->actingAs($f['admin'])->postJson("/api/v1/warehouse/fulfillment-change-proposals/{$proposalId}/approve")->assertOk();

        $this->assertSame(2, $item->fresh()->fulfilled_quantity);
        $this->assertSame($proposedDate, $item->fresh()->requested_delivery_date?->toDateString());
        $this->assertSame($originalReserved - 1, (int) ProductStock::where('agent_id', $f['agen']->id)->where('product_id', $f['product']->id)->value('quantity_reserved'));
        $this->assertSame($originalTotal - 10000, (int) $f['order']->fresh()->total_amount);
        $this->assertSame('approved', OrderFulfillmentChangeProposal::findOrFail($proposalId)->status);
    }

    public function test_quantity_only_proposal_preserves_the_current_delivery_date(): void
    {
        $f = $this->fixture();
        $item = $f['order']->items()->firstOrFail();
        $this->setFreeShipping($f['order']);
        $currentDate = now()->addDays(18)->toDateString();
        $item->update(['requested_delivery_date' => $currentDate]);
        $proposalId = $this->actingAs($f['gudang'])->postJson("/api/v1/warehouse/orders/{$f['order']->id}/items/{$item->id}/fulfillment-proposals", [
            'fulfilled_quantity' => 2,
        ])->assertCreated()->assertJsonPath('data.proposed.requested_delivery_date', $currentDate)->json('data.id');

        $this->assertSame($currentDate, $item->fresh()->requested_delivery_date?->toDateString());
        $this->actingAs($f['admin'])->postJson("/api/v1/warehouse/fulfillment-change-proposals/{$proposalId}/approve")->assertOk();
        $this->assertSame(2, $item->fresh()->fulfilled_quantity);
        $this->assertSame($currentDate, $item->fresh()->requested_delivery_date?->toDateString());
    }

    public function test_shipping_method_restrictions_cannot_be_bypassed_by_admin_approval(): void
    {
        foreach (['rajaongkir', 'pickup', 'legacy-provider'] as $providerCode) {
            $f = $this->fixture();
            $item = $f['order']->items()->firstOrFail();
            $currentDate = $item->requested_delivery_date?->toDateString();
            $proposedDate = now()->addDays(22)->toDateString();
            Shipment::where('order_id', $f['order']->id)->update(['shipping_provider_code' => $providerCode]);
            $proposalId = $this->actingAs($f['gudang'])->postJson("/api/v1/warehouse/orders/{$f['order']->id}/items/{$item->id}/fulfillment-proposals", [
                'fulfilled_quantity' => $item->fulfilled_quantity,
                'requested_delivery_date' => $proposedDate,
            ])->assertCreated()->json('data.id');

            $this->actingAs($f['admin'])->postJson("/api/v1/warehouse/fulfillment-change-proposals/{$proposalId}/approve")->assertStatus(422);
            $this->assertSame($currentDate, $item->fresh()->requested_delivery_date?->toDateString());
            $this->assertSame('pending', OrderFulfillmentChangeProposal::findOrFail($proposalId)->status);
        }
    }

    public function test_admin_rejects_without_mutation_and_approve_applies_once(): void
    {
        $f = $this->fixture();
        $item = $f['order']->items()->firstOrFail();
        $this->setFreeShipping($f['order']);
        $originalDate = $item->requested_delivery_date?->toDateString();
        $originalTotal = (int) $f['order']->fresh()->total_amount;
        $originalReserved = (int) ProductStock::where('agent_id', $f['agen']->id)->where('product_id', $f['product']->id)->value('quantity_reserved');
        $originalShipments = Shipment::where('order_id', $f['order']->id)->pluck('id')->all();
        $rejectedDate = now()->addDays(19)->toDateString();
        $proposal = $this->actingAs($f['gudang'])->postJson("/api/v1/warehouse/orders/{$f['order']->id}/items/{$item->id}/fulfillment-proposals", [
            'fulfilled_quantity' => 2, 'requested_delivery_date' => $rejectedDate,
        ])->assertCreated()->json('data.id');
        $this->actingAs($f['other'])->postJson("/api/v1/warehouse/fulfillment-change-proposals/{$proposal}/approve")->assertNotFound();
        $this->actingAs($f['admin'])->postJson("/api/v1/warehouse/fulfillment-change-proposals/{$proposal}/reject", ['reason' => 'Cek ulang'])->assertOk();
        $this->assertSame(3, $item->fresh()->fulfilled_quantity);
        $this->assertSame($originalDate, $item->fresh()->requested_delivery_date?->toDateString());
        $this->assertSame($originalTotal, (int) $f['order']->fresh()->total_amount);
        $this->assertSame($originalReserved, (int) ProductStock::where('agent_id', $f['agen']->id)->where('product_id', $f['product']->id)->value('quantity_reserved'));
        $this->assertSame($originalShipments, Shipment::where('order_id', $f['order']->id)->pluck('id')->all());
        $rejectAudit = ActivityLog::where('event', 'fulfillment_change_proposal.rejected')->firstOrFail();
        $this->assertSame(3, $rejectAudit->properties['current']['fulfilled_quantity']);
        $this->assertSame(2, $rejectAudit->properties['proposed']['fulfilled_quantity']);
        $this->actingAs($f['admin'])->getJson('/api/v1/warehouse/fulfillment-change-proposals?status=rejected')
            ->assertOk()->assertJsonPath('data.0.status', 'rejected');

        $proposal = $this->actingAs($f['gudang'])->postJson("/api/v1/warehouse/orders/{$f['order']->id}/items/{$item->id}/fulfillment-proposals", ['fulfilled_quantity' => 2])->assertCreated()->json('data.id');
        $this->actingAs($f['admin'])->postJson("/api/v1/warehouse/fulfillment-change-proposals/{$proposal}/approve")->assertOk();
        $this->assertSame(2, $item->fresh()->fulfilled_quantity);
        $approvedTotal = (int) $f['order']->fresh()->total_amount;
        $approvedReserved = (int) ProductStock::where('agent_id', $f['agen']->id)->where('product_id', $f['product']->id)->value('quantity_reserved');
        $approvedShipments = Shipment::where('order_id', $f['order']->id)->pluck('id')->all();
        $this->actingAs($f['admin'])->postJson("/api/v1/warehouse/fulfillment-change-proposals/{$proposal}/approve")->assertOk();
        $this->assertSame($approvedTotal, (int) $f['order']->fresh()->total_amount);
        $this->assertSame($approvedReserved, (int) ProductStock::where('agent_id', $f['agen']->id)->where('product_id', $f['product']->id)->value('quantity_reserved'));
        $this->assertSame($approvedShipments, Shipment::where('order_id', $f['order']->id)->pluck('id')->all());
        $this->assertSame(2, $item->fresh()->fulfilled_quantity);
        $this->assertSame('approved', OrderFulfillmentChangeProposal::findOrFail($proposal)->status);
    }

    public function test_stale_proposal_fails_closed_and_foreign_order_is_denied(): void
    {
        $f = $this->fixture();
        $item = $f['order']->items()->firstOrFail();
        $baseDate = $item->requested_delivery_date?->toDateString();
        $proposal = $this->actingAs($f['gudang'])->postJson("/api/v1/warehouse/orders/{$f['order']->id}/items/{$item->id}/fulfillment-proposals", ['fulfilled_quantity' => 2])->assertCreated()->json('data.id');
        $item->update(['requested_delivery_date' => now()->addDay()->toDateString()]);
        $this->actingAs($f['admin'])->postJson("/api/v1/warehouse/fulfillment-change-proposals/{$proposal}/approve")->assertStatus(409);
        $this->assertSame('pending', OrderFulfillmentChangeProposal::findOrFail($proposal)->status);
        $item->update(['requested_delivery_date' => $baseDate, 'fulfilled_quantity' => 2]);
        $this->actingAs($f['admin'])->postJson("/api/v1/warehouse/fulfillment-change-proposals/{$proposal}/approve")->assertStatus(409);
        $this->assertSame(2, $item->fresh()->fulfilled_quantity);
        $this->assertSame('pending', OrderFulfillmentChangeProposal::findOrFail($proposal)->status);

        $this->actingAs($f['gudang'])->postJson('/api/v1/warehouse/orders/999999/items/999999/fulfillment-proposals', ['fulfilled_quantity' => 1])->assertNotFound();
    }
}