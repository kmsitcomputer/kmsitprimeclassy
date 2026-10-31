<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use App\Services\Payment\AvailablePaymentMethodService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/** Improvement 04 — checkout payment methods follow the acting role; the backend is the authority. */
class RoleBasedPaymentMethodsTest extends TestCase
{
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko', 'address' => 'Jl. X',
            'latitude' => -6.2, 'longitude' => 106.8,
        ]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id]);

        return compact('agen', 'konsumen');
    }

    public function test_konsumen_is_offered_bank_transfer_only_while_agen_keeps_cod_and_dp(): void
    {
        $b = $this->branch();
        $svc = app(AvailablePaymentMethodService::class);

        $this->assertSame(['bank_transfer'], $svc->availableMethods($b['agen']->id, null, $b['konsumen'])->pluck('code')->all());

        $agenCodes = $svc->availableMethods($b['agen']->id, null, $b['agen'])->pluck('code')->all();
        $this->assertContains('cod', $agenCodes);
        $this->assertContains('bank_transfer', $agenCodes);
        $this->assertContains('down_payment', $agenCodes);
    }

    public function test_forged_cod_or_dp_payload_from_a_konsumen_is_rejected_by_the_backend(): void
    {
        $b = $this->branch();
        $product = Product::create([
            'sku' => 'RB-'.Str::uuid(), 'name' => 'Kue', 'slug' => 'kue-rb-'.uniqid(), 'has_variations' => false,
            'base_price' => 50000, 'weight_grams' => 400, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $b['agen']->id, 'product_id' => $product->id, 'quantity_on_hand' => 5, 'quantity_reserved' => 0]);

        foreach (['cod', 'down_payment'] as $code) {
            $this->assertNotNull(PaymentMethod::query()->where('code', $code)->first());
            $this->actingAs($b['konsumen'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
                'payment_method_code' => $code,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'recipient_name' => 'R', 'recipient_phone' => '0812', 'address_line' => 'Jl. R',
                'village_id' => $this->seedTestVillage(),
                'latitude' => -6.9, 'longitude' => 107.6,
            ])->assertStatus(422);
        }

        $this->assertDatabaseCount('orders', 0);
    }
}
