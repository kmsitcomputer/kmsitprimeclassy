<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\UpdateAgentPaymentGatewayConfigRequest;
use App\Models\AgentPaymentGatewayConfig;
use App\Models\AgentPaymentMethodSetting;
use App\Models\PaymentMethod;
use App\Services\Logging\ActivityLogger;
use Illuminate\Http\Request;

/**
 * Payment method settings (cod/manual) for one Agen's own branch —
 * reachable by the Agen themselves AND by that branch's own Admin (never
 * another branch's), same "own agent_id, never own id" scoping pattern as
 * StockController::resolveViewedAgentId(): an agen's own agent_id equals
 * their own id, while an admin's agent_id points at the branch they belong
 * to — so using `$actor->agent_id` (never `$actor->id`) as the scoping key
 * resolves correctly for both roles without an extra branch in this
 * controller. Every mutation still logs the actual acting user (agen or
 * admin) as the audit-log causer, never the resolved agent id.
 *   - on/off override (never widens what super_admin's global
 *     PaymentMethod::is_active already allows, only narrows it further)
 *   - for manual: their own bank account — never shared with or visible to
 *     another agen or to super_admin (config is always $hidden).
 *   External payment gateways are not supported (removed by Owner decision).
 */
class AgentPaymentMethodController extends Controller
{
    public function index(Request $request)
    {
        $agentId = $request->user()->agent_id;

        $methods = PaymentMethod::query()->where('type', '!=', 'gateway')->orderBy('id')->get();
        $overrides = AgentPaymentMethodSetting::query()->where('agent_id', $agentId)->get()->keyBy('payment_method_id');
        $configuredMethodIds = AgentPaymentGatewayConfig::query()
            ->where('agent_id', $agentId)->pluck('payment_method_id')->unique();

        return $this->ok($methods->map(function (PaymentMethod $method) use ($overrides, $configuredMethodIds) {
            $override = $overrides->get($method->id);

            return [
                'id' => $method->id,
                'code' => $method->code,
                'name' => $method->name,
                'type' => $method->type,
                'globally_active' => $method->is_active,
                'is_active' => $override?->is_active ?? true,
                'configured' => $method->type === 'cod' ? null : $configuredMethodIds->contains($method->id),
            ];
        })->values());
    }

    public function toggle(Request $request, PaymentMethod $method)
    {
        // External gateways were removed by Owner decision. Their historical rows stay readable
        // in the DB, but they are never mutable through this surface (PAY-01): a direct-ID call
        // must not re-activate or otherwise rewrite a legacy gateway method.
        if (! in_array($method->type, ['manual', 'cod'], true)) {
            abort(403, __('messages.system.unauthorized_action'));
        }

        $actor = $request->user();
        $agentId = $actor->agent_id;
        $current = AgentPaymentMethodSetting::query()
            ->where('agent_id', $agentId)->where('payment_method_id', $method->id)->first();
        $newState = ! ($current?->is_active ?? true);

        $setting = AgentPaymentMethodSetting::query()->updateOrCreate(
            ['agent_id' => $agentId, 'payment_method_id' => $method->id],
            ['is_active' => $newState],
        );

        ActivityLogger::log($actor->id, $setting, 'agent_payment_method.toggled', null, [
            'payment_method' => $method->code, 'is_active' => $newState, 'actor_role' => $actor->role?->slug,
        ]);

        return $this->ok([
            'id' => $method->id,
            'code' => $method->code,
            'name' => $method->name,
            'type' => $method->type,
            'globally_active' => $method->is_active,
            'is_active' => $newState,
        ], __('messages.payment.gateway_toggled'));
    }

    public function updateConfig(UpdateAgentPaymentGatewayConfigRequest $request, PaymentMethod $method)
    {
        if ($method->type !== 'manual') {
            abort(403, __('messages.system.unauthorized_action'));
        }

        $actor = $request->user();
        $agentId = $actor->agent_id;
        $environment = $request->input('environment')
            ?? AgentPaymentMethodSetting::query()->where('agent_id', $agentId)->where('payment_method_id', $method->id)->value('active_environment')
            ?? 'sandbox';

        AgentPaymentGatewayConfig::replaceConfig(
            $agentId,
            $method->id,
            $environment,
            $request->input('config'),
        );

        ActivityLogger::log($actor->id, $method, 'agent_payment_method.config_updated', null, [
            'payment_method' => $method->code,
            'environment' => $environment,
            'actor_role' => $actor->role?->slug,
            // Only field NAMES are logged — never their values.
            'fields' => array_keys($request->input('config')),
        ]);

        return $this->ok(['configured' => true], __('messages.payment.gateway_config_saved'));
    }
}
