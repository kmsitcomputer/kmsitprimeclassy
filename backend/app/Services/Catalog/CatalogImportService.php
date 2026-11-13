<?php

namespace App\Services\Catalog;

use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariation;
use App\Models\User;
use App\Models\WarehouseSubLocation;
use App\Services\Hierarchy\ValidAgentResolver;
use App\Services\Product\ProductVariationService;
use App\Services\Stock\StockOpnameService;
use App\Support\PayloadFingerprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REM-01 / ODR-03 B / D-07 A.
 *
 *  import=target      a stock cell is the FINAL COUNTED physical quantity at ONE explicit
 *                     Agent/bucket/location — never a delta, never written as a balance.
 *  duplicate=update   a SKU that already exists in the global registry updates its owner.
 *  atomic=rows        every row is its own transaction; one bad row never blocks or rolls
 *                     back another.
 *
 * A row with NO stock cell is applied immediately. A row WITH a stock cell is STAGED: its
 * catalogue change is withheld (pending_catalog_change) and published by the very
 * transaction in which the Admin approves that row's single-target opname — so catalogue
 * publication and stock approval are one atomic step (D-07). Stock is only ever applied
 * through the existing opname (Gudang counts/submits, Admin approves, locked + stale-409).
 */
class CatalogImportService
{
    public const STOCK_TYPES = ['transit', 'shipping', 'sub'];

    public function __construct(
        private readonly ValidAgentResolver $agents,
        private readonly StockOpnameService $opnames,
        private readonly ProductVariationService $variations,
    ) {}

    /**
     * Pure validation — writes nothing.
     *
     * @param  list<array<string,string>>  $rows
     * @param  array{agent_id:?int, stock_type:?string, sub_location_id:?int}  $context
     * @return array{summary:array<string,int>, rows:list<array<string,mixed>>, context:?array<string,mixed>}
     */
    public function preview(User $actor, array $rows, array $context): array
    {
        $plans = $this->plan($actor, $rows, $context);

        return $this->summarise($plans, $plans['_context'] ?? null);
    }

    /**
     * @param  list<array<string,string>>  $rows
     * @param  array{agent_id:?int, stock_type:?string, sub_location_id:?int}  $context
     * @return array{summary:array<string,int>, rows:list<array<string,mixed>>, context:?array<string,mixed>}
     */
    public function apply(User $actor, array $rows, array $context, string $batchKey): array
    {
        $plans = $this->plan($actor, $rows, $context);
        $ctx = $plans['_context'] ?? null;
        unset($plans['_context']);

        foreach ($plans as $i => $plan) {
            if ($plan['errors'] !== []) {
                $plans[$i]['outcome'] = 'rejected';

                continue;
            }
            try {
                $plans[$i]['outcome'] = DB::transaction(fn () => $this->applyRow($actor, $plan, $ctx, $batchKey));
            } catch (ApiException $e) {
                $plans[$i]['errors'][] = $e->status() === 409 ? 'idempotency_conflict' : 'apply_failed';
                $plans[$i]['outcome'] = 'rejected';
            } catch (ValidationException $e) {
                $plans[$i]['errors'][] = 'validation:'.implode('|', array_keys($e->errors()));
                $plans[$i]['outcome'] = 'rejected';
            }
        }

        return $this->summarise($plans + ['_context' => $ctx], $ctx);
    }

    /* ------------------------------------------------------------------ planning */

    /** @return array<int|string,mixed> */
    private function plan(User $actor, array $rows, array $context): array
    {
        $anyStock = collect($rows)->contains(fn ($r) => ($r['stock_target'] ?? '') !== '');
        $ctx = null;
        $ctxError = null;
        if ($anyStock) {
            try {
                $ctx = $this->resolveContext($actor, $context);
            } catch (ApiException $e) {
                $ctxError = 'stock_context_invalid';
            }
        }

        // duplicate=update targets the DB owner; a SKU repeated INSIDE the file is ambiguous → reject every occurrence.
        $counts = [];
        foreach ($rows as $row) {
            $k = $this->skuKey($row['sku'] ?? '');
            if ($k !== '') {
                $counts[$k] = ($counts[$k] ?? 0) + 1;
            }
        }

        $plans = [];
        foreach ($rows as $i => $row) {
            $plans[$i] = $this->planRow($i + 2, $row, ($counts[$this->skuKey($row['sku'] ?? '')] ?? 0) > 1, $ctx, $ctxError);
        }
        $plans['_context'] = $ctx;

        return $plans;
    }

    private function skuKey(string $sku): string
    {
        return mb_strtolower($this->clean($sku));
    }

