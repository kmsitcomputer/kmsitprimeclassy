<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Order;
use App\Models\ProductStock;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCrmFixtures;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/** G17-D1/D2/D3: CRM-origin Assisted Order — buyer origin, placer, authorization, idempotency, isolation. */
class AssistedOrderTest extends TestCase
{
    use BuildsCrmFixtures;
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_owner_places_an_order_with_exactly_one_crm_origin_and_no_minted_user(): void
    {
        $b = $this->branch();
        $customer = $this->crmCustomer($b['sales']);
        $product = $this->stockedProduct($b['agen']);
        $users = User::count();

        $order = $this->assistedOrder($b['sales'], $customer, $product);

        $this->assertNull($order->konsumen_id, 'a CRM-origin order must not carry an account buyer');
        $this->assertSame($customer, $order->crm_customer_id);
        $this->assertSame($b['sales']->id, $order->placed_by_user_id);
        $this->assertSame('Ibu Rina', $order->buyer_name_snapshot);
        $this->assertSame($b['agen']->id, $order->agent_id);
        $this->assertSame($b['sales']->id, $order->sales_id);
        $this->assertSame($b['korsal']->id, $order->korsal_id);
        $this->assertSame($users, User::count(), 'no User may be created for a CRM buyer');

        // Stock really reserved through the unchanged pipeline.
        $this->assertSame(3, (int) ProductStock::query()->where('product_id', $order->items()->first()->product_id)->value('quantity_reserved'));
    }

    public function test_commission_is_written_once_to_the_owning_seller_and_agent_only(): void
    {
        $b = $this->branch();
        $customer = $this->crmCustomer($b['sales']);
        $product = $this->stockedProduct($b['agen']);
        app(\App\Services\Fee\FeeService::class)->setForProduct($product, 5000, 3000, 0);

        // Placed by the Korsal UPLINE: the placer is not the beneficiary.
        $order = $this->assistedOrder($b['korsal'], $customer, $product);

        $this->assertSame($b['korsal']->id, $order->placed_by_user_id);
        $rows = Commission::query()->where('order_id', $order->id)->get();
        $this->assertCount(2, $rows, 'one agent + one sales commission for the single item; never doubled');
        $this->assertEqualsCanonicalizing(
            [[$b['agen']->id, 'agent'], [$b['sales']->id, 'sales']],
            $rows->map(fn ($c) => [$c->beneficiary_user_id, $c->beneficiary_role])->all(),
        );
        $this->assertSame(0, Commission::query()->where('order_id', $order->id)->where('beneficiary_user_id', $b['korsal']->id)->count());
    }

    public function test_upline_authority_follows_rbac_and_branch_scope(): void
    {
        $b = $this->branch();
        $customer = $this->crmCustomer($b['sales']);
        $product = $this->stockedProduct($b['agen']);

        // Owner's Korsal and the branch Agen may place; everyone else may not.
        $this->placeAssisted($b['korsal'], $customer, $this->assistedPayload($product))->assertCreated();
        $this->placeAssisted($b['agen'], $customer, $this->assistedPayload($product))->assertCreated();

        $otherSales = User::factory()->sales()->create(['agent_id' => $b['agen']->id]);
        $otherKorsal = User::factory()->korsal()->create(['agent_id' => $b['agen']->id]);
        foreach ([$otherSales, $otherKorsal, $b['sks']] as $stranger) {
            $this->placeAssisted($stranger, $customer, $this->assistedPayload($product))->assertForbidden();
        }
        foreach (['admin', 'keuangan'] as $role) {
            $this->placeAssisted($b[$role], $customer, $this->assistedPayload($product))->assertForbidden();
        }

        $konsumen = $b['konsumen'];
        $this->placeAssisted($konsumen, $customer, $this->assistedPayload($product))->assertForbidden();

        // Cross-branch: the customer simply does not exist for another Agent (global scope) -> 404.
        $other = $this->branch();
        $this->placeAssisted($other['agen'], $customer, $this->assistedPayload($this->stockedProduct($other['agen'])))->assertNotFound();
        $this->placeAssisted($other['sales'], $customer, $this->assistedPayload($this->stockedProduct($other['agen'])))->assertNotFound();
    }

