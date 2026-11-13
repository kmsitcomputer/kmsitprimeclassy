<?php

namespace App\Models;

use App\Services\Product\CategoryHierarchyService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class ProductCategory extends Model
{
    protected $fillable = ['parent_id', 'name', 'slug', 'is_active', 'sort_order'];

    /**
     * Memoised ONLY once the column is known to exist: the code is deployable BEFORE the migration runs, and a
     * long-lived process (queue worker, test process, schema rebuild) must never keep a stale "absent" answer after
     * the migration has added the NOT NULL column.
     */
    private static ?bool $hasNameKey = null;

    public static function nameKeyColumnExists(): bool
    {
        if (self::$hasNameKey === true) {
            return true;
        }

        $exists = Schema::hasColumn('product_categories', 'name_key');
        self::$hasNameKey = $exists ? true : null;

        return $exists;
    }

    /** For tests / after a schema change inside one process. */
    public static function forgetSchemaMemo(): void
    {
        self::$hasNameKey = null;
    }

    protected static function booted(): void
    {
        // Every Eloquent writer (service, seeders, imports, tests) gets the materialised normalisation key for free;
        // when the column does not exist yet (pre-migration) this is a no-op.
        static::saving(function (self $category) {
            if (self::nameKeyColumnExists() && ($category->isDirty('name') || empty($category->getAttribute('name_key')))) {
                $category->setAttribute('name_key', CategoryHierarchyService::nameKey((string) $category->name));
            }
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }
}