    /** Undo the export's formula-neutralising apostrophe; trim. */
    private function clean(string $v): string
    {
        $v = trim($v);
        if (strlen($v) > 1 && $v[0] === "'" && strpbrk($v[1], "=+-@\t\r") !== false) {
            $v = substr($v, 1);
        }

        return $v;
    }

    /** @return array<string,mixed> */
    private function planRow(int $line, array $raw, bool $dupInFile, ?array $ctx, ?string $ctxError): array
    {
        $row = array_map(fn ($v) => $this->clean((string) $v), $raw);
        $plan = ['line' => $line, 'sku' => $row['sku'] ?? '', 'type' => $row['type'] ?? '', 'action' => 'none', 'errors' => [], 'stock_target' => null, 'changes' => [], 'attributes' => null, 'target' => null, 'row' => $row];
        $err = function (string $code) use (&$plan) {
            $plan['errors'][] = $code;
        };

        if ($dupInFile) {
            $err('duplicate_sku_in_file');
        }
        $sku = $row['sku'] ?? '';
        if ($sku === '' || mb_strlen($sku) > 60) {
            $err('sku_invalid');
        }
        $type = $row['type'] ?? '';
        if (! in_array($type, ['simple', 'variant'], true)) {
            $err('type_invalid');
        }

        // numeric fields — omitted/empty preserves, an invalid value is an error
        $changes = [];
        if (($row['price'] ?? '') !== '') {
            if (! is_numeric($row['price']) || (float) $row['price'] < 0 || (float) $row['price'] > 9_999_999_999) {
                $err('price_invalid');
            } else {
                $changes['price'] = round((float) $row['price'], 2);
            }
        }
        if (($row['weight_grams'] ?? '') !== '') {
            if (! ctype_digit($row['weight_grams']) || (int) $row['weight_grams'] < 1) {
                $err('weight_invalid');
            } else {
                $changes['weight_grams'] = (int) $row['weight_grams'];
            }
        }
        if (($row['category_id'] ?? '') !== '') {
            if (! ctype_digit($row['category_id']) || ! ProductCategory::query()->whereKey((int) $row['category_id'])->exists()) {
                $err('category_invalid');
            } else {
                $changes['category_id'] = (int) $row['category_id'];
            }
        }
        if (($row['status'] ?? '') !== '') {
            $allowed = $type === 'variant' ? ['active', 'inactive'] : ['draft', 'active', 'inactive'];
            if (! in_array($row['status'], $allowed, true)) {
                $err('status_invalid');
            } else {
                $changes['status'] = $row['status'];
            }
        }
        if (($row['product_name'] ?? '') !== '') {
            if (mb_strlen($row['product_name']) > 150) {
                $err('name_invalid');
            } else {
                $changes['name'] = $row['product_name'];
            }
        }
        if (($row['attributes'] ?? '') !== '') {
            $attrs = $this->parseAttributes($row['attributes']);
            if ($attrs === null) {
                $err('attributes_invalid');
            } else {
                $plan['attributes'] = $attrs;
            }
        }
        if (($row['stock_target'] ?? '') !== '') {
            if (! ctype_digit($row['stock_target']) || (int) $row['stock_target'] > 100_000_000) {
                $err('stock_target_invalid');
            } elseif ($ctxError) {
                $err($ctxError);
            } else {
                $plan['stock_target'] = (int) $row['stock_target'];
            }
        }

        if ($plan['errors'] !== [] && in_array('sku_invalid', $plan['errors'], true) || ! in_array($type, ['simple', 'variant'], true)) {
            return $plan;
        }

        // SKU owner resolution through the global registry (never bypassed, never remapped).
        $owner = DB::table('catalog_skus')->where('sku', $sku)->first();
        if ($owner) {
            $expected = $type === 'variant' ? 'variant' : 'product';
            if ($owner->owner_type !== $expected) {
                $err('sku_owner_type_mismatch');

                return $plan;
            }
            $model = $expected === 'variant'
                ? ProductVariation::query()->find($owner->owner_id)
                : Product::query()->find($owner->owner_id);
            if (! $model) {
                $err('sku_retired'); // owner soft-deleted/removed: never silently revived or re-created

                return $plan;
            }
            if ($type === 'variant') {
                if (($row['product_slug'] ?? '') !== '' && $model->product?->slug !== $row['product_slug']) {
                    $err('sku_belongs_to_other_product');

                    return $plan;
                }
                $plan['target'] = ['kind' => 'variant', 'id' => $model->id, 'product_id' => $model->product_id];
            } else {
                if ($model->has_variations) {
                    $err('product_has_variations');

                    return $plan;
                }
                $plan['target'] = ['kind' => 'product', 'id' => $model->id];
            }
            $plan['action'] = 'update';
            if ($type === 'variant') {
                unset($changes['name'], $changes['category_id']);
                $changes = $this->variantFields($changes);
            }
            $plan['changes'] = $changes;

            return $plan;
        }

        // ---- create
        $plan['action'] = 'create';
        if ($type === 'simple') {
            foreach (['name' => 'name_required', 'price' => 'price_required', 'weight_grams' => 'weight_required'] as $f => $code) {
                if (! isset($changes[$f])) {
                    $err($code);
                }
            }
            $slug = $row['product_slug'] ?? '';
            if ($slug !== '' && (mb_strlen($slug) > 150 || Product::withTrashed()->where('slug', $slug)->exists())) {
                $err('slug_taken');
            }
            $changes['slug'] = $slug !== '' ? $slug : null;
            $changes['status'] = $changes['status'] ?? 'draft';
        } else {
            $parent = ($row['product_slug'] ?? '') !== '' ? Product::query()->where('slug', $row['product_slug'])->first() : null;
            if (! $parent) {
                $err('parent_not_found');
            } elseif (! $parent->has_variations) {
                $err('parent_without_variations');
            } else {
                $plan['target'] = ['kind' => 'new_variant', 'product_id' => $parent->id];
            }
            foreach (['price' => 'price_required', 'weight_grams' => 'weight_required'] as $f => $code) {
                if (! isset($changes[$f])) {
                    $err($code);
                }
            }
            if ($plan['attributes'] === null) {
                $err('attributes_required');
            }
            $changes = $this->variantFields($changes);
            $changes['is_active'] = $changes['is_active'] ?? true;
        }
        $plan['changes'] = $changes;

        return $plan;
    }

