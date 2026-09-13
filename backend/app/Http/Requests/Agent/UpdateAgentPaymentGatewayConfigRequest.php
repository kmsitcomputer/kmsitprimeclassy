<?php

namespace App\Http\Requests\Agent;

use App\Http\Requests\BaseFormRequest;
use App\Models\PaymentMethod;
use App\Support\PaymentGatewayFields;

/**
 * An agen's own credentials for a manual/gateway payment method — mirrors
 * the old (now removed) Admin\UpdatePaymentGatewayConfigRequest, but scoped
 * to the authenticated agen (never another agen's row — the controller
 * always writes with agent_id = auth()->id(), never from input).
 */
class UpdateAgentPaymentGatewayConfigRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isRole('agen') ?? false;
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
