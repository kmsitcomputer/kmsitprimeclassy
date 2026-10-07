<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\UpdateAgentGoogleAuthConfigRequest;
use App\Services\Auth\GoogleAuthConfigService;
use Illuminate\Http\Request;

/**
 * IMP-001 Step 3 — an Agen's own Google Auth configuration for their branch.
 *
 * Scoping mirrors AgentPaymentMethodController exactly: the agent_id used is
 * ALWAYS the authenticated user's own `agent_id` (an agen's own agent_id is
 * their own id; a branch admin's agent_id points at the branch they belong
 * to), never a value from input — so one branch can never read or write
 * another branch's configuration. Mutations audit the acting user, and the
 * client secret is never returned (has_secret flag only).
 */
class AgentGoogleAuthConfigController extends Controller
{
    public function __construct(private readonly GoogleAuthConfigService $config) {}

    public function show(Request $request)
    {
        return $this->ok($this->config->forAgent($request->user()->agent_id));
    }

    public function update(UpdateAgentGoogleAuthConfigRequest $request)
    {
        $actor = $request->user();

        $settings = $this->config->updateForAgent(
            $actor,
            $actor->agent_id,
            $request->validated(),
            $request->boolean('is_enabled'),
        );

        return $this->ok($settings, __('messages.google.agent_settings_updated'));
    }
}