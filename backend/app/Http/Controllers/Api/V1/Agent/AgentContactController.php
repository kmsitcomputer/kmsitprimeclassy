<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\StoreAgentProfileRequest;
use App\Http\Requests\Agent\UpdateAgentProfileRequest;
use App\Http\Resources\AgentContactResource;
use App\Http\Resources\AgentDirectoryResource;
use App\Models\AgentProfile;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use App\Support\SafeSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * "Kontak Agen" (Blueprint §Agent Contact) — manages the AgentProfile
 * contact/location record for an existing agen user (created via
 * POST /users). Every write is super_admin-only; the public directory
 * (index) only ever shows active agents through the narrower
 * AgentContactResource, which strips email/status entirely (referral_code
 * IS shown — it's meant to be shared publicly so konsumen can sign up
 * under that agent's network, same purpose as the registration-time
 * referral preview endpoint).
 */
class AgentContactController extends Controller
{
    /**
     * Public — active agents only, contact/location + referral_code fields
     * only. Also the data OpenRoute/shipping distance calc reads from.
     * Reachable on a completely fresh, unmigrated deployment (installer
     * wizard page, bots, monitoring) before `agent_profiles` exists —
     * SafeSchema treats that identically to "no DB connection at all" and
     * this degrades to an empty directory instead of a raw QueryException
     * (see SafeSchema's docblock).
     */
    public function index()
    {
        if (! SafeSchema::hasTable('agent_profiles')) {
            return $this->ok([]);
        }

        $agents = AgentProfile::query()
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->with('user')
            ->get();

        return $this->ok(AgentContactResource::collection($agents)->resolve());
    }

    public function adminIndex()
    {
        Gate::authorize('manage-system-config');

        // User carries no agent-scoping global scope of its own (see
        // BelongsToAgentScope's docblock) — the only global scope it
        // actually has is SoftDeletingScope, so `withoutGlobalScopes()` here
        // was accidentally resurrecting soft-deleted agen accounts into this
        // list. Plain query() correctly excludes them.
        $agents = User::query()
            ->whereHas('role', fn ($q) => $q->where('slug', 'agen'))
            ->with('agentProfile')
            ->orderBy('name')
            ->get();

        return $this->ok(AgentDirectoryResource::collection($agents)->resolve());
    }

    public function store(StoreAgentProfileRequest $request)
    {
        Gate::authorize('manage-system-config');

        $agent = User::query()->findOrFail($request->integer('user_id'));

        if (! $agent->isRole('agen')) {
            throw new ApiException(__('messages.agent.not_an_agent'), 422);
        }

        if ($agent->agentProfile()->exists()) {
            throw new ApiException(__('messages.agent.profile_already_exists'), 422);
        }

        $profile = AgentProfile::create([
            'user_id' => $agent->id,
            'store_name' => $request->string('store_name')->toString(),
            'address' => $request->string('address')->toString(),
            'phone' => $request->input('phone'),
            'latitude' => $request->float('latitude'),
            'longitude' => $request->float('longitude'),
        ]);

        ActivityLogger::log($request->user()->id, $profile, 'agent_profile.created', null, [
            'agent_user_id' => $agent->id, 'actor_role' => $request->user()->role?->slug,
        ]);

        return $this->created(new AgentDirectoryResource($agent->fresh('agentProfile')));
    }

    public function update(UpdateAgentProfileRequest $request, AgentProfile $agentProfile)
    {
        Gate::authorize('manage-system-config');

        $before = $agentProfile->only(['store_name', 'address', 'phone', 'latitude', 'longitude']);
        $agentProfile->update($request->validated());

        ActivityLogger::log($request->user()->id, $agentProfile, 'agent_profile.updated', null, [
            'actor_role' => $request->user()->role?->slug, 'old' => $before, 'new' => $agentProfile->only(array_keys($before)),
        ]);

        $agent = $agentProfile->user;
        $agent->setRelation('agentProfile', $agentProfile->fresh());

        return $this->ok(new AgentDirectoryResource($agent));
    }

    public function destroy(Request $request, AgentProfile $agentProfile)
    {
        Gate::authorize('manage-system-config');

        $agentUserId = $agentProfile->user_id;
        $agentProfile->delete();

        ActivityLogger::log($request->user()->id, $request->user(), 'agent_profile.deleted', null, [
            'agent_user_id' => $agentUserId, 'actor_role' => $request->user()->role?->slug,
        ]);

        return $this->ok(null, __('messages.agent.profile_deleted'));
    }

    public function toggleStatus(Request $request, User $agent)
    {
        Gate::authorize('manage-system-config');

        $agent = User::query()->findOrFail($agent->id);

        if (! $agent->isRole('agen')) {
            throw new ApiException(__('messages.agent.not_an_agent'), 422);
        }

        $newStatus = $agent->status === 'active' ? 'inactive' : 'active';
        $agent->update(['status' => $newStatus]);

        ActivityLogger::log($request->user()->id, $agent, 'agent.status_changed', null, [
            'status' => $newStatus, 'actor_role' => $request->user()->role?->slug,
        ]);

        return $this->ok(new AgentDirectoryResource($agent->load('agentProfile')));
    }
}
