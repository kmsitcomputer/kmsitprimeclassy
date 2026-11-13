<?php

namespace App\Support;

use App\Services\Product\CategoryHierarchyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REM-06 DB-hardening preflight: READ-ONLY detection of categories that the application's own
 * normalisation (CategoryHierarchyService::nameKey — NFC, control/zero-width removal, whitespace
 * collapse, case-fold) considers equal within the same parent identity. It is the single source of
 * truth shared by the `categories:unique-preflight` command and the proposed migration, so the
 * preflight can never disagree with the constraint it prepares. It writes nothing.
 */
class CategoryDuplicateScanner
{
    /**
     * @param  array<int, array{id:int, parent_id:?int, name:string, slug?:string}>|null  $rows  null = read from the database
     * @return array{rows:int, groups:array<int, array<string, mixed>>, empty_keys:array<int,int>, accent_only:array<int, array<string, mixed>>}
     */
    public static function scan(?array $rows = null): array
    {
        $rows ??= DB::table('product_categories')->orderBy('id')->get(['id', 'parent_id', 'name', 'slug'])->map(fn ($r) => (array) $r)->all();

        $byKey = [];
        $emptyKeys = [];
        $accentFolded = [];
        foreach ($rows as $r) {
            $key = CategoryHierarchyService::nameKey((string) $r['name']);
            if ($key === '') {
                $emptyKeys[] = (int) $r['id'];

                continue;
            }
            $parent = $r['parent_id'] === null ? 0 : (int) $r['parent_id'];
            $byKey[$parent][$key][] = $r;
            // informational: equal only after accent folding (the DB collation would fold them, the app does not)
            $accentFolded[$parent][self::foldAccents($key)][$key][] = (int) $r['id'];
        }

        $refs = self::referenceCounts();
        $groups = [];
        foreach ($byKey as $parent => $keys) {
            foreach ($keys as $key => $members) {
                if (count($members) < 2) {
                    continue;
                }
                $groups[] = [
                    'parent_id' => $parent === 0 ? null : $parent,
                    'scope' => $parent === 0 ? 'root' : 'siblings',
                    'normalized_key_length' => mb_strlen($key),
                    'members' => array_map(fn ($m) => [
                        'category_id' => (int) $m['id'],
                        'reason' => self::reason($members[0], $m),
                        'products' => $refs['products'][(int) $m['id']] ?? 0,
                        'cms_blocks' => $refs['cms'][(int) $m['id']] ?? 0,
                        'children' => $refs['children'][(int) $m['id']] ?? 0,
                    ], $members),
                ];
            }
        }

        $accentOnly = [];
        foreach ($accentFolded as $parent => $folds) {
            foreach ($folds as $fold => $variants) {
                if (count($variants) > 1) {
                    $accentOnly[] = ['parent_id' => $parent === 0 ? null : $parent, 'category_ids' => array_merge(...array_values($variants))];
                }
            }
        }

        return ['rows' => count($rows), 'groups' => $groups, 'empty_keys' => $emptyKeys, 'accent_only' => $accentOnly];
    }

    /** Why `$m` collides with the group's first member (no names are echoed, only the reason code). */
    private static function reason(array $first, array $m): string
    {
        $a = (string) $first['name'];
        $b = (string) $m['name'];
        if ($a === $b) {
            return 'identical';
        }
        if (mb_strtolower($a, 'UTF-8') === mb_strtolower($b, 'UTF-8')) {
            return 'case';
        }
        if (preg_replace('/\s+/u', ' ', trim($a)) === preg_replace('/\s+/u', ' ', trim($b))) {
            return 'whitespace';
        }
        if (class_exists(\Normalizer::class) && \Normalizer::normalize($a, \Normalizer::FORM_C) === \Normalizer::normalize($b, \Normalizer::FORM_C)) {
            return 'unicode_normalization';
        }

        return 'zero_width_or_control_characters';
    }

    private static function foldAccents(string $s): string
    {
        if (! class_exists(\Normalizer::class)) {
            return $s;
        }

        return preg_replace('/\p{Mn}+/u', '', \Normalizer::normalize($s, \Normalizer::FORM_D) ?: $s) ?? $s;
    }

    /** @return array{products:array<int,int>, cms:array<int,int>, children:array<int,int>} */
    private static function referenceCounts(): array
    {
        $out = ['products' => [], 'cms' => [], 'children' => []];
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'category_id')) {
            $out['products'] = DB::table('products')->whereNotNull('category_id')->selectRaw('category_id, COUNT(*) n')->groupBy('category_id')->pluck('n', 'category_id')->map(fn ($n) => (int) $n)->all();
        }
        $out['children'] = DB::table('product_categories')->whereNotNull('parent_id')->selectRaw('parent_id, COUNT(*) n')->groupBy('parent_id')->pluck('n', 'parent_id')->map(fn ($n) => (int) $n)->all();
        if (Schema::hasTable('cms_homepage_blocks')) {
            foreach (DB::table('cms_homepage_blocks')->where('type', 'category')->get(['content']) as $block) {
                $content = json_decode((string) $block->content, true) ?: [];
                foreach (array_merge((array) ($content['category_ids'] ?? []), isset($content['category_id']) ? [$content['category_id']] : []) as $id) {
                    $out['cms'][(int) $id] = ($out['cms'][(int) $id] ?? 0) + 1;
                }
            }
        }

        return $out;
    }
}
