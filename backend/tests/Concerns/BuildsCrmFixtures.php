<?php

namespace Tests\Concerns;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Shared fixtures for the G17 suites (assisted order / linking / CRM consent / refund recipient).
 * Requires HasTestRegion + RoleSeeder + PaymentMethodSeeder in the using test.
 */
trait BuildsCrmFixtures
{
    /** One Agent branch: agen, admin, keuangan, korsal, sales (under the korsal), sks and a referred konsumen. */
    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko QA', 'address' => 'Jl. QA',
            'latitude' => -6.2, 'longitude' => 106.8166,
        ]);
        $admin = User::factory()->admin()->create(['agent_id' => $agen->id]);
        $keuangan = User::factory()->keuangan()->create(['agent_id' => $agen->id]);
        $korsal = User::factory()->korsal()->create(['agent_id' => $agen->id]);
        $sales = User::factory()->sales()->create(['agent_id' => $agen->id, 'korsal_id' => $korsal->id]);
        $sks = User::factory()->salesKurirSub()->create(['agent_id' => $agen->id]);
        $konsumen = User::factory()->konsumen()->create(['agent_id' => $agen->id, 'sales_id' => $sales->id]);

        return compact('agen', 'admin', 'keuangan', 'korsal', 'sales', 'sks', 'konsumen');
    }

    private function crmCustomer(User $owner, array $overrides = []): int
    {
        return $this->actingAs($owner)->postJson('/api/v1/crm/customers', $overrides + [
            'name' => 'Ibu Rina', 'phone' => '08'.random_int(1000000000, 1999999999), 'email' => 'rina'.uniqid().'@example.com',
        ])->assertCreated()->json('data.id');
    }

    /**
     * Links a CRM customer to a konsumen account through the real OTP flow (needs Mail::fake() in the test).
     * Returns nothing; asserts each step succeeded.
     */
    private function linkCustomer(User $owner, int $customerId, User $konsumen): void
    {
        $challenge = $this->actingAs($owner)->postJson("/api/v1/crm/customers/{$customerId}/link-otp", ['konsumen_id' => $konsumen->id])
            ->assertCreated()->json('data.challenge_id');
        $this->actingAs($owner)->postJson("/api/v1/consent/otp/{$challenge}/verify", ['code' => $this->lastOtpCode()])->assertOk();
        $this->actingAs($owner)->postJson("/api/v1/crm/customers/{$customerId}/link", [
            'konsumen_id' => $konsumen->id, 'otp_challenge_id' => $challenge,
        ])->assertOk();
    }

    /** The plain code of the most recent OTP mail (Mail::fake() must be active). */
    private function lastOtpCode(): string
    {
        $code = null;
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\OtpChallengeMail::class, function (\App\Mail\OtpChallengeMail $m) use (&$code) {
            $code = $m->code;

            return true;
        });

        return (string) $code;
    }

    /** Issues + verifies a CRM consent OTP; returns the verified challenge id. */
    private function verifiedConsentOtp(User $owner, int $customerId): int
    {
        $challenge = $this->actingAs($owner)->postJson("/api/v1/crm/customers/{$customerId}/consent-otp", ['purpose' => 'assisted_return'])
            ->assertCreated()->assertJsonMissingPath('data.code')->json('data.challenge_id');
        $this->actingAs($owner)->postJson("/api/v1/consent/otp/{$challenge}/verify", ['code' => $this->lastOtpCode()])->assertOk();

        return $challenge;
    }

    private function stockedProduct(User $agen, int $stock = 20, int $price = 100000): Product
    {
        $product = Product::create(['sku' => 'TEST-'.Str::uuid(),
            'name' => 'Assisted Cake', 'slug' => 'assisted-cake-'.uniqid(),
            'has_variations' => false, 'base_price' => $price, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => $stock, 'quantity_reserved' => 0]);

        return $product;
    }

    private function assistedPayload(Product $product, array $overrides = []): array
    {
        return $overrides + [
            'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'recipient_name' => 'Ibu Rina', 'recipient_phone' => '0811000111', 'address_line' => 'Jl. Sudirman 1',
            'village_id' => $this->seedTestVillage(),
            'latitude' => -6.914744, 'longitude' => 107.609810,
        ];
    }

    private function placeAssisted(User $actor, int $customerId, array $payload, ?string $key = null)
    {
        return $this->actingAs($actor)->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()])
            ->postJson("/api/v1/crm/customers/{$customerId}/orders", $payload);
    }

    private function assistedOrder(User $actor, int $customerId, Product $product): Order
    {
        $id = $this->placeAssisted($actor, $customerId, $this->assistedPayload($product))->assertCreated()->json('data.id');

        return Order::withoutGlobalScopes()->findOrFail($id);
    }
}
