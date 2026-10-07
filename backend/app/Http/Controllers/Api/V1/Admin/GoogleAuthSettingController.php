<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateGoogleAuthSettingsRequest;
use App\Services\Auth\GoogleAuthConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * IMP-001 Step 3 — Super Admin management of the global Google Auth
 * configuration (enable/disable, Client ID, Client Secret, callback/redirect
 * info). The dashboard path is the normal management surface; .env remains a
 * bootstrap/fallback only. The secret is never returned — see
 * GoogleAuthConfigService::forSuperAdmin (has_secret flag only).
 */
class GoogleAuthSettingController extends Controller
{
    public function __construct(private readonly GoogleAuthConfigService $config) {}

    public function show(Request $request)
    {
        Gate::authorize('manage-system-config');

        return $this->ok($this->config->forSuperAdmin());
    }

    public function update(UpdateGoogleAuthSettingsRequest $request)
    {
        Gate::authorize('manage-system-config');

        $settings = $this->config->updateGlobal(
            $request->user(),
            $request->validated(),
            $request->boolean('is_enabled'),
        );

        return $this->ok($settings, __('messages.google.settings_updated'));
    }
}