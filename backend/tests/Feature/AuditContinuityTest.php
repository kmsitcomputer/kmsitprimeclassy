<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\DeliveryVerification;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ShippingConfiguration;
use App\Models\Shipment;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use App\Services\Order\CourierService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/**
 * R-03 / audit continuity: the historical delivery actor and verifier must stay resolvable after
 * their account is soft-deleted (Users use SoftDeletes).
 */
class AuditContinuityTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        Storage::fake('public');

        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create(['user_id' => $agen->id, 'store_name' => 'Toko', 'address' => 'Jl. X', 'latitude' => -6.2, 'longitude' => 106.8]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $sub = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id, 'sales_id' => $sub->id]);
        ShippingConfiguration::create(['agent_id' => null, 'price_per_km' => 2000, 'minimum_distance_km' => 0, 'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true]);
        $product = Product::create(['sku' => 'AUD-'.Str::uuid(), 'name' => 'Audit Cake', 'slug' => 'audit-'.uniqid(), 'has_variations' => false, 'base_price' => 50000, 'weight_grams' => 500, 'status' => 'active']);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
        $location = WarehouseSubLocation::create(['agent_id' => $agen->id, 'code' => 'AUD1', 'name' => 'AUD1', 'created_by' => $agen->id]);
        $location->forceFill(['owner_user_id' => $sub->id])->save();
        WarehouseStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'stock_type' => 'sub', 'sub_location_id' => $location->id, 'quantity' => 5]);

        $this->b = compact('agen', 'admin', 'sub', 'konsumen', 'product');
    }

    public function test_delivery_and_verification_actors_stay_resolvable_after_soft_delete(): void
    {
        $id = $this->actingAs($this->b['sub'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod', 'stock_source' => 'sub', 'konsumen_id' => $this->b['konsumen']->id,
                'items' => [['product_id' => $this->b['product']->id, 'quantity' => 1]],
                'recipient_name' => 'Buyer', 'recipient_phone' => '0811', 'address_line' => 'Jl. Buyer',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertCreated()->json('data.id');

        $shipment = OrderItem::where('order_id', $id)->firstOrFail()->shipment;
        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'dikirim', $this->b['sub']);
        app(CourierService::class)->updateShipmentStatus($shipment->fresh(), 'terkirim', $this->b['sub'], UploadedFile::fake()->image('proof.jpg'));

        $this->actingAs($this->b['admin'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/shipments/{$shipment->id}/delivery-verifications", ['outcome' => 'received'])
            ->assertCreated();

        // Soft-delete both actors.
        $this->b['sub']->delete();
        $this->b['admin']->delete();
        $this->assertSoftDeleted('users', ['id' => $this->b['sub']->id]);
        $this->assertSoftDeleted('users', ['id' => $this->b['admin']->id]);

        // Historical references still resolve.
        $this->assertSame($this->b['sub']->id, Shipment::query()->findOrFail($shipment->id)->selfDeliveredBy?->id);
        $this->assertSame($this->b['admin']->id, DeliveryVerification::query()->firstOrFail()->verifiedBy?->id);
    }
}
