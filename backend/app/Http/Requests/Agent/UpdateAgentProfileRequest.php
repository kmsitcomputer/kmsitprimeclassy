<?php

namespace App\Http\Requests\Agent;

use App\Http\Requests\BaseFormRequest;

class UpdateAgentProfileRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_name' => ['sometimes', 'string', 'max:150'],
            'address' => ['sometimes', 'string'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
        ];
    }
}
