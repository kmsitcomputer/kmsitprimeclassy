<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseFormRequest;

/** Super Admin only (also enforced by the `role:super_admin` route group). Secrets are write-only. */
class UpdateWhatsAppSettingsRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isRole('super_admin') ?? false;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'base_url' => ['sometimes', 'nullable', 'string', 'max:255'],
            'username' => ['sometimes', 'nullable', 'string', 'max:100'],
            // Optional: an absent/empty password KEEPS the stored one; it is never echoed back.
            'password' => ['sometimes', 'nullable', 'string', 'max:200'],
            'device_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'timeout' => ['sometimes', 'integer', 'between:2,30'],
            'max_attempts' => ['sometimes', 'integer', 'between:1,5'],
            'backoff_seconds' => ['sometimes', 'integer', 'between:10,3600'],
            'limits' => ['sometimes', 'array'],
            'limits.per_recipient_per_hour' => ['sometimes', 'integer', 'between:1,60'],
            'limits.global_per_minute' => ['sometimes', 'integer', 'between:1,120'],
            'test_numbers' => ['sometimes', 'array', 'max:5'],
            'test_numbers.*' => ['string', 'max:20'],
            'otp' => ['sometimes', 'array'],
            'otp.enabled' => ['sometimes', 'boolean'],
            'otp.ttl_minutes' => ['sometimes', 'integer', 'between:1,30'],
            'otp.max_attempts' => ['sometimes', 'integer', 'between:1,10'],
            'otp.cooldown_seconds' => ['sometimes', 'integer', 'between:0,600'],
            'otp.limit' => ['sometimes', 'integer', 'between:1,10'],
            'otp.limit_window_minutes' => ['sometimes', 'integer', 'between:1,60'],
            'events' => ['sometimes', 'array'],
            'events.*' => ['boolean'],
        ];
    }
}
