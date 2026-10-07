<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * IMP-001 Step 3 — global (Super Admin) Google Auth settings, single row.
 *
 * `client_secret` is encrypted at rest (`encrypted` cast) and is never
 * readable through any API — GoogleAuthConfigService returns only a masked
 * "secret is set" flag to controllers. Mirrors the per-agen credential
 * tables' pattern (AgentPaymentGatewayConfig, AgentShippingProviderConfig).
 */
class GoogleAuthSetting extends Model
{
    public const SINGLE_ROW_ID = 1;

    protected $fillable = [
        'is_enabled',
        'client_id',
        'client_secret',
        'redirect_uri',
        'frontend_url',
    ];

    protected $hidden = ['client_secret'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'client_secret' => 'encrypted',
        ];
    }

    /**
     * The single global settings row, created on demand.
     *
     * The canonical id is SINGLE_ROW_ID (id=1), which the migration seeds;
     * this method stays id-agnostic so a database rebuilt without that seed
     * (for example an older migration snapshot or a row inserted before the
     * seed existed) still resolves to exactly one row: first it tries the
     * canonical id, falls back to "any existing row", and finally creates a
     * new one. In the fallback cases it regenerates the canonical alias as a
     * best effort so callers keyed on id=1 keep working.
     */
    public static function singleton(): self
    {
        return once(function () {
            $row = static::query()->whereKey(self::SINGLE_ROW_ID)->first()
                ?? static::query()->orderBy('id')->first()
                ?? new static(['is_enabled' => false]);

            if (! $row->exists) {
                $row->save();
            }

            if ($row->getKey() !== self::SINGLE_ROW_ID) {
                static::query()
                    ->whereKey($row->getKey())
                    ->update(['id' => self::SINGLE_ROW_ID]);
                $row->setRawAttributes(
                    array_merge($row->getRawOriginal(), ['id' => self::SINGLE_ROW_ID]),
                    true,
                );
            }

            return $row;
        });
    }
}