    public function test_body_cannot_inject_a_konsumen_identity_or_a_stock_source(): void
    {
        $b = $this->branch();
        $customer = $this->crmCustomer($b['sales']);
        $product = $this->stockedProduct($b['agen']);

        // An address-book id is refused outright (a CRM customer has none).
        $this->placeAssisted($b['sales'], $customer, $this->assistedPayload($product, ['address_id' => 1]))->assertStatus(422);

        // konsumen_id / stock_source / sub_location_id are not part of this endpoint's contract and are ignored.
        $order = Order::withoutGlobalScopes()->findOrFail(
            $this->placeAssisted($b['sales'], $customer, $this->assistedPayload($product, [
                'konsumen_id' => $b['konsumen']->id, 'stock_source' => 'sub', 'sub_location_id' => 1,
            ]))->assertCreated()->json('data.id'),
        );

        $this->assertNull($order->konsumen_id);
        $this->assertSame('agent', $order->items()->first()->stock_source);
    }

    public function test_idempotency_is_origin_scoped(): void
    {
        $b = $this->branch();
        $a = $this->crmCustomer($b['sales'], ['name' => 'A']);
        $c = $this->crmCustomer($b['sales'], ['name' => 'C']);
        $product = $this->stockedProduct($b['agen']);
        $key = (string) Str::uuid();

        $first = $this->placeAssisted($b['sales'], $a, $this->assistedPayload($product), $key)->assertCreated()->json('data.id');
        $again = $this->placeAssisted($b['sales'], $a, $this->assistedPayload($product), $key)->assertCreated()->json('data.id');
        $this->assertSame($first, $again, 'same CRM customer + key replays the same order');
        $this->assertSame(1, Order::withoutGlobalScopes()->where('crm_customer_id', $a)->count());
        $this->assertSame(3, (int) ProductStock::query()->where('product_id', $product->id)->value('quantity_reserved'), 'replay must not reserve twice');

        // Same key for ANOTHER CRM customer is a different key space.
        $other = $this->placeAssisted($b['sales'], $c, $this->assistedPayload($product), $key)->assertCreated()->json('data.id');
        $this->assertNotSame($first, $other);

        // ...and never collides with an account buyer's order using the same key.
        $accountOrder = $this->actingAs($b['agen'])->withHeaders(['Idempotency-Key' => $key])->postJson('/api/v1/orders', [
            'konsumen_id' => $b['konsumen']->id,
            'payment_method_code' => 'cod', 'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. X',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ])->assertCreated()->json('data.id');
        $this->assertNotContains($accountOrder, [$first, $other]);

        // The key is mandatory (withHeaders persists inside one test, so drop it explicitly).
        $this->withoutHeader('Idempotency-Key')->actingAs($b['sales'])
            ->postJson("/api/v1/crm/customers/{$a}/orders", $this->assistedPayload($product))->assertStatus(422);
    }

