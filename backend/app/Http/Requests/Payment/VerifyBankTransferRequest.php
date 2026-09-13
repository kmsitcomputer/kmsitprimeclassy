<?php

namespace App\Http\Requests\Payment;

use App\Http\Requests\BaseFormRequest;

class VerifyBankTransferRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // ownership/role is checked in the controller against the order's agent branch.
    }

    public function rules(): array
    {
        return [
            'approved' => ['required', 'boolean'],
            'rejection_reason' => ['required_if:approved,false', 'nullable', 'string', 'max:255'],
        ];
    }
}
