<?php

namespace App\Support;

/**
 * The full set of Website Settings keys (Blueprint §Website Settings) —
 * every one lives as its own row in the generic `settings` table (see that
 * migration's own docblock: "site name/logo/favicon, header/footer menus,
 * theme tokens" was always its intended use). This class is only a
 * *validation/default* manifest, never the values themselves — the values
 * always come from the database (WebsiteSettingsService), never a hard-coded
 * config array, so nothing here needs redeploying to change a setting.
 */
class WebsiteSettingKeys
{
    public const GROUP = 'site';

    /**
     * key => ['type' => 'string'|'json', 'default' => mixed]. Underscored,
     * never dotted — Laravel's own validation rule keys AND JSON response
     * path lookups (`$response->json('data.foo.bar')`) both treat a literal
     * "." as nesting, which would silently swallow every dotted key here on
     * both ends of the wire; underscores sidestep that trap entirely.
     */
    public const DEFINITIONS = [
        'site_title' => ['type' => 'string', 'default' => 'Prime Classy Cake & Cookies'],
        'site_slogan' => ['type' => 'string', 'default' => ''],
        'site_logo_media_id' => ['type' => 'string', 'default' => null],
        'site_favicon_media_id' => ['type' => 'string', 'default' => null],
        'site_contact_email' => ['type' => 'string', 'default' => ''],
        'site_contact_phone' => ['type' => 'string', 'default' => ''],
        'site_address' => ['type' => 'string', 'default' => ''],
        'site_header' => ['type' => 'json', 'default' => []],
        'site_footer' => ['type' => 'json', 'default' => []],
        'site_theme' => ['type' => 'json', 'default' => []],
        'site_social' => ['type' => 'json', 'default' => []],
        'site_seo_title' => ['type' => 'string', 'default' => ''],
        'site_seo_description' => ['type' => 'string', 'default' => ''],
        'site_seo_keywords' => ['type' => 'string', 'default' => ''],
        'site_about' => ['type' => 'string', 'default' => ''],
    ];

    public static function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function isValid(string $key): bool
    {
        return array_key_exists($key, self::DEFINITIONS);
    }

    public static function isJson(string $key): bool
    {
        return (self::DEFINITIONS[$key]['type'] ?? 'string') === 'json';
    }

    public static function default(string $key): mixed
    {
        return self::DEFINITIONS[$key]['default'] ?? null;
    }
}
