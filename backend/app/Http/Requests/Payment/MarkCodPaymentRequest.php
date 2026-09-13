<?php

namespace App\Http\Requests\Payment;

use App\Http\Requests\BaseFormRequest;

class MarkCodPaymentRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // role/branch ownership is checked in the controller.
    }

    public function rules(): array
    {
        return [
            'paid' => ['required', 'boolean'],
        ];
    }
}
