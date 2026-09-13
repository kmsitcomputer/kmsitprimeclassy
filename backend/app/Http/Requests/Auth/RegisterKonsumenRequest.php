<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseFormRequest;

/** Public registration is only ever for the konsumen role — see Blueprint §Authentication. */
class RegisterKonsumenRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'referral_code' => ['required', 'string', 'max:20'],
        ];
    }
}
