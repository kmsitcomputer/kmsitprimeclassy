<?php

namespace Tests\Feature;

use App\Models\CmsHomepageBlock;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\CategoryDuplicateScanner;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RestoresIsolatedTestDatabase;
use Tests\TestCase;

/** REM-06 preflight: the READ-ONLY scanner classifies exactly what the application normalisation treats as equal. */
class CategoryDuplicateScannerTest extends TestCase
{
    use RestoresIsolatedTestDatabase;

    protected bool $isolatedSchemaAlwaysRebuild = true;

    /** Legacy duplicates can only exist BEFORE the hardening, so recreate the pre-migration shape (schema is rebuilt afterwards). */
    protected function setUp(): void
    {
        parent::setUp();
        if (Schema::hasColumn('product_categories', 'name_key')) {
            (require base_path('database/migrations/2026_11_16_200000_add_unique_normalized_name_key_to_product_categories.php'))->down();
        }
    }

    private function cat(string $name, ?int $parent = null): ProductCategory
    {
        return ProductCategory::create(['name' => $name, 'parent_id' => $parent, 'slug' => 's-'.uniqid()]);
    }

    private function reasons(array $report): array
    {
        return collect($report['groups'])->map(fn ($g) => collect($g['members'])->pluck('reason')->slice(1)->values()->all())->all();
    }

    public function test_clean_data_reports_no_conflicts(): void
    {
        $kue = $this->cat('Kue');
        $this->cat('Nastar', $kue->id);
        $this->cat('Nastar', $this->cat('Roti')->id);   // same name, different parent: fine
        $this->cat('Nastar');                            // root vs child: fine
        $r = CategoryDuplicateScanner::scan();
        $this->assertSame([], $r['groups']);
        $this->assertSame([], $r['empty_keys']);
        $this->assertSame(5, $r['rows']);
    }

    public function test_every_equivalence_class_of_the_application_normalisation_is_detected_with_a_reason(): void
    {
        $p = $this->cat('Induk');
        foreach ([
            'identical' => ['Kopi', 'Kopi'],
            'case' => ['Teh', 'TEH'],
            'whitespace' => ['Es Jeruk', "  Es   Jeruk\t"],
            'unicode_normalization' => ["Caf\u{00E9}", "Cafe\u{0301}"],
            'zero_width_or_control_characters' => ['Susu Murni', "Susu\u{200B} Murni"],
        ] as $reason => [$a, $b]) {
            $this->cat($a, $p->id);
            $this->cat($b, $p->id);
        }
        $r = CategoryDuplicateScanner::scan();
        $this->assertCount(5, $r['groups']);
        $this->assertEqualsCanonicalizing(['identical', 'case', 'whitespace', 'unicode_normalization', 'zero_width_or_control_characters'], collect($this->reasons($r))->flatten()->all());
        foreach ($r['groups'] as $g) {
            $this->assertSame('siblings', $g['scope']);
            $this->assertSame($p->id, $g['parent_id']);
        }
    }

    public function test_root_duplicates_are_detected_and_names_are_not_echoed(): void
    {
        $a = $this->cat('Minuman');
        $b = $this->cat(' minuman ');
        $r = CategoryDuplicateScanner::scan();
        $this->assertSame('root', $r['groups'][0]['scope']);
        $this->assertNull($r['groups'][0]['parent_id']);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], collect($r['groups'][0]['members'])->pluck('category_id')->all());
        $this->assertStringNotContainsString('inuman', json_encode($r), 'category names must not appear in the report');
    }

    public function test_empty_normalised_names_are_reported_and_accent_only_lookalikes_are_informational_not_blocking(): void
    {
        $e = $this->cat("\u{200B}\t ");
        $this->cat('Cafe');
        $this->cat("Caf\u{00E9}");
        $r = CategoryDuplicateScanner::scan();
        $this->assertSame([$e->id], $r['empty_keys']);
        $this->assertSame([], $r['groups'], 'accent-only differences are distinct under the application key');
        $this->assertCount(1, $r['accent_only']);
    }

    public function test_reference_impact_is_reported_per_member(): void
    {
        $a = $this->cat('Kue');
        $b = $this->cat('KUE');
        $this->cat('Anak', $b->id);
        Product::create(['name' => 'P', 'slug' => 'p', 'sku' => 'P-1', 'has_variations' => false, 'base_price' => 1, 'weight_grams' => 1, 'status' => 'active', 'category_id' => $a->id]);
        CmsHomepageBlock::create(['type' => 'category', 'sort_order' => 1, 'is_active' => true, 'content' => ['category_ids' => [$b->id]]]);
        $m = collect(CategoryDuplicateScanner::scan()['groups'][0]['members'])->keyBy('category_id');
        $this->assertSame(1, $m[$a->id]['products']);
        $this->assertSame(0, $m[$a->id]['cms_blocks']);
        $this->assertSame(1, $m[$b->id]['cms_blocks']);
        $this->assertSame(1, $m[$b->id]['children']);
    }

    public function test_the_preflight_command_is_read_only_and_exits_non_zero_on_conflicts(): void
    {
        $this->cat('Kue');
        $this->artisan('categories:unique-preflight')->assertExitCode(0);
        $this->cat('KUE');
        $before = ProductCategory::query()->orderBy('id')->get(['id', 'name', 'parent_id', 'slug', 'updated_at'])->toArray();
        $this->artisan('categories:unique-preflight')->assertExitCode(1);
        $this->artisan('categories:unique-preflight', ['--json' => true])->assertExitCode(1);
        $this->assertSame($before, ProductCategory::query()->orderBy('id')->get(['id', 'name', 'parent_id', 'slug', 'updated_at'])->toArray(), 'nothing written');
    }
}
