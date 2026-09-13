<?php

namespace App\Http\Requests\Fee;

use App\Http\Requests\BaseFormRequest;

class SetFeeRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // ProductPolicy::manage checked explicitly in the controller.
    }

    public function rules(): array
    {
        return [
            'agent_fee' => ['required', 'numeric', 'min:0'],
            'sales_fee' => ['required', 'numeric', 'min:0'],
            'courier_fee' => ['required', 'numeric', 'min:0'],
        ];
    }
}
