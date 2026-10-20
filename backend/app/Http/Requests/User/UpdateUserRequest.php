<?php

namespace App\Http\Requests\User;

use App\Http\Requests\BaseFormRequest;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Validation\Rule;

/** Never role_id/agent_id/hierarchy fields — see UserPolicy::update's docblock. */
class UpdateUserRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone'))) {
            $this->merge([
                'phone' => PhoneNormalizer::normalize($this->input('phone')),
            ]);
        }
    }

    public function authorize(): bool
    {
        return true; // UserPolicy::update checked explicitly in the controller.
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'phone' => [
                'sometimes',
                'string',
                'max:20',
                Rule::unique('users', 'phone')->ignore(
                    $this->route('user') instanceof User
                        ? $this->route('user')->getKey()
                        : $this->route('user')
                ),
            ],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive', 'suspended'])],
        ];
    }
}
