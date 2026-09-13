<?php

namespace App\Http\Requests\Payment;

use App\Http\Requests\BaseFormRequest;

class ConfirmCodPaymentRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // agen/admin/super_admin branch check happens explicitly in the controller.
    }

    public function rules(): array
    {
        return [
            'confirmed' => ['required', 'boolean'],
            'rejection_reason' => ['required_if:confirmed,false', 'nullable', 'string', 'max:255'],
        ];
    }
}
