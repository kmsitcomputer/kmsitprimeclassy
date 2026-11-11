<?php

namespace App\Http\Requests\Payment;

use App\Http\Requests\BaseFormRequest;

class StoreCodPaymentProofRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // ownership is checked in the controller against the order itself.
    }

    public function rules(): array
    {
        return [
            'proof' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            // REM-004: nominal actually handed over (partial COD collection). Omitted = the full open amount.
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
        ];
    }
}
