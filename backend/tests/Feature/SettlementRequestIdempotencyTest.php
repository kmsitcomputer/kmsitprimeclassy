<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AgentPaymentGatewayConfig;
use App\Models\AgentProfile;
use App\Models\BankTransferVerification;
use App\Models\Order;
use App\Models\OrderAdditionalPayment;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ShippingConfiguration;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\HasTestRegion;
use Tests\Support\ConcurrencyHarness;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

/**
 * IDEMPOTENT SETTLEMENT REQUEST (Human decision).
 *
 * A DP settlement request asks for a specific outstanding amount. Two equivalent requests for
 * the same canonical financial context must converge on ONE canonical pending settlement:
 * no second transaction, no second financial side effect, no duplicated ledger movement — the
 * existing pending settlement is returned with HTTP 200.
 *
 * It must also be CONCURRENCY-SAFE (two simultaneous requests) and it must never silently
 * replace a pending settlement whose nominal no longer matches what the order owes: that request
 * is refused by the existing duplicate-obligation rule while the pending one is preserved.
 * Money movement remains owned exclusively by PaymentService::verifyBankTransfer.
 *
 * Committed fixtures + rebuild between tests (RestoresIsolatedTestDatabase), exactly like every other
 * two-connection suite: the spawned actor processes must SEE the order through their OWN MySQL
 * connections, which is impossible while the test process keeps its rows inside an uncommitted
 * RefreshDatabase transaction.
 */
class SettlementRequestIdempotencyTest extends TestCase
{
    use HasTestRegion;
    use RestoresIsolatedTestDatabase;

