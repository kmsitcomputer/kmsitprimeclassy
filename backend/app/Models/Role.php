<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    /** R-01: the 10th business role (formerly `sales-kurir`). Same roles row, same id. */
    public const SALES_KURIR_SUB = 'sales-kurir-sub';

    /** Pre-R-01 slug. Recognised only so a deploy can never strand users before the migration renames the row. */
    public const LEGACY_SALES_KURIR = 'sales-kurir';

    protected $fillable = ['slug', 'name'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Maps any legacy slug onto its canonical replacement; every other slug is returned unchanged. */
    public static function canonicalSlug(?string $slug): ?string
    {
        return $slug === self::LEGACY_SALES_KURIR ? self::SALES_KURIR_SUB : $slug;
    }

    /**
     * Every stored spelling of a canonical slug — for SQL `IN (...)` lists that must
     * keep matching during the migration window.
     *
     * @return list<string>
     */
    public static function slugsFor(string $canonical): array
    {
        return $canonical === self::SALES_KURIR_SUB ? [self::SALES_KURIR_SUB, self::LEGACY_SALES_KURIR] : [$canonical];
    }
}
