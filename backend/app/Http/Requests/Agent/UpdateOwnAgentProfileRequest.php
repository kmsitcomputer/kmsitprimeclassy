<?php

namespace App\Http\Requests\Agent;

use App\Http\Requests\BaseFormRequest;

/** Self-service counterpart of UpdateAgentProfileRequest — same shape, no user_id (always the acting agen). */
class UpdateOwnAgentProfileRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // role:agen middleware gates this; the controller never trusts anything but $request->user() for WHOSE profile.
    }

    public function rules(): array
    {
        return [
            'store_name' => ['required', 'string', 'max:150'],
            'address' => ['required', 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
