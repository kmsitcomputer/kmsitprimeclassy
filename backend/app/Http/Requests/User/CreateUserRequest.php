<?php

namespace App\Http\Requests\User;

use App\Http\Requests\BaseFormRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\Rule;

/** Actor-aware validation; policy and service independently enforce the same creation matrix. */
class CreateUserRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [User::class, $this->targetRole()]) ?? false;
    }

    public function rules(): array
    {
        return [
            'role' => ['required', 'string', 'in:agen,korsal,sales,admin,keuangan,kurir'],
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],

            'agent_id' => ['nullable', 'integer', Rule::in([$this->user()?->agent_id])],
            'korsal_id' => [
                Rule::requiredIf(fn () => $this->targetRole() === 'sales' && $this->user()?->isRole('agen')),
                'nullable', 'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q
                    ->where('agent_id', $this->user()?->agent_id)
                    ->where('role_id', Role::query()->where('slug', 'korsal')->value('id'))
                    ->whereNull('deleted_at')),
                ...($this->user()?->isRole('korsal') ? [Rule::in([$this->user()->id])] : []),
            ],
        ];
    }

    public function targetRole(): string
    {
        return $this->string('role')->toString();
    }

    public function extraData(): array
    {
        return [
            'agent_id' => $this->integer('agent_id') ?: null,
            'korsal_id' => $this->integer('korsal_id') ?: null,
        ];
    }
}
