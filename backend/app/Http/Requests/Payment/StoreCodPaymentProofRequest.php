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
            'proof' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }
}
