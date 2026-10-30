<?php

namespace Tests\Feature;

use App\Mail\OtpChallengeMail;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerLinkEvent;
use App\Models\IdentityOtpChallenge;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsCrmFixtures;
use Tests\Concerns\HasTestRegion;
use Tests\TestCase;

/** OD-03 / OD-04 / CI-01 / CI-05: CRM customer <-> konsumen account linking. */
class CrmLinkTest extends TestCase
{
    use BuildsCrmFixtures;
    use HasTestRegion;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        Mail::fake();
    }

    /** Issues the link OTP as $actor and returns [challengeId, code]. */
    private function linkOtp(User $actor, int $customer, User $konsumen): array
    {
        $id = $this->actingAs($actor)->postJson("/api/v1/crm/customers/{$customer}/link-otp", ['konsumen_id' => $konsumen->id])
            ->assertCreated()->assertJsonMissingPath('data.code')->json('data.challenge_id');

        $code = null;
        Mail::assertSent(OtpChallengeMail::class, function (OtpChallengeMail $m) use (&$code) {
            $code = $m->code;

            return true;
        });

        return [$id, $code];
    }

    private function verified(User $actor, int $customer, User $konsumen): int
    {
        [$id, $code] = $this->linkOtp($actor, $customer, $konsumen);
        $this->actingAs($actor)->postJson("/api/v1/consent/otp/{$id}/verify", ['code' => $code])->assertOk();

        return $id;
    }

    public function test_link_requires_the_emailed_otp_and_never_changes_existing_orders(): void
    {
        $b = $this->branch();
        $customer = $this->crmCustomer($b['sales']);
        $order = $this->assistedOrder($b['sales'], $customer, $this->stockedProduct($b['agen']));
        $before = $order->only(['konsumen_id', 'crm_customer_id', 'placed_by_user_id', 'sales_id', 'korsal_id', 'agent_id', 'buyer_name_snapshot', 'total_amount']);

        $challenge = $this->verified($b['sales'], $customer, $b['konsumen']);
        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$customer}/link", [
            'konsumen_id' => $b['konsumen']->id, 'otp_challenge_id' => $challenge,
        ])->assertOk()->assertJsonPath('data.linked_konsumen_id', $b['konsumen']->id);

        $this->assertSame($b['konsumen']->id, CrmCustomer::withoutGlobalScopes()->find($customer)->linked_konsumen_id);
        $this->assertNotNull(IdentityOtpChallenge::find($challenge)->consumed_at);
        $this->assertSame(1, CrmCustomerLinkEvent::withoutGlobalScopes()->where('event_type', 'linked')->count());
        $this->assertSame($before, Order::withoutGlobalScopes()->find($order->id)->only(array_keys($before)), 'linking must not rewrite any order');
    }

    public function test_link_candidates_are_only_the_sellers_own_unlinked_referrals_with_masked_contacts(): void
    {
        $b = $this->branch();
        $other = $this->branch();
        $customer = $this->crmCustomer($b['sales']);
        $second = User::factory()->konsumen()->create(['agent_id' => $b['agen']->id, 'sales_id' => $b['sales']->id, 'name' => 'Zed Kedua']);
        $unverified = User::factory()->konsumen()->unverified()->create(['agent_id' => $b['agen']->id, 'sales_id' => $b['sales']->id, 'name' => 'Belum Verifikasi']);
        User::factory()->konsumen()->create(['agent_id' => $b['agen']->id, 'sales_id' => $b['sks']->id, 'name' => 'Referral Orang Lain']);

        $rows = $this->actingAs($b['sales'])->getJson('/api/v1/crm/link-candidates')->assertOk()->json('data');
        $names = collect($rows)->pluck('name')->all();
        // A referred konsumen with NO order yet is offered (the orders report could never offer it).
        $this->assertEqualsCanonicalizing([$b['konsumen']->name, 'Zed Kedua', 'Belum Verifikasi'], $names);
        $this->assertNotContains('Referral Orang Lain', $names);

        $row = collect($rows)->firstWhere('id', $b['konsumen']->id);
        $this->assertStringNotContainsString($b['konsumen']->email, json_encode($row), 'email must be masked');
        $this->assertMatchesRegularExpression('/^.\*\*\*@/', $row['email_masked']);
        $this->assertTrue($row['email_verified']);
        $this->assertFalse(collect($rows)->firstWhere('id', $unverified->id)['email_verified']);
        $this->assertArrayNotHasKey('phone', $row);

        // Once linked, the account is no longer offered.
        $this->linkCustomer($b['sales'], $customer, $b['konsumen']);
        $this->assertNotContains($b['konsumen']->name, collect($this->actingAs($b['sales'])->getJson('/api/v1/crm/link-candidates')->json('data'))->pluck('name')->all());

        // Nobody else can use it: other seller sees only their own, read-only/other roles are refused.
        $theirs = collect($this->actingAs($other['sales'])->getJson('/api/v1/crm/link-candidates')->json('data'))->pluck('id')->all();
        $this->assertSame([$other['konsumen']->id], $theirs, 'another branch sees only its own referral, never ours');
        $this->actingAs($b['agen'])->getJson('/api/v1/crm/link-candidates')->assertForbidden();
        $this->actingAs($b['konsumen'])->getJson('/api/v1/crm/link-candidates')->assertForbidden();
        $this->assertNotNull($second->id);
    }

    public function test_matching_contact_details_alone_never_link(): void
    {
        $b = $this->branch();
        // CRM customer carries the konsumen's exact email and phone.
        $customer = $this->crmCustomer($b['sales'], ['email' => $b['konsumen']->email, 'phone' => $b['konsumen']->phone]);

        // No OTP at all.
        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$customer}/link", [
            'konsumen_id' => $b['konsumen']->id, 'otp_challenge_id' => 999999,
        ])->assertStatus(422);

        // An OTP that was issued but never verified.
        [$issued] = $this->linkOtp($b['sales'], $customer, $b['konsumen']);
        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$customer}/link", [
            'konsumen_id' => $b['konsumen']->id, 'otp_challenge_id' => $issued,
        ])->assertStatus(422);

        $this->assertNull(CrmCustomer::withoutGlobalScopes()->find($customer)->linked_konsumen_id);
    }

    public function test_cross_agent_unreferred_and_unverified_targets_are_refused_without_disclosure(): void
    {
        $b = $this->branch();
        $other = $this->branch();
        $customer = $this->crmCustomer($b['sales']);

        // Another Agent's konsumen: same answer as a non-existent id.
        $foreign = $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$customer}/link-otp", ['konsumen_id' => $other['konsumen']->id]);
        $missing = $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$customer}/link-otp", ['konsumen_id' => 99999999]);
        $foreign->assertForbidden();
        $missing->assertForbidden();
        $this->assertSame($foreign->json('message'), $missing->json('message'));

        // Same Agent but referred by another seller.
        $stranger = User::factory()->konsumen()->create(['agent_id' => $b['agen']->id, 'sales_id' => $b['sks']->id]);
        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$customer}/link-otp", ['konsumen_id' => $stranger->id])->assertForbidden();

        // Unverified email -> no OTP channel.
        $b['konsumen']->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$customer}/link-otp", ['konsumen_id' => $b['konsumen']->id])->assertStatus(422);

        // A non-owner seller cannot drive the link, nor can read-only roles.
        $b['konsumen']->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($b['sks'])->postJson("/api/v1/crm/customers/{$customer}/link-otp", ['konsumen_id' => $b['konsumen']->id])->assertForbidden();
        $this->actingAs($b['agen'])->postJson("/api/v1/crm/customers/{$customer}/link-otp", ['konsumen_id' => $b['konsumen']->id])->assertForbidden();
    }

    public function test_a_challenge_is_bound_to_its_customer_and_cannot_be_reused(): void
    {
        $b = $this->branch();
        $a = $this->crmCustomer($b['sales'], ['name' => 'A']);
        $c = $this->crmCustomer($b['sales'], ['name' => 'C']);

        $challenge = $this->verified($b['sales'], $a, $b['konsumen']);

        // Issued for customer A, presented for customer C.
        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$c}/link", [
            'konsumen_id' => $b['konsumen']->id, 'otp_challenge_id' => $challenge,
        ])->assertStatus(422);

        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$a}/link", [
            'konsumen_id' => $b['konsumen']->id, 'otp_challenge_id' => $challenge,
        ])->assertOk();

        // Consumed: a replay with the same challenge fails (customer already linked -> 409 first).
        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$a}/link", [
            'konsumen_id' => $b['konsumen']->id, 'otp_challenge_id' => $challenge,
        ])->assertStatus(409);
    }

    public function test_duplicate_links_are_prevented_both_ways(): void
    {
        $b = $this->branch();
        $a = $this->crmCustomer($b['sales'], ['name' => 'A']);
        $c = $this->crmCustomer($b['sales'], ['name' => 'C']);
        $second = User::factory()->konsumen()->create(['agent_id' => $b['agen']->id, 'sales_id' => $b['sales']->id]);

        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$a}/link", [
            'konsumen_id' => $b['konsumen']->id, 'otp_challenge_id' => $this->verified($b['sales'], $a, $b['konsumen']),
        ])->assertOk();

        // The same account cannot also back customer C.
        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$c}/link", [
            'konsumen_id' => $b['konsumen']->id, 'otp_challenge_id' => $this->verified($b['sales'], $c, $b['konsumen']),
        ])->assertStatus(409);

        // Customer A cannot take a second account.
        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$a}/link", [
            'konsumen_id' => $second->id, 'otp_challenge_id' => $this->verified($b['sales'], $a, $second),
        ])->assertStatus(409);

        $this->assertSame(1, CrmCustomerLinkEvent::withoutGlobalScopes()->count());
    }

    public function test_unlink_is_audited_authorized_append_only_and_allows_relinking_with_fresh_proof(): void
    {
        $b = $this->branch();
        $customer = $this->crmCustomer($b['sales']);
        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$customer}/link", [
            'konsumen_id' => $b['konsumen']->id, 'otp_challenge_id' => $this->verified($b['sales'], $customer, $b['konsumen']),
        ])->assertOk();

        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$customer}/unlink", [])->assertStatus(422);
        $this->actingAs($b['sks'])->postJson("/api/v1/crm/customers/{$customer}/unlink", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($b['keuangan'])->postJson("/api/v1/crm/customers/{$customer}/unlink", ['reason' => 'x'])->assertForbidden();
        $other = $this->branch();
        $this->actingAs($other['agen'])->postJson("/api/v1/crm/customers/{$customer}/unlink", ['reason' => 'x'])->assertNotFound();

        $this->actingAs($b['agen'])->postJson("/api/v1/crm/customers/{$customer}/unlink", ['reason' => 'Salah tautan'])->assertOk();
        $this->assertNull(CrmCustomer::withoutGlobalScopes()->find($customer)->linked_konsumen_id);
        $this->actingAs($b['agen'])->postJson("/api/v1/crm/customers/{$customer}/unlink", ['reason' => 'lagi'])->assertStatus(422);

        $events = CrmCustomerLinkEvent::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertSame(['linked', 'unlinked'], $events->pluck('event_type')->all());
        $this->assertSame($b['agen']->id, $events[1]->actor_id);
        $this->assertSame('Salah tautan', $events[1]->reason);

        // Re-link needs a brand-new verified OTP.
        $this->actingAs($b['sales'])->postJson("/api/v1/crm/customers/{$customer}/link", [
            'konsumen_id' => $b['konsumen']->id, 'otp_challenge_id' => $this->verified($b['sales'], $customer, $b['konsumen']),
        ])->assertOk();
        $this->assertSame(3, CrmCustomerLinkEvent::withoutGlobalScopes()->count());

        $this->expectException(\RuntimeException::class);
        $events[0]->update(['reason' => 'tamper']);
    }
}