    public function test_archived_customer_and_missing_destination_are_refused(): void
    {
        $b = $this->branch();
        $customer = $this->crmCustomer($b['sales']);
        $product = $this->stockedProduct($b['agen']);

        $payload = $this->assistedPayload($product);
        unset($payload['address_line']);
        $this->placeAssisted($b['sales'], $customer, $payload)->assertStatus(422);

        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$customer}/archive")->assertOk();
        $this->placeAssisted($b['sales'], $customer, $this->assistedPayload($product))->assertStatus(422);
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    public function test_account_origin_checkout_is_unchanged_and_now_records_its_placer(): void
    {
        $b = $this->branch();
        $product = $this->stockedProduct($b['agen']);

        $id = $this->actingAs($b['sales'])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', [
            'konsumen_id' => $b['konsumen']->id, 'payment_method_code' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. X',
            'village_id' => $this->seedTestVillage(), 'latitude' => -6.9, 'longitude' => 107.6,
        ])->assertCreated()->assertJsonPath('data.buyer_origin', 'account')->json('data.id');

        $order = Order::withoutGlobalScopes()->findOrFail($id);
        $this->assertSame($b['konsumen']->id, $order->konsumen_id);
        $this->assertNull($order->crm_customer_id);
        $this->assertSame($b['sales']->id, $order->placed_by_user_id, 'D2: the actual submitter, not the owner');
        $this->assertNull($order->buyer_name_snapshot);
    }

    public function test_visibility_and_pay_on_behalf_for_crm_orders(): void
    {
        $b = $this->branch();
        $customer = $this->crmCustomer($b['sales']);
        $order = $this->assistedOrder($b['sales'], $customer, $this->stockedProduct($b['agen']));

        $show = fn (User $u) => $this->actingAs($u)->getJson("/api/v1/orders/{$order->id}");
        $show($b['sales'])->assertOk()->assertJsonPath('data.buyer_origin', 'crm')->assertJsonPath('data.crm_customer.name', 'Ibu Rina');
        $show($b['agen'])->assertOk();
        $show($b['korsal'])->assertOk();

        $otherSales = User::factory()->sales()->create(['agent_id' => $b['agen']->id]);
        $show($otherSales)->assertForbidden();
        $show($b['konsumen'])->assertForbidden();

        // Only the owning seller may submit a payment proof on a CRM order; verification stays finance-only.
        $fresh = Order::withoutGlobalScopes()->findOrFail($order->id);
        $this->assertTrue(Gate::forUser($b['sales'])->allows('payOnBehalf', $fresh));
        $this->assertFalse(Gate::forUser($otherSales)->allows('payOnBehalf', $fresh));
        $this->assertFalse(Gate::forUser($b['korsal'])->allows('payOnBehalf', $fresh));
        $this->assertFalse(Gate::forUser($b['konsumen'])->allows('payOnBehalf', $fresh));
    }

    public function test_buyer_origin_placer_and_snapshot_are_write_once_and_exclusive(): void
    {
        $b = $this->branch();
        $customerId = $this->crmCustomer($b['sales']);
        $other = $this->crmCustomer($b['sales'], ['name' => 'Other']);
        $order = $this->assistedOrder($b['sales'], $customerId, $this->stockedProduct($b['agen']));

        foreach ([['crm_customer_id', $other], ['placed_by_user_id', $b['agen']->id], ['buyer_name_snapshot', 'Takeover']] as [$field, $value]) {
            try {
                $order->update([$field => $value]);
                $this->fail("{$field} must be write-once");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('write-once', $e->getMessage());
            }
            $order->refresh();
        }

        // Taking over the order with an account identity is refused: exactly one origin.
        try {
            $order->update(['konsumen_id' => $b['konsumen']->id]);
            $this->fail('a CRM-origin order must not also get an account buyer');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('exactly one buyer origin', $e->getMessage());
        }

        $fresh = Order::withoutGlobalScopes()->findOrFail($order->id);
        $this->assertNull($fresh->konsumen_id);
        $this->assertSame($customerId, $fresh->crm_customer_id);
        $this->assertSame($b['sales']->id, $fresh->placed_by_user_id);
    }

    public function test_service_refuses_a_persisted_konsumen_for_a_crm_order_and_vice_versa(): void
    {
        $b = $this->branch();
        $product = $this->stockedProduct($b['agen']);
        $customer = \App\Models\CrmCustomer::withoutGlobalScopes()->findOrFail($this->crmCustomer($b['sales']));
        $service = app(\App\Services\Order\OrderService::class);
        $lines = [['product_id' => $product->id, 'product_variation_id' => null, 'quantity' => 1]];

        $this->expectException(\LogicException::class);
        $service->createOrder($b['konsumen'], $lines, [], $b['sales'], 'cod', null, 'k1', null, null, null, null, null, null, $customer);
    }
}
