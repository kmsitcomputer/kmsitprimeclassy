<?php

namespace App\Http\Requests\User;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/** Never role_id/agent_id/hierarchy fields — see UserPolicy::update's docblock. */
class UpdateUserRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // UserPolicy::update checked explicitly in the controller.
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'phone' => ['sometimes', 'string', 'max:20'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive', 'suspended'])],
        ];
    }
}
