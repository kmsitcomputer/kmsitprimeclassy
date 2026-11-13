<?php

namespace App\Services\Product;

use App\Exceptions\ApiException;
use App\Models\Product;
use App\Services\Logging\ActivityLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * REM-04: guarded PERMANENT product delete (distinct from the existing soft delete).
 *
 * A product is removable only when nothing outside its own catalog "shell"
 * (images, fees, translations, attributes, compositions — all CASCADE-owned)
 * refers to it or to its variants. The reference scan is derived from the live
 * schema (every table that has a product_id / product_variation_id column), so
 * a future table that references products blocks deletion by default instead of
 * being silently forgotten. DB restrict-FKs remain the last line of defence
 * against a concurrent writer; that race maps to 409, never 500.
 */
class ProductHardDeleteService
{
    /** Columns that point at catalog rows. */
    private const COLUMNS = ['product_id', 'product_variation_id'];

    /**
     * @return array<string,int> table => referencing rows (empty = deletable)
     */
    public function blockers(Product $product): array
    {
        $variationIds = DB::table('product_variations')->where('product_id', $product->id)->pluck('id')->all();
        $owned = $this->ownedTables();
        $found = [];

        $cols = DB::select(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN (?, ?)',
            self::COLUMNS
        );
        foreach ($cols as $col) {
            if (in_array($col->t, $owned, true) || in_array($col->t, ['products', 'product_variations'], true)) {
                continue;
            }
            $ids = $col->c === 'product_id' ? [$product->id] : $variationIds;
            if ($ids === []) {
                continue;
            }
            $n = DB::table($col->t)->whereIn($col->c, $ids)->count();
            if ($n > 0) {
                $found[$col->t] = ($found[$col->t] ?? 0) + $n;
            }
        }
        ksort($found);

        return $found;
    }

    public function delete(Product $product, ?int $actorId, ?string $actorRole): void
    {
        $paths = [];
        $snapshot = $product->only(['id', 'sku', 'name', 'status']);

        try {
            DB::transaction(function () use ($product, &$paths) {
                $locked = Product::withTrashed()->whereKey($product->id)->lockForUpdate()->first();
                if (! $locked) {
                    throw new ApiException('Produk tidak ditemukan.', 404);
                }
                DB::table('product_variations')->where('product_id', $product->id)->orderBy('id')->lockForUpdate()->pluck('id');

                $blockers = $this->blockers($locked);
                if ($blockers !== []) {
                    throw new ApiException(
                        'Produk tidak dapat dihapus permanen karena masih direferensikan.', 409, ['references' => $blockers]
                    );
                }

                $variationIds = DB::table('product_variations')->where('product_id', $product->id)->pluck('id')->all();
                $paths = DB::table('product_images')->where('product_id', $product->id)->pluck('path')->all();

                DB::table('catalog_skus')->where('owner_type', 'variant')->whereIn('owner_id', $variationIds)->delete();
                DB::table('catalog_skus')->where('owner_type', 'product')->where('owner_id', $product->id)->delete();
                // CASCADE FKs remove images/fees/translations/attributes/compositions with the parents.
                DB::table('product_variations')->where('product_id', $product->id)->delete();
                $locked->forceDelete();
            });
        } catch (QueryException $e) {
            // A concurrent writer slipped a restrict-FK reference in between the scan and the delete.
            if (in_array($e->errorInfo[1] ?? null, [1451, 1217], true)) {
                throw new ApiException('Produk tidak dapat dihapus permanen karena masih direferensikan.', 409);
            }
            throw $e;
        }

        // File cleanup strictly AFTER commit, and never for a file another row still uses.
        foreach (array_unique($paths) as $path) {
            if (! DB::table('product_images')->where('path', $path)->exists()) {
                Storage::disk('public')->delete($path);
            }
        }

        ActivityLogger::log($actorId, $product, 'product.hard_deleted', null, [
            'actor_role' => $actorRole,
            'old' => $snapshot,
        ]);
    }

    /** Tables whose rows belong to the product and are removed with it (CASCADE to products / product_variations). @return list<string> */
    private function ownedTables(): array
    {
        $rows = DB::select(
            "SELECT DISTINCT kcu.TABLE_NAME AS t
             FROM information_schema.REFERENTIAL_CONSTRAINTS rc
             JOIN information_schema.KEY_COLUMN_USAGE kcu
               ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME AND kcu.TABLE_NAME = rc.TABLE_NAME
             WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
               AND rc.DELETE_RULE = 'CASCADE'
               AND rc.REFERENCED_TABLE_NAME IN ('products', 'product_variations')"
        );

        return array_map(fn ($r) => $r->t, $rows);
    }
}
