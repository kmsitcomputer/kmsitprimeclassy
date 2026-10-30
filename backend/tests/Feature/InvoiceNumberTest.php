<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Services\Order\InvoiceNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Workstream 1 — D-MMYY##### invoice numbers (Owner decision 2026-10-29).
 * Allocation is protected by a settings-row lock inside the order-creation
 * transaction; no MAX() over orders is ever used. These tests exercise the
 * allocator directly against the real DB (the checkout path injects it).
 */
class InvoiceNumberTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    private function allocator(): InvoiceNumberService
    {
        return app(InvoiceNumberService::class);
    }

    private function setSequence(int $n): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => InvoiceNumberService::SETTING_KEY],
            ['value' => (string) $n, 'group' => 'orders', 'is_public' => false, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    private function legacyOrder(string $no, ?string $createdAt = null): void
    {
        $agent = \App\Models\User::factory()->agen()->create();
        $agent->update(['agent_id' => $agent->id]);
        DB::table('orders')->insert([
            'order_no' => $no, 'agent_id' => $agent->id, 'konsumen_id' => null,
            'status' => 'diterima', 'payment_status' => 'unpaid',
            'subtotal_amount' => 1000, 'total_amount' => 1000, 'paid_amount' => 0, 'remaining_amount' => 0,
            'recipient_name_snapshot' => 'X', 'recipient_phone_snapshot' => '0', 'address_snapshot' => 'y',
            'created_at' => $createdAt ?? '2026-10-20 08:00:00', 'updated_at' => $createdAt ?? '2026-10-20 08:00:00',
        ]);
    }

    public function test_first_number_sequence_and_continuity_across_months_and_years(): void
    {
        Carbon::setTestNow('2026-10-20 09:00:00');
        $s = $this->allocator();
        $this->assertSame('D-102600001', $s->next());
        $this->assertSame('D-102600002', $s->next());

        Carbon::setTestNow('2026-11-05 09:00:00');
        $this->assertSame('D-112600003', $s->next());   // sequence does NOT reset per month

        Carbon::setTestNow('2027-01-02 09:00:00');
        $this->assertSame('D-012700004', $s->next());   // nor per year
        Carbon::setTestNow();
    }

    public function test_rollover_after_99999_to_00001_when_free(): void
    {
        Carbon::setTestNow('2027-02-01 10:00:00');
        $this->setSequence(InvoiceNumberService::MAX_SEQUENCE);
        $this->assertSame('D-022700001', $this->allocator()->next());
        // Persisted sequence continues from the rolled-over value.
        $this->assertSame('D-022700002', $this->allocator()->next());
        Carbon::setTestNow();
    }

    public function test_rollover_collision_fails_safely_instead_of_reusing_a_number(): void
    {
        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->legacyOrder('D-102600001');       // that exact number already exists this MMYY
        $this->setSequence(InvoiceNumberService::MAX_SEQUENCE);

        try {
            $this->allocator()->next();
            $this->fail('Expected a capacity guard.');
        } catch (ApiException $e) {
            $this->assertStringContainsString('Rentang nomor invoice', $e->getMessage());
        }
        // It must NOT have burned/rewritten the sequence or the existing number.
        $this->assertSame('D-102600001', DB::table('orders')->where('order_no', 'D-102600001')->value('order_no'));
        $this->assertSame('99999', DB::table('settings')->where('key', InvoiceNumberService::SETTING_KEY)->value('value'));
        Carbon::setTestNow();
    }

    public function test_repeated_allocation_is_unique_and_historical_numbers_are_preserved(): void
    {
        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->legacyOrder('PC-261020-000042');  // historic format must survive untouched
        $numbers = [];
        for ($i = 0; $i < 5; $i++) {
            $numbers[] = $this->allocator()->next();
        }
        $this->assertCount(5, array_unique($numbers));
        $this->assertSame('PC-261020-000042', DB::table('orders')->where('order_no', 'PC-261020-000042')->value('order_no'));
        $this->assertSame(['D-102600001', 'D-102600002', 'D-102600003', 'D-102600004', 'D-102600005'], $numbers);
        Carbon::setTestNow();
    }
}