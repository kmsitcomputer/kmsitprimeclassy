<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateWebsiteSettingsRequest;
use App\Services\Setting\WebsiteSettingsService;
use Illuminate\Support\Facades\Gate;

/**
 * Website Settings (Blueprint §Website Settings) — one super_admin-only
 * write surface, one public cached read the storefront hits on every load.
 * Every value lives in the `settings` table (WebsiteSettingsService); this
 * controller never hard-codes a single configuration value itself.
 */
class WebsiteSettingController extends Controller
{
    public function __construct(private readonly WebsiteSettingsService $settings) {}

    /** Public — cached, only ever the is_public-safe fields (see WebsiteSettingKeys, none are secret). */
    public function show()
    {
        return $this->ok($this->settings->publicSettings());
    }

    public function adminShow()
    {
        Gate::authorize('manage-system-config');

        return $this->ok($this->settings->all());
    }

    public function update(UpdateWebsiteSettingsRequest $request)
    {
        Gate::authorize('manage-system-config');

        $settings = $this->settings->update($request->validated(), $request->user());

        return $this->ok($settings, __('messages.settings.updated'));
    }
}
