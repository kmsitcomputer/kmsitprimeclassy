<?php

namespace App\Services\Setting;

use App\Models\Media;
use App\Models\Setting;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use App\Support\SafeSchema;
use App\Support\WebsiteSettingKeys;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The single source of truth for Website Settings is always the `settings`
 * table — the cache here is purely a read accelerator for the public,
 * unauthenticated storefront read (every visitor's first request), and is
 * invalidated synchronously on every write, so a super_admin's change is
 * visible immediately, never stale for up to some TTL (Blueprint: "database
 * tetap menjadi source of truth").
 */
class WebsiteSettingsService
{
    private const CACHE_KEY = 'website_settings.public';

    private const CACHE_TTL = 3600;

    /** Every defined key, resolved with its default when unset — what the admin dashboard edits. */
    public function all(): array
    {
        $rows = Setting::query()->where('group', WebsiteSettingKeys::GROUP)->pluck('value', 'key');

        $result = [];
        foreach (WebsiteSettingKeys::keys() as $key) {
            $result[$key] = $this->decode($key, $rows[$key] ?? null);
        }

        return $this->attachMediaUrls($result);
    }

    /**
     * The cached, public-safe read — every key here is already `is_public`
     * (Website Settings has no secret values by design), so this is safe to
     * expose on an unauthenticated endpoint the storefront hits on every load.
     *
     * The SPA's chrome (header/logo) calls this unconditionally on every
     * page load, including the installer wizard's own pages — before a
     * database connection even exists yet, on a fresh deployment. Falling
     * through to a real query in that state doesn't just fail cleanly: it
     * can hang for the driver's full connection timeout against
     * still-placeholder DB credentials before failing. SafeSchema treats
     * "no table" and "no connection at all" identically, so this returns
     * plain defaults instantly instead, exactly like every other pre-install
     * check in this app (see SafeSchema's own docblock).
     */
    public function publicSettings(): array
    {
        if (! SafeSchema::hasTable('settings')) {
            return $this->attachMediaUrls(
                collect(WebsiteSettingKeys::keys())->mapWithKeys(fn ($key) => [$key => WebsiteSettingKeys::default($key)])->all()
            );
        }

        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => $this->all());
    }

    /** @param  array<string, mixed>  $data  key => new value, only the keys being changed */
    public function update(array $data, User $actor): array
    {
        $before = $this->all();

        DB::transaction(function () use ($data) {
            foreach ($data as $key => $value) {
                if (! WebsiteSettingKeys::isValid($key)) {
                    continue;
                }

                Setting::query()->updateOrCreate(
                    ['key' => $key],
                    [
                        'value' => WebsiteSettingKeys::isJson($key) ? json_encode($value) : (string) $value,
                        'group' => WebsiteSettingKeys::GROUP,
                        'is_public' => true,
                    ]
                );
            }
        });

        Cache::forget(self::CACHE_KEY);
        $after = $this->all();

        // No single "settings" record naturally owns a bulk key/value change —
        // the actor themselves is the subject, same convention as
        // RegionImportExportController's 'region.csv_imported'.
        ActivityLogger::log($actor->id, $actor, 'settings.changed', null, [
            'actor_role' => $actor->role?->slug,
            'keys' => array_keys($data),
            'old' => array_intersect_key($before, $data),
            'new' => array_intersect_key($after, $data),
        ]);

        return $after;
    }

    private function decode(string $key, ?string $raw): mixed
    {
        if ($raw === null) {
            return WebsiteSettingKeys::default($key);
        }

        return WebsiteSettingKeys::isJson($key) ? (json_decode($raw, true) ?? WebsiteSettingKeys::default($key)) : $raw;
    }

    /** Resolves logo/favicon media ids into actual URLs so the frontend never has to. */
    private function attachMediaUrls(array $settings): array
    {
        foreach (['site_logo_media_id' => 'logo_url', 'site_favicon_media_id' => 'favicon_url'] as $idKey => $urlKey) {
            $mediaId = $settings[$idKey] ?? null;
            $settings[$urlKey] = $mediaId ? Media::find($mediaId)?->url() : null;
        }

        return $settings;
    }
}