    /** Variant rows speak "status"; the variant table speaks is_active. @return array<string,mixed> */
    private function variantFields(array $changes): array
    {
        if (isset($changes['status'])) {
            $changes['is_active'] = $changes['status'] === 'active';
            unset($changes['status']);
        }

        return $changes;
    }

    /** "Ukuran=S;Rasa=Coklat" → ['Ukuran'=>'S','Rasa'=>'Coklat'] (null when malformed). */
    private function parseAttributes(string $text): ?array
    {
        $out = [];
        foreach (explode(';', $text) as $pair) {
            if (trim($pair) === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            if (count($parts) !== 2) {
                return null;
            }
            [$n, $v] = [trim($parts[0]), trim($parts[1])];
            if ($n === '' || $v === '' || mb_strlen($n) > 100 || mb_strlen($v) > 100 || isset($out[$n])) {
                return null;
            }
            $out[$n] = $v;
        }

        return $out ?: null;
    }

    /** @return array{agent_id:int, stock_type:string, sub_location_id:?int} */
    private function resolveContext(User $actor, array $context): array
    {
        $agent = $this->agents->forActor($actor, $context['agent_id'] ?? null);
        $type = $context['stock_type'] ?? null;
        if (! in_array($type, self::STOCK_TYPES, true)) {
            throw new ApiException('Konteks stok tidak valid.', 422);
        }
        $loc = $context['sub_location_id'] ?? null;
        if (($type === 'sub') !== ($loc !== null)) {
            throw new ApiException('Konteks stok tidak valid.', 422);
        }
        if ($loc !== null && ! WarehouseSubLocation::query()->whereKey($loc)->where('agent_id', $agent->id)->where('is_active', true)->exists()) {
            throw new ApiException('Sub Location bukan milik Agen ini.', 403);
        }

        return ['agent_id' => $agent->id, 'stock_type' => $type, 'sub_location_id' => $loc];
    }

    /* ------------------------------------------------------------------ applying */

    /** @return string outcome */
    private function applyRow(User $actor, array $plan, ?array $ctx, string $batchKey): string
    {
        $staged = $plan['stock_target'] !== null;
        $rowKey = null;
        $fingerprint = null;

        if ($staged) {
            $rowKey = 'imp:'.substr(hash('sha256', implode('|', [$batchKey, $ctx['agent_id'], $ctx['stock_type'], $ctx['sub_location_id'] ?? 0, $this->skuKey($plan['sku'])])), 0, 60);
            $fingerprint = PayloadFingerprint::of(['row' => $plan['row'], 'ctx' => $ctx]);
            $existing = $this->opnames->findImportRow($rowKey);
            if ($existing) {
                if ($existing->import_fingerprint !== $fingerprint) {
                    throw new ApiException('Idempotency key dipakai dengan data berbeda.', 409);
                }

                return 'stock_pending_replay';
            }
        }

        $changes = $plan['changes'];
        $pending = null;

        if ($plan['action'] === 'update') {
            if ($staged) {
                $pending = ['kind' => $plan['target']['kind'], 'id' => $plan['target']['id'], 'changes' => $changes, 'attributes' => $plan['attributes']];
                $targetKind = $plan['target']['kind'];
                $targetId = $plan['target']['id'];
            } else {
                $this->applyChange($plan['target']['kind'], $plan['target']['id'], $changes, $plan['attributes']);

                return 'catalog_updated';
            }
        } else {
            // create
            if ($plan['type'] === 'simple') {
                $finalStatus = $changes['status'];
                $create = $changes;
                if ($staged && $finalStatus === 'active') {
                    $create['status'] = 'draft'; // never active before approval
                }
                $product = new Product([
                    'name' => $create['name'], 'slug' => $create['slug'] ?? (Str::slug($create['name']).'-'.uniqid()),
                    'sku' => $plan['sku'], 'has_variations' => false, 'base_price' => $create['price'],
                    'weight_grams' => $create['weight_grams'], 'category_id' => $create['category_id'] ?? null,
                    'status' => $create['status'], 'created_by' => $actor->id,
                ]);
                $product->save();
                if (! $staged) {
                    return 'catalog_created';
                }
                $pending = ['kind' => 'product', 'id' => $product->id, 'changes' => $finalStatus === 'active' ? ['status' => 'active'] : [], 'attributes' => null];
                $targetKind = 'product';
                $targetId = $product->id;
            } else {
                $parent = Product::query()->findOrFail($plan['target']['product_id']);
                $finalActive = $changes['is_active'];
                $variation = $this->variations->create($parent, $plan['attributes'], [
                    'sku' => $plan['sku'], 'price' => $changes['price'], 'weight_grams' => $changes['weight_grams'],
                    'is_active' => $staged ? false : $finalActive,
                ]);
                if (! $staged) {
                    return 'catalog_created';
                }
                $pending = ['kind' => 'variant', 'id' => $variation->id, 'changes' => $finalActive ? ['is_active' => true] : [], 'attributes' => null];
                $targetKind = 'variant';
                $targetId = $variation->id;
            }
        }

        $this->opnames->stageImportRow(
            $actor, $ctx['agent_id'], $ctx['stock_type'], $ctx['sub_location_id'],
            $targetKind === 'product' ? $targetId : null, $targetKind === 'variant' ? $targetId : null,
            $rowKey, $fingerprint, $plan['stock_target'], $pending,
        );

        return $pending && ($pending['changes'] !== [] || $pending['attributes'] !== null) ? 'catalog_withheld_stock_pending' : 'stock_pending';
    }

    /** Applies catalogue fields to an existing owner (also used by the approval-time publisher). @param array<string,mixed> $changes @param ?array<string,string> $attributes */
    public function applyChange(string $kind, int $id, array $changes, ?array $attributes): void
    {
        if ($kind === 'product') {
            $p = Product::query()->findOrFail($id);
            $map = ['price' => 'base_price'];
            foreach ($changes as $k => $v) {
                $p->{$map[$k] ?? $k} = $v;
            }
            $p->save();
        } else {
            $v = ProductVariation::query()->findOrFail($id);
            foreach ($changes as $k => $val) {
                $v->{$k} = $val;
            }
            $v->save();
            if ($attributes !== null) {
                $this->variations->updateAttributes($v->product, $v, $attributes);
            }
        }
    }

    /* ------------------------------------------------------------------ result shaping */

    private function summarise(array $plans, ?array $ctx): array
    {
        unset($plans['_context']);
        $rows = [];
        $summary = ['total' => 0, 'valid' => 0, 'invalid' => 0, 'create' => 0, 'update' => 0, 'with_stock' => 0];
        foreach ($plans as $plan) {
            $summary['total']++;
            $ok = $plan['errors'] === [];
            $summary[$ok ? 'valid' : 'invalid']++;
            if ($ok) {
                $summary[$plan['action']] = ($summary[$plan['action']] ?? 0) + 1;
                $summary['with_stock'] += $plan['stock_target'] !== null ? 1 : 0;
            }
            $rows[] = [
                'line' => $plan['line'], 'sku' => $plan['sku'], 'type' => $plan['type'],
                'action' => $ok ? $plan['action'] : 'none', 'valid' => $ok, 'errors' => array_values(array_unique($plan['errors'])),
                'stock_target' => $plan['stock_target'], 'outcome' => $plan['outcome'] ?? null,
            ];
        }

        return ['summary' => $summary, 'rows' => $rows, 'context' => $ctx];
    }
}
