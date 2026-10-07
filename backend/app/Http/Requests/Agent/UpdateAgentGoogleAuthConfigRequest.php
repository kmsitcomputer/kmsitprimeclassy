<?php

namespace App\Http\Requests\Agent;

use App\Http\Requests\BaseFormRequest;

/**
 * IMP-001 Step 3 — an Agen's own Google Auth configuration. Same secret
 * semantics as the Super Admin form: blank keeps the stored secret,
 * `clear_secret` removes it explicitly. The controller pins agent_id to the
 * authenticated user's own branch — never from input.
 */
class UpdateAgentGoogleAuthConfigRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isRole('agen', 'admin') ?? false;
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