    /** @var array{agen:User, admin:User, keuangan:User, konsumen:User, product:Product} */
    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        Storage::fake('public');
        $this->b = $this->branch();
    }

    private function branch(): array
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        AgentProfile::create([
            'user_id' => $agen->id, 'store_name' => 'Toko Setel', 'address' => 'Jl. Setel',
            'latitude' => -6.2, 'longitude' => 106.8166,
        ]);
        $bank = PaymentMethod::query()->where('code', 'bank_transfer')->firstOrFail();
        AgentPaymentGatewayConfig::create([
            'agent_id' => $agen->id, 'payment_method_id' => $bank->id, 'environment' => 'sandbox',
            'config' => ['bank_name' => 'BCA', 'account_name' => 'PT Prime', 'account_number' => '123456'],
        ]);
        ShippingConfiguration::create([
            'agent_id' => $agen->id, 'price_per_km' => 2000, 'minimum_distance_km' => 0,
            'minimum_charge' => 5000, 'free_shipping_enabled' => false, 'is_active' => true,
        ]);
        $product = Product::create([
            'sku' => 'STL-'.Str::uuid(), 'name' => 'Produk Setel', 'slug' => 'produk-setel-'.uniqid(),
            'has_variations' => false, 'base_price' => 100000, 'weight_grams' => 500, 'status' => 'active',
        ]);
        ProductStock::create(['agent_id' => $agen->id, 'product_id' => $product->id, 'quantity_on_hand' => 50, 'quantity_reserved' => 0]);

        return [
            'agen' => $agen,
            'admin' => User::factory()->admin()->create(['agent_id' => $agen->id]),
            'keuangan' => User::factory()->keuangan()->create(['agent_id' => $agen->id]),
            'konsumen' => User::factory()->konsumen()->create(['agent_id' => $agen->id, 'parent_id' => $agen->id]),
            'product' => $product,
        ];
    }

    /** A DP order whose DP has been verified, so a real outstanding balance remains. */
    private function dpOrderWithOutstandingBalance(float $dp = 40000): Order
    {
        $this->actingAs($this->b['konsumen'])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'down_payment', 'dp_amount' => $dp,
                'items' => [['product_id' => $this->b['product']->id, 'quantity' => 1]],
                'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810,
            ])->assertCreated();

        $order = Order::withoutGlobalScopes()->latest('id')->firstOrFail();

        // Verify the DP so the order is `partially_paid` with a genuine remaining balance.
        $this->actingAs($this->b['konsumen'])
            ->postJson("/api/v1/orders/{$order->id}/payment/proof", ['proof' => UploadedFile::fake()->image('dp.jpg')])
            ->assertOk();
        $this->actingAs($this->b['keuangan'])
            ->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('partially_paid', $fresh->payment_status);
        $this->assertGreaterThan(0, (float) $fresh->remaining_amount);

        return $fresh;
    }

    private function settle(User $actor, Order $order)
    {
        return $this->actingAs($actor)->postJson("/api/v1/orders/{$order->id}/payment/settle");
    }

    /** @return \Illuminate\Support\Collection<int, PaymentTransaction> */
    private function settlementTransactions(Order $order)
    {
        $bankId = PaymentMethod::query()->where('code', 'bank_transfer')->value('id');

        return PaymentTransaction::query()
            ->where('order_id', $order->id)
            ->where('payment_method_id', $bankId)
            ->where('type', 'payment')
            ->where('raw_payload->note', 'dp_settlement')
            ->orderBy('id')
            ->get();
    }

    // ---------- A. The first request creates exactly one pending settlement ----------

    public function test_first_request_creates_exactly_one_pending_settlement(): void
    {
        $order = $this->dpOrderWithOutstandingBalance();

        $response = $this->settle($this->b['keuangan'], $order)->assertOk();
        $this->assertFalse($response->json('data.replay'), 'the first request is a creation, not a replay');

        $settlements = $this->settlementTransactions($order);
        $this->assertCount(1, $settlements);
        $this->assertSame('pending', $settlements->first()->status);
        $this->assertSame(round((float) $order->remaining_amount, 2), round((float) $settlements->first()->amount, 2));
        $this->assertSame(
            $settlements->first()->id,
            (int) $response->json('data.transaction.id'),
            'the response carries the canonical pending settlement',
        );
    }

    // ---------- B. An identical sequential retry returns the SAME canonical settlement ----------

    public function test_identical_retry_returns_the_same_canonical_pending_settlement(): void
    {
        $order = $this->dpOrderWithOutstandingBalance();

        $first = $this->settle($this->b['keuangan'], $order)->assertOk();
        $second = $this->settle($this->b['keuangan'], $order)->assertOk();
        $third = $this->settle($this->b['keuangan'], $order)->assertOk();

        $this->assertFalse($first->json('data.replay'));
        $this->assertTrue($second->json('data.replay'), 'an identical retry is a replay');
        $this->assertTrue($third->json('data.replay'));

        $this->assertSame($first->json('data.transaction.id'), $second->json('data.transaction.id'));
        $this->assertSame($first->json('data.transaction.id'), $third->json('data.transaction.id'));
        $this->assertSame(1, $this->settlementTransactions($order)->count(), 'retries never mint a second settlement');
    }

    // ---------- C. A retry duplicates no money / no ledger effect ----------

    public function test_retry_creates_no_financial_side_effect_and_the_ledger_stays_exact(): void
    {
        $order = $this->dpOrderWithOutstandingBalance();

        $before = $order->fresh()->only(['total_amount', 'paid_amount', 'remaining_amount', 'payment_status']);
        $transactionsBefore = PaymentTransaction::query()->where('order_id', $order->id)->count();
        $verificationsBefore = BankTransferVerification::query()->count();
        $additionalBefore = OrderAdditionalPayment::query()->where('order_id', $order->id)->count();
        $commissionsBefore = \App\Models\Commission::query()->where('order_id', $order->id)->count();

        $this->settle($this->b['keuangan'], $order)->assertOk();
        $this->settle($this->b['keuangan'], $order)->assertOk();
        $this->settle($this->b['keuangan'], $order)->assertOk();

        // No new transaction, no verification, no additional payment, no commission, and the
        // order's own money columns are untouched (a request never moves money).
        $this->assertSame($transactionsBefore + 1, PaymentTransaction::query()->where('order_id', $order->id)->count());
        $this->assertSame($verificationsBefore, BankTransferVerification::query()->count());
        $this->assertSame($additionalBefore, OrderAdditionalPayment::query()->where('order_id', $order->id)->count());
        $this->assertSame($commissionsBefore, \App\Models\Commission::query()->where('order_id', $order->id)->count());
        $this->assertSame($before, $order->fresh()->only(['total_amount', 'paid_amount', 'remaining_amount', 'payment_status']));

        // Only ONE creation event was logged; the retries are logged as replays, not as new
        // settlement requests.
        $this->assertSame(1, ActivityLog::query()
            ->where('subject_type', Order::class)->where('subject_id', $order->id)
            ->where('event', 'payment.settlement_requested')->count());
        $this->assertSame(2, ActivityLog::query()
            ->where('subject_type', Order::class)->where('subject_id', $order->id)
            ->where('event', 'payment.settlement_request_replayed')->count());

        // The single settlement still collects the outstanding balance EXACTLY once.
        $this->actingAs($this->b['konsumen'])
            ->postJson("/api/v1/orders/{$order->id}/payment/proof", ['proof' => UploadedFile::fake()->image('settle.jpg')])
            ->assertOk();
        $this->actingAs($this->b['keuangan'])
            ->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertOk();

        $settled = $order->fresh();
        $this->assertSame('paid', $settled->payment_status);
        $this->assertSame(0.0, (float) $settled->remaining_amount);
        $this->assertSame(100000.0, (float) $settled->paid_amount, 'the balance is collected exactly once, not three times');
        $this->assertSame(1, $this->settlementTransactions($order)
            ->where('status', 'paid')->count(), 'exactly one settlement collected money');
        $this->assertSame(2, PaymentTransaction::query()->where('order_id', $order->id)
            ->where('status', 'paid')->where('type', 'payment')->count(), 'the DP tranche plus the one settlement, nothing more');
    }

    // ---------- D. Two CONCURRENT identical requests converge on ONE canonical settlement ----------

    public function test_two_concurrent_identical_requests_converge_on_one_canonical_settlement(): void
    {
        $order = $this->dpOrderWithOutstandingBalance();

        $report = (new ConcurrencyHarness)->runDispatchRace(
            ['op' => 'settle', 'actor_id' => $this->b['keuangan']->id, 'order_id' => $order->id],
            ['op' => 'settle', 'actor_id' => $this->b['keuangan']->id, 'order_id' => $order->id],
        );

        $this->assertTrue($report['different_connections'], 'the race really used two MySQL connections');
        $this->assertTrue($report['true_overlap'], 'the race really overlapped in time');
        $this->assertSame('success', $report['a']['outcome'], json_encode($report));
        $this->assertSame('success', $report['b']['outcome'], json_encode($report));
        foreach (['a', 'b'] as $side) {
            $this->assertNotContains((int) ($report[$side]['error_code'] ?? 0), [1213, 1205], 'no deadlock/lock timeout: '.json_encode($report));
        }

        // Exactly one settlement exists and BOTH sides converged onto it.
        $settlements = $this->settlementTransactions($order);
        $this->assertCount(1, $settlements, 'two simultaneous requests produced one canonical settlement');
        $this->assertSame($settlements->first()->id, (int) $report['a']['transaction_id']);
        $this->assertSame($settlements->first()->id, (int) $report['b']['transaction_id']);
        $this->assertSame(
            [(int) $report['a']['was_replay'], (int) $report['b']['was_replay']],
            [0, 1],
            'exactly one side created it and exactly one side replayed it',
        );
        $this->assertSame('pending', $settlements->first()->status);
        $this->assertSame($order->fresh()->only(['paid_amount', 'remaining_amount', 'payment_status']),
            $order->fresh()->only(['paid_amount', 'remaining_amount', 'payment_status']));
    }

    // ---------- E. A materially incompatible pending request is refused, not replaced ----------

    public function test_incompatible_pending_settlement_is_refused_and_preserved(): void
    {
        $order = $this->dpOrderWithOutstandingBalance();

        $created = $this->settle($this->b['keuangan'], $order)->assertOk();
        $createdId = (int) $created->json('data.transaction.id');
        $createdAmount = (float) $this->settlementTransactions($order)->first()->amount;

        // The order's outstanding balance MOVES (a fulfillment increase owes more). The pending
        // settlement no longer represents what is owed. The order must be inside the fulfillment
        // window for an increase to be legal at all.
        $this->actingAs($this->b['admin'])
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'diproses'])->assertOk();
        $item = \App\Models\OrderItem::query()->where('order_id', $order->id)->firstOrFail();
        $this->actingAs($this->b['admin'])
            ->patchJson("/api/v1/orders/{$order->id}/items/{$item->id}/fulfillment", [
                'fulfilled_quantity' => 2, 'reason' => 'pelanggan menambah pesanan',
            ])->assertOk();

        $newRemaining = round((float) $order->fresh()->remaining_amount, 2);
        $this->assertGreaterThan($createdAmount, $newRemaining, 'precondition: the balance really moved');

        $this->settle($this->b['keuangan'], $order)
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.payment.already_processed'));

        // The pending settlement is preserved byte-for-byte — never silently replaced.
        $settlements = $this->settlementTransactions($order);
        $this->assertCount(1, $settlements);
        $this->assertSame($createdId, $settlements->first()->id);
        $this->assertSame('pending', $settlements->first()->status);
        $this->assertSame($createdAmount, round((float) $settlements->first()->amount, 2));

        // The refusal is auditable, with the concrete reason.
        $this->assertSame(1, ActivityLog::query()
            ->where('subject_type', Order::class)->where('subject_id', $order->id)
            ->where('event', 'payment.settlement_request_rejected')->count());

        // Resolving it needs NO new workflow: the existing reject action on the stale settlement's
        // own proof frees the record, and a fresh request for the new balance then succeeds.
        $this->actingAs($this->b['konsumen'])
            ->postJson("/api/v1/orders/{$order->id}/payment/proof", ['proof' => UploadedFile::fake()->image('stale.jpg')])
            ->assertOk();
        $this->actingAs($this->b['keuangan'])
            ->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => false, 'rejection_reason' => 'nominal lama tidak sesuai'])
            ->assertOk();

        $this->settle($this->b['keuangan'], $order)->assertOk()->assertJsonPath('data.replay', false);
        $this->assertCount(2, $this->settlementTransactions($order));
        $this->assertSame(
            round((float) $order->fresh()->remaining_amount, 2),
            round((float) $this->settlementTransactions($order)->last()->amount, 2),
            'the new request carries the CURRENT outstanding balance',
        );
    }

    public function test_nothing_to_settle_rule_is_preserved_verbatim(): void
    {
        $order = $this->dpOrderWithOutstandingBalance();
        $this->settle($this->b['keuangan'], $order)->assertOk();

        // Settle it, then settle again: there is no outstanding balance any more, so the EXISTING
        // rejection rule answers (unchanged message and status), never a replay.
        $this->actingAs($this->b['konsumen'])
            ->postJson("/api/v1/orders/{$order->id}/payment/proof", ['proof' => UploadedFile::fake()->image('s.jpg')])
            ->assertOk();
        $this->actingAs($this->b['keuangan'])
            ->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => true])->assertOk();
        $this->assertSame(0.0, (float) $order->fresh()->remaining_amount);

        $this->settle($this->b['keuangan'], $order)
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.payment.nothing_to_settle'));
        $this->assertCount(1, $this->settlementTransactions($order), 'no extra settlement was created');
    }

    public function test_a_rejected_settlement_does_not_poison_the_next_request(): void
    {
        $order = $this->dpOrderWithOutstandingBalance();

        $this->settle($this->b['keuangan'], $order)->assertOk();
        $this->actingAs($this->b['konsumen'])
            ->postJson("/api/v1/orders/{$order->id}/payment/proof", ['proof' => UploadedFile::fake()->image('s.jpg')])
            ->assertOk();
        $this->actingAs($this->b['keuangan'])
            ->postJson("/api/v1/orders/{$order->id}/payment/verify", ['approved' => false, 'rejection_reason' => 'bukti tidak terbaca'])
            ->assertOk();

        // No pending settlement remains, so the retry is a fresh creation (not a replay) and the
        // outstanding balance is still owed exactly once.
        $retry = $this->settle($this->b['keuangan'], $order)->assertOk();
        $this->assertFalse($retry->json('data.replay'));
        $this->assertCount(2, $this->settlementTransactions($order));
        $this->assertSame(
            round((float) $order->fresh()->remaining_amount, 2),
            round((float) $retry->json('data.transaction.amount'), 2),
        );
    }

    // ---------- F. Authorization is unchanged ----------

    public function test_authorization_is_unchanged_for_the_settlement_endpoint(): void
    {
        $order = $this->dpOrderWithOutstandingBalance();
        $before = PaymentTransaction::query()->where('order_id', $order->id)->count();

        // Consumers, admin, gudang, kurir and koordinator cannot request a settlement.
        foreach (['konsumen', 'admin'] as $role) {
            $this->settle($this->b[$role], $order)->assertForbidden();
        }
        foreach ([
            'kurir' => User::factory()->kurir()->create(['agent_id' => $this->b['agen']->id]),
            'gudang' => User::factory()->gudang()->create(['agent_id' => $this->b['agen']->id]),
            'koordinator' => User::factory()->koordinatorKurir()->create(['agent_id' => $this->b['agen']->id, 'parent_id' => $this->b['agen']->id]),
        ] as $actor) {
            $this->settle($actor, $order)->assertForbidden();
        }

        // A Keuangan of ANOTHER branch is denied too.
        $otherAgen = User::factory()->agen()->create();
        $otherAgen->update(['agent_id' => $otherAgen->id]);
        // Out-of-scope record: the API contract answers 404 for another branch's order (never 403,
        // which would confirm the order exists).
        $foreign = User::factory()->keuangan()->create(['agent_id' => $otherAgen->id]);
        $this->settle($foreign, $order)->assertNotFound();

        $this->assertSame($before, PaymentTransaction::query()->where('order_id', $order->id)->count(), 'no denial created anything');

        // The branch's own Keuangan is authorized, and still only once.
        $this->settle($this->b['keuangan'], $order)->assertOk();
        $this->settle($this->b['keuangan'], $order)->assertOk();
        $this->assertCount(1, $this->settlementTransactions($order));
    }

    public function test_settlement_is_still_refused_for_a_non_dp_order(): void
    {
        $this->actingAs($this->b['konsumen'])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/orders', [
                'payment_method_code' => 'cod',
                'items' => [['product_id' => $this->b['product']->id, 'quantity' => 1]],
                'recipient_name' => 'Budi', 'recipient_phone' => '0811', 'address_line' => 'Jl. Sudirman',
                'village_id' => $this->seedTestVillage(), 'latitude' => -6.914744, 'longitude' => 107.609810,
            ])->assertCreated();
        $cod = Order::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->settle($this->b['keuangan'], $cod)
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.payment.not_dp_order'));
    }

    public function test_a_direct_service_call_gets_the_same_idempotency(): void
    {
        // The service is the boundary, not the controller.
        $order = $this->dpOrderWithOutstandingBalance();
        $service = app(\App\Services\Payment\PaymentService::class);

        [$first, $firstReplay] = $service->requestSettlement($order, $this->b['keuangan']);
        [$second, $secondReplay] = $service->requestSettlement($order, $this->b['keuangan']);

        $this->assertFalse($firstReplay);
        $this->assertTrue($secondReplay);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $this->settlementTransactions($order)->count());
    }
}