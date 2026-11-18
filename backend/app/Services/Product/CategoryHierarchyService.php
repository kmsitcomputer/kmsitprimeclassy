<?php

namespace App\Services\Product;

use App\Exceptions\ApiException;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REM-06: parent_id hierarchy integrity for product categories.
 *
 * The whole taxonomy is tiny and structurally edited by super_admin only, so
 * mutations serialise on a lock over every category row. That makes two
 * concurrent "A under B" / "B under A" edits impossible to interleave into a
 * cycle (the second sees the first's committed parent).
 */
class CategoryHierarchyService
{
    /** @param  array<string,mixed>  $attributes */
    /** MariaDB/MySQL advisory lock that serialises category creation across connections (deadlock-free, works on an empty table). */
    private const CREATE_LOCK = 'primeclassy:product_categories:create';

    /**
     * REM-06 global CREATE-ONLY: super_admin / admin / agen may create parent or child categories in the
     * ONE global taxonomy. Duplicate protection = (normalised name + parent identity), enforced under a
     * serialising advisory lock + tree row locks with LOCKING reads (never an application-only exists()).
     * Residual risk: there is no UNIQUE index on (parent_id, name) — a writer that bypasses this service
     * (raw SQL, future code) is not stopped by the database (see the migration proposal in the report).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?User $actor = null): ProductCategory
    {
        $name = self::normalizeName((string) ($attributes['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Nama kategori tidak boleh kosong.']);
        }
        $attributes['name'] = $name;

        $acquired = (int) (DB::selectOne('SELECT GET_LOCK(?, 10) AS got', [self::CREATE_LOCK])->got ?? 0);
        if ($acquired !== 1) {
            throw new ApiException('Sistem sedang memproses kategori lain. Silakan coba lagi.', 503);
        }

        try {
            return $this->guardingDuplicates(fn () => DB::transaction(function () use ($attributes, $name, $actor) {
                $parentId = isset($attributes['parent_id']) ? (int) $attributes['parent_id'] : null;
                $this->lockTree();
                $this->assertParentExists($parentId);
                $this->assertNoSiblingWithName($parentId, $name);

                // refresh(): the response must carry the stored defaults (is_active / sort_order), not nulls.
                $category = ProductCategory::create($attributes)->refresh();

                ActivityLogger::log($actor?->id, $category, 'category.create', 'Kategori dibuat (taksonomi global)', [
                    'category_id' => $category->id,
                    'parent_id' => $category->parent_id,
                    // Server-derived only — never read from the request payload.
                    'actor_role' => $actor?->role?->slug,
                    'agent_id' => $actor?->agent_id,
                    'result' => 'success',
                ]);

                return $category;
            }));
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [self::CREATE_LOCK]);
        }
    }

    /**
     * Once the DB-level unique key exists (proposed migration), a duplicate that slipped past the application
     * check (raw writer, race outside the lock) surfaces as a unique violation: map it to the SAME 422 the
     * application check produces, never a 500. Pre-migration this is a no-op wrapper.
     */
    private function guardingDuplicates(callable $work): mixed
    {
        try {
            return $work();
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'slug')) {
                throw $e; // slug collisions keep their existing behaviour
            }
            throw ValidationException::withMessages(['name' => 'Kategori dengan nama yang sama sudah ada pada induk ini.']);
        }
    }

    /** Unicode NFC, control/zero-width characters removed, whitespace trimmed and collapsed. */
    public static function normalizeName(string $name): string
    {
        if (class_exists(\Normalizer::class)) {
            $name = \Normalizer::normalize($name, \Normalizer::FORM_C) ?: $name;
        }
        $name = preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', $name) ?? $name; // controls become spaces; zero-width/format chars (U+200B…) too
        $name = preg_replace('/[\p{Zs}\s]+/u', ' ', $name) ?? $name;

        return trim($name);
    }

    /** Comparison key: normalised, case-folded. (The column collation additionally ignores accents.) */
    public static function nameKey(string $name): string
    {
        return mb_strtolower(self::normalizeName($name), 'UTF-8');
    }

    private function assertNoSiblingWithName(?int $parentId, string $name, ?int $exceptId = null): void
    {
        // LOCKING read: sees every committed sibling, including one a concurrent request just inserted.
        $siblings = ProductCategory::query()
            ->when($parentId === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parentId))
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->lockForUpdate()->get(['id', 'name']);
        $key = self::nameKey($name);
        foreach ($siblings as $sibling) {
            if (self::nameKey((string) $sibling->name) === $key) {
                throw ValidationException::withMessages(['name' => 'Kategori dengan nama yang sama sudah ada pada induk ini.']);
            }
        }
    }

    public function update(ProductCategory $category, array $attributes): ProductCategory
    {
        $changesIdentity = array_key_exists('name', $attributes) || array_key_exists('parent_id', $attributes);
        if (array_key_exists('name', $attributes)) {
            $attributes['name'] = self::normalizeName((string) $attributes['name']);
            if ($attributes['name'] === '') {
                throw ValidationException::withMessages(['name' => 'Nama kategori tidak boleh kosong.']);
            }
        }

        // Rename / reparent can create a duplicate too: serialise with creates and re-check (super_admin path).
        $locked = false;
        if ($changesIdentity) {
            $locked = (int) (DB::selectOne('SELECT GET_LOCK(?, 10) AS got', [self::CREATE_LOCK])->got ?? 0) === 1;
            if (! $locked) {
                throw new ApiException('Sistem sedang memproses kategori lain. Silakan coba lagi.', 503);
            }
        }

        try {
            return $this->guardingDuplicates(fn () => DB::transaction(function () use ($category, $attributes, $changesIdentity) {
                $this->lockTree();
                $category = ProductCategory::query()->findOrFail($category->id);

                if (array_key_exists('parent_id', $attributes) && $attributes['parent_id'] !== null) {
                    $parentId = (int) $attributes['parent_id'];
                    $this->assertParentExists($parentId);
                    if ($parentId === $category->id) {
                        throw ValidationException::withMessages(['parent_id' => 'Kategori tidak boleh menjadi induk dirinya sendiri.']);
                    }
                    if (in_array($parentId, $this->descendantIds($category->id), true)) {
                        throw ValidationException::withMessages(['parent_id' => 'Induk tidak boleh berupa turunan kategori ini (siklus hierarki).']);
                    }
                }

                if ($changesIdentity) {
                    $newParent = array_key_exists('parent_id', $attributes) ? ($attributes['parent_id'] === null ? null : (int) $attributes['parent_id']) : ($category->parent_id === null ? null : (int) $category->parent_id);
                    $newName = array_key_exists('name', $attributes) ? $attributes['name'] : (string) $category->name;
                    $moved = $newParent !== ($category->parent_id === null ? null : (int) $category->parent_id) || self::nameKey($newName) !== self::nameKey((string) $category->name);
                    if ($moved) {
                        $this->assertNoSiblingWithName($newParent, $newName, (int) $category->id);
                    }
                }

                $category->update($attributes);

                return $category;
            }));
        } finally {
            if ($locked) {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [self::CREATE_LOCK]);
            }
        }
    }

    /**
     * Delete a category. ON DELETE SET NULL promotes its children to roots; with the DB-level unique key that can
     * collide with an existing root of the same name — surface it as a clear 409 instead of a database error.
     */
    public function delete(ProductCategory $category): void
    {
        try {
            DB::transaction(fn () => $category->delete());
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23000', '1761', '1451'], true) || str_contains($e->getMessage(), 'duplicate entry')) {
                throw new ApiException('Kategori tidak dapat dihapus: sub-kategorinya akan bentrok dengan kategori utama bernama sama. Pindahkan atau ganti nama sub-kategori terlebih dahulu.', 409);
            }
            throw $e;
        }
    }

    public function descendantIds(int $categoryId): array
    {
        $children = ProductCategory::query()->whereNotNull('parent_id')->get(['id', 'parent_id'])->groupBy('parent_id');

        $seen = [$categoryId => true];
        $queue = [$categoryId];
        $out = [];
        while ($queue) {
            $current = array_shift($queue);
            foreach ($children[$current] ?? [] as $child) {
                if (! isset($seen[$child->id])) {
                    $seen[$child->id] = true;
                    $out[] = (int) $child->id;
                    $queue[] = (int) $child->id;
                }
            }
        }

        return $out;
    }

    /** Category id plus all its descendants — used for catalog filtering. @return list<int> */
    public function selfAndDescendantIds(int $categoryId): array
    {
        return [$categoryId, ...$this->descendantIds($categoryId)];
    }

    private function lockTree(): void
    {
        ProductCategory::query()->orderBy('id')->lockForUpdate()->pluck('id');
    }

    private function assertParentExists(?int $parentId): void
    {
        if ($parentId !== null && ! ProductCategory::query()->whereKey($parentId)->exists()) {
            throw ValidationException::withMessages(['parent_id' => 'Kategori induk tidak ditemukan.']);
        }
    }
}
