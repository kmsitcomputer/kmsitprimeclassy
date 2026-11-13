<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Hierarchy\ValidAgentResolver;
use App\Support\PayloadFingerprint;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Group 1 foundations: valid-Agent resolution (fail-closed) and payload fingerprint. */
class ValidAgentResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function agent(): User
    {
        $a = User::factory()->agen()->create();
        $a->update(['agent_id' => $a->id]);

        return $a;
    }

    private function resolve(User $actor, ?int $explicit = null): User
    {
        return app(ValidAgentResolver::class)->forActor($actor, $explicit);
    }

    public function test_each_role_resolves_only_its_own_scope(): void
    {
        $a = $this->agent();
        $other = $this->agent();
        $admin = User::factory()->admin()->create(['agent_id' => $a->id]);
        $sa = User::factory()->superAdmin()->create();

        $this->assertSame($a->id, $this->resolve($a)->id);
        $this->assertSame($a->id, $this->resolve($admin)->id);
        $this->assertSame($a->id, $this->resolve($sa, $a->id)->id);

        foreach ([[$a, $other->id], [$admin, $other->id]] as [$actor, $explicit]) {
            try {
                $this->resolve($actor, $explicit);
                $this->fail('cross-Agent must be refused');
            } catch (ApiException $e) {
                $this->assertSame(403, $e->status());
            }
        }
        try {
            $this->resolve($sa); // no implicit default for Super Admin
            $this->fail('explicit Agent required');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status());
        }
        $sales = User::factory()->sales()->create(['agent_id' => $a->id]);
        $this->expectException(ApiException::class);
        $this->resolve($sales);
    }

    public function test_deleted_inactive_or_non_agent_targets_fail_closed_for_admin_and_super_admin(): void
    {
        $a = $this->agent();
        $admin = User::factory()->admin()->create(['agent_id' => $a->id]);
        $sa = User::factory()->superAdmin()->create();
        $notAgent = User::factory()->korsal()->create(['agent_id' => $a->id]);
        $resolver = app(ValidAgentResolver::class);

        $this->assertFalse($resolver->isValid($notAgent->id));
        $this->assertFalse($resolver->isValid(999999));

        $a->update(['status' => 'suspended']);
        $this->assertFalse($resolver->isValid($a->id));
        $a->update(['status' => 'active']);
        $this->assertTrue($resolver->isValid($a->id));

        $a->delete(); // stale Admin → deleted Agent
        $this->assertFalse($resolver->isValid($a->id));
        foreach ([fn () => $this->resolve($admin), fn () => $this->resolve($sa, $a->id)] as $call) {
            try {
                $call();
                $this->fail('must fail closed');
            } catch (ApiException $e) {
                $this->assertSame(403, $e->status());
            }
        }
    }

    public function test_agent_linked_middleware_blocks_a_stale_link_but_leaves_valid_branches_alone(): void
    {
        $a = $this->agent();
        $admin = User::factory()->admin()->create(['agent_id' => $a->id]);

        $this->actingAs($admin)->getJson('/api/v1/manage/products')->assertOk();
        $a->delete();
        $this->actingAs($admin)->getJson('/api/v1/manage/products')->assertStatus(403);
    }

    public function test_payload_fingerprint_is_order_and_type_stable_but_value_sensitive(): void
    {
        $x = PayloadFingerprint::of(['b' => 2, 'a' => ['y' => 1.0, 'x' => ' s ']]);
        $y = PayloadFingerprint::of(['a' => ['x' => 's', 'y' => 1], 'b' => '2']);
        $this->assertSame($x, $y);
        $this->assertNotSame($x, PayloadFingerprint::of(['b' => 3, 'a' => ['y' => 1, 'x' => 's']]));
        $this->assertNotSame(PayloadFingerprint::of(['a' => null]), PayloadFingerprint::of(['a' => '']));
    }
}
