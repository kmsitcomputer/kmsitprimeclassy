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
 * An Agen's own settings for every payment method (cod/manual/gateway),
 * scoped to their own branch only:
 *   - on/off override (never widens what super_admin's global
 *     PaymentMethod::is_active already allows, only narrows it further)
 *   - for manual/gateway: their own credentials (bank account, or
 *     Xendit/Tripay/Stripe API keys) — never shared with or visible to
 *     another agen or to super_admin (config is always $hidden).
 *   - for gateway: which environment (sandbox/production) is active.
 */
class AgentPaymentMethodController extends Controller
{
    public function index(Request $request)
    {
        $agentId = $request->user()->id;

        $methods = PaymentMethod::query()->orderBy('id')->get();
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
                'active_environment' => $method->type === 'gateway' ? ($override?->active_environment ?? 'sandbox') : null,
                'configured' => $method->type === 'cod' ? null : $configuredMethodIds->contains($method->id),
            ];
        })->values());
    }

    public function toggle(Request $request, PaymentMethod $method)
    {
        $agentId = $request->user()->id;
        $current = AgentPaymentMethodSetting::query()
            ->where('agent_id', $agentId)->where('payment_method_id', $method->id)->first();
        $newState = ! ($current?->is_active ?? true);

        $setting = AgentPaymentMethodSetting::query()->updateOrCreate(
            ['agent_id' => $agentId, 'payment_method_id' => $method->id],
            ['is_active' => $newState],
        );

        ActivityLogger::log($agentId, $setting, 'agent_payment_method.toggled', null, [
            'payment_method' => $method->code, 'is_active' => $newState,
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

    public function setEnvironment(Request $request, PaymentMethod $method)
    {
        if ($method->type !== 'gateway') {
            abort(403, __('messages.system.unauthorized_action'));
        }

        $validated = $request->validate(['environment' => ['required', 'in:sandbox,production']]);
        $agentId = $request->user()->id;

        $setting = AgentPaymentMethodSetting::query()->updateOrCreate(
            ['agent_id' => $agentId, 'payment_method_id' => $method->id],
            ['active_environment' => $validated['environment']],
        );

        ActivityLogger::log($agentId, $setting, 'agent_payment_method.environment_changed', null, [
            'payment_method' => $method->code, 'active_environment' => $validated['environment'],
        ]);

        return $this->ok(null, __('messages.payment.gateway_environment_updated'));
    }

    public function updateConfig(UpdateAgentPaymentGatewayConfigRequest $request, PaymentMethod $method)
    {
        if (! in_array($method->type, ['manual', 'gateway'], true)) {
            abort(403, __('messages.system.unauthorized_action'));
        }

        $agentId = $request->user()->id;
        $environment = $request->input('environment')
            ?? AgentPaymentMethodSetting::query()->where('agent_id', $agentId)->where('payment_method_id', $method->id)->value('active_environment')
            ?? 'sandbox';

        AgentPaymentGatewayConfig::query()->updateOrCreate(
            ['agent_id' => $agentId, 'payment_method_id' => $method->id, 'environment' => $environment],
            ['config' => $request->input('config')],
        );

        ActivityLogger::log($agentId, $method, 'agent_payment_method.config_updated', null, [
            'payment_method' => $method->code,
            'environment' => $environment,
            // Only field NAMES are logged — never their values.
            'fields' => array_keys($request->input('config')),
        ]);

        return $this->ok(['configured' => true], __('messages.payment.gateway_config_saved'));
    }
}
