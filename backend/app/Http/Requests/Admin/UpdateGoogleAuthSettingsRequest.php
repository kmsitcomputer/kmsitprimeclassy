<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseFormRequest;

/**
 * IMP-001 Step 3 — Super Admin's global Google Auth settings. The secret is
 * optional on edit (blank = keep the stored one), and `clear_secret` is the
 * explicit opt-in to remove it. `client_secret` is a nullable max:255 string
 * — it is never echoed back, only its presence flag.
 */
class UpdateGoogleAuthSettingsRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isRole('super_admin') ?? false;
    }

    public function rules(): array
    {
        return [
            'is_enabled' => ['sometimes', 'boolean'],
            'client_id' => ['nullable', 'string', 'max:191'],
            'client_secret' => ['nullable', 'string', 'max:255'],
            'redirect_uri' => ['nullable', 'url', 'max:255'],
            'frontend_url' => ['nullable', 'url', 'max:255'],
            'clear_secret' => ['sometimes', 'boolean'],
        ];
    }
}