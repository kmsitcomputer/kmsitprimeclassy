<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use Tests\Concerns\BuildsCrmFixtures;
use Tests\TestCase;

/**
 * Area A Phase 2: CRM customer XLSX export.
 * The export is the JSON list alone in a different body: the identical CrmAccess scope,
 * the identical archived/stage filters, plus an audit event for the mass personal-data read.
 */
class CrmExportTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCrmFixtures;

    protected int $exportCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** Streams the export and returns the data rows (header excluded) as arrays. */
    private function export(User $actor, array $params = []): array
    {
        $response = $this->actingAs($actor)->get('/api/v1/crm/customers?'.http_build_query($params + ['export' => 'xlsx']));
        $response->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('crm-customers-', $response->headers->get('content-disposition'));

        $content = $response->streamedContent();
        $path = tempnam(sys_get_temp_dir(), 'crmexport').'.xlsx';
        file_put_contents($path, $content);
        $rowMatrix = (new XlsxReader)->load($path)->getActiveSheet()->toArray();
        unlink($path);

        $this->exportCount++;
        array_shift($rowMatrix);

        return $rowMatrix;
    }

    private function names(array $rows): array
    {
        return array_map(fn (array $r) => $r[1], $rows);
    }

    public function test_export_scope_matches_the_json_list_per_role(): void
    {
        $a = $this->branch();
        $b = $this->branch();
        $loner = User::factory()->sales()->create(['agent_id' => $a['agen']->id]); // same branch, NOT under the korsal

        $dealId = $this->crmCustomer($a['sales'], ['name' => 'A-Sales-Deal']);
        $fuId = $this->crmCustomer($a['sales'], ['name' => 'A-Sales-FU']);
        $this->crmCustomer($a['sks'], ['name' => 'A-SKS']);
        $this->crmCustomer($loner, ['name' => 'A-Loner']);
        $this->crmCustomer($a['korsal'], ['name' => 'A-Korsal']);
        $this->crmCustomer($b['sales'], ['name' => 'B-Sales']);

        $this->actingAs($a['sales'])->postJson("/api/v1/crm/customers/{$dealId}/visits", ['visit_date' => now()->toDateString(), 'status' => '7'])->assertCreated();
        $this->actingAs($a['sales'])->postJson("/api/v1/crm/customers/{$fuId}/visits", [
            'visit_date' => now()->toDateString(), 'status' => '3', 'plan_next_date' => now()->addDay()->toDateString(),
        ])->assertCreated();

        // sales: exactly the two customers they own — nothing else, not even in their own branch.
        $rows = $this->names($this->export($a['sales']));
        $this->assertEqualsCanonicalizing(['A-Sales-Deal', 'A-Sales-FU'], $rows);

        // sks: own only.
        $rows = $this->names($this->export($a['sks']));
        $this->assertEqualsCanonicalizing(['A-SKS'], $rows);

        // korsal: own + downline owners' (korsal_id) customers. The SKS and the lone sales are NOT
        // their downline, so even same-branch rows stay hidden — the same rule as the JSON list.
        $rows = $this->names($this->export($a['korsal']));
        $this->assertEqualsCanonicalizing(['A-Sales-Deal', 'A-Sales-FU', 'A-Korsal'], $rows);
        $this->assertNotContains('A-SKS', $rows);
        $this->assertNotContains('A-Loner', $rows);

        // agen/admin: the whole branch, cross-branch rows never appear.
        foreach ([$a['agen'], $a['admin']] as $actor) {
            $rows = $this->names($this->export($actor));
            $this->assertEqualsCanonicalizing(
                ['A-Sales-Deal', 'A-Sales-FU', 'A-SKS', 'A-Korsal', 'A-Loner'],
                $rows,
                get_class($actor).' must see the whole branch only'
            );
            $this->assertNotContains('B-Sales', $rows);
        }

        // super_admin: everything.
        $super = User::factory()->superAdmin()->create();
        $rows = $this->names($this->export($super));
        $this->assertContains('A-Loner', $rows);
        $this->assertContains('B-Sales', $rows);

        // Branch B's actor never sees branch A rows.
        $rows = $this->names($this->export($b['sales']));
        $this->assertEqualsCanonicalizing(['B-Sales'], $rows);
    }

    public function test_export_applies_stage_and_archived_filters(): void
    {
        $a = $this->branch();
        $dealId = $this->crmCustomer($a['sales'], ['name' => 'A-Sales-Deal']);
        $fuId = $this->crmCustomer($a['sales'], ['name' => 'A-Sales-FU']);

        $this->actingAs($a['sales'])->postJson("/api/v1/crm/customers/{$dealId}/visits", ['visit_date' => now()->toDateString(), 'status' => '7'])->assertCreated();
        $this->actingAs($a['sales'])->postJson("/api/v1/crm/customers/{$fuId}/visits", [
            'visit_date' => now()->toDateString(), 'status' => '3', 'plan_next_date' => now()->addDay()->toDateString(),
        ])->assertCreated();
        $this->actingAs($a['sales'])->postJson("/api/v1/crm/customers/{$fuId}/archive")->assertOk();

        // stage=D => customer whose latest visit is Deal.
        $rows = $this->names($this->export($a['sales'], ['stage' => 'D']));
        $this->assertEqualsCanonicalizing(['A-Sales-Deal'], $rows);

        // The archived customer with an open plan is invisible to the active export.
        $rows = $this->names($this->export($a['sales'], ['stage' => 'FU']));
        $this->assertSame([], $rows, 'Archived customers leave the active list, so FU on active set is empty.');

        // archived=1 => only the archived customer.
        $rows = $this->names($this->export($a['sales'], ['archived' => '1']));
        $this->assertEqualsCanonicalizing(['A-Sales-FU'], $rows);
    }

    public function test_export_writes_audit_events(): void
    {
        $a = $this->branch();
        $this->crmCustomer($a['sales'], ['name' => 'A-Sales-Deal']);
        $this->crmCustomer($a['korsal'], ['name' => 'A-Korsal']);

        $this->export($a['sales']);
        $this->export($a['korsal'], ['stage' => 'D']);

        $logs = ActivityLog::where('event', 'crm.customers.exported')->orderBy('id')->get();
        $this->assertSame(2, $logs->count());
        $this->assertSame($a['sales']->id, (int) $logs[0]->causer_id);
        $this->assertSame(1, (int) $logs[0]->properties['count']);
        $this->assertSame('D', $logs[1]->properties['stage']);
    }

    public function test_keuangan_has_no_export_route(): void
    {
        $a = $this->branch();
        $this->actingAs($a['keuangan'])->get('/api/v1/crm/customers?export=xlsx')
            ->assertForbidden();
    }
}
