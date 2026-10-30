<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseFormRequest;
use App\Services\Mail\SmtpSettings;
use Illuminate\Validation\Rule;

/** Super Admin only (also enforced by the `role:super_admin` route group). The password is write-only. */
class UpdateSmtpSettingsRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isRole('super_admin') ?? false;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            // A hostname or IP only: no scheme, path or credentials.
            'host' => ['sometimes', 'string', 'max:190', 'regex:/^[A-Za-z0-9]([A-Za-z0-9.\-]*[A-Za-z0-9])?$/'],
            'port' => ['sometimes', 'integer', Rule::in([25, 465, 587, 2525, 1025, 2465])],
            'encryption' => ['sometimes', Rule::in(SmtpSettings::ENCRYPTIONS)],
            'username' => ['sometimes', 'nullable', 'string', 'max:190'],
            // Optional: absent/empty KEEPS the stored one; it is never echoed back.
            'password' => ['sometimes', 'nullable', 'string', 'max:300'],
            'clear_password' => ['sometimes', 'boolean'],
            'from_address' => ['sometimes', 'email:rfc', 'max:190'],
            'from_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'timeout' => ['sometimes', 'integer', 'between:2,60'],
        ];
    }
}
