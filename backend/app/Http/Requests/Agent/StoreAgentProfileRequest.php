<?php

namespace App\Http\Requests\Agent;

use App\Http\Requests\BaseFormRequest;

class StoreAgentProfileRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // super_admin-only, checked via role middleware + Gate in the controller.
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'store_name' => ['required', 'string', 'max:150'],
            'address' => ['required', 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
