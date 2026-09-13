<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\UpdateOwnAgentProfileRequest;
use App\Models\AgentProfile;
use App\Services\Logging\ActivityLogger;
use Illuminate\Http\Request;

/**
 * An agen's own "Kontak Agen" (store_name/address/phone/lat-lng) — the
 * self-service counterpart of AgentContactController's super_admin-only
 * management surface. Always operates on `$request->user()->id`; never
 * accepts a user_id/agent_id from the request body, so an agen can only
 * ever create or edit its own record, never another agent's.
 */
class AgentStoreProfileController extends Controller
{
    public function show(Request $request)
    {
        $profile = AgentProfile::query()->where('user_id', $request->user()->id)->first();

        return $this->ok($profile ? [
            'store_name' => $profile->store_name,
            'address' => $profile->address,
            'phone' => $profile->phone,
            'latitude' => $profile->latitude,
            'longitude' => $profile->longitude,
        ] : null);
    }

    public function update(UpdateOwnAgentProfileRequest $request)
    {
        $agentId = $request->user()->id;
        $before = AgentProfile::query()->where('user_id', $agentId)->first()?->only(['store_name', 'address', 'phone', 'latitude', 'longitude']);

        $profile = AgentProfile::query()->updateOrCreate(
            ['user_id' => $agentId],
            $request->only('store_name', 'address', 'phone', 'latitude', 'longitude'),
        );

        ActivityLogger::log($agentId, $profile, 'agent_profile.self_updated', null, [
            'old' => $before, 'new' => $profile->only(['store_name', 'address', 'phone', 'latitude', 'longitude']),
        ]);

        return $this->ok([
            'store_name' => $profile->store_name,
            'address' => $profile->address,
            'phone' => $profile->phone,
            'latitude' => $profile->latitude,
            'longitude' => $profile->longitude,
        ], __('messages.agent.profile_updated'));
    }
}
