<?php

namespace App\Http\Requests\Agent;

use App\Http\Requests\BaseFormRequest;
use App\Models\PaymentMethod;
use App\Support\PaymentGatewayFields;

/**
 * An agen's own credentials for a manual/gateway payment method — mirrors
 * the old (now removed) Admin\UpdatePaymentGatewayConfigRequest, but scoped
 * to the authenticated agen's own branch (never another agent's row — the
 * controller always writes with agent_id = auth()->agent_id, never from
 * input). Also reachable by that branch's own admin, same as agen (their
 * agent_id resolves to the same branch — see AgentPaymentMethodController's
 * docblock).
 */
class UpdateAgentPaymentGatewayConfigRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isRole('agen', 'admin') ?? false;
    }

    public function rules(): array
    {
        /** @var PaymentMethod $method */
        $method = $this->route('method');

        return [
            'environment' => ['sometimes', 'in:sandbox,production'],
            'config' => ['required', 'array'],
            ...PaymentGatewayFields::rulesFor($method->code),
        ];
    }
}
