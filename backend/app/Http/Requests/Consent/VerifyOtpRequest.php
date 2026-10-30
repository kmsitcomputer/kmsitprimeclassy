<?php

namespace App\Http\Requests\Consent;

use App\Http\Requests\BaseFormRequest;

class VerifyOtpRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isRole('sales', 'sales-kurir-sub', 'korsal', 'agen') ?? false;
    }

    public function rules(): array
    {
        return ['code' => ['required', 'digits:6']];
    }
}
