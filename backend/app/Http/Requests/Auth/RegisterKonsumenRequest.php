<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseFormRequest;
use App\Support\PhoneNormalizer;

/** Public registration is only ever for the konsumen role — see Blueprint §Authentication. */
class RegisterKonsumenRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone'))) {
            $this->merge([
                'phone' => PhoneNormalizer::normalize($this->input('phone')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            // Optional — a konsumen may register with no referral at all and
            // pick an agent later (see AuthController::register and the
            // "Lihat Toko Agen" fallback on the registration page).
            'referral_code' => ['nullable', 'string', 'max:20'],
        ];
    }
}
