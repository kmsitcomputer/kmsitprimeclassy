<?php

namespace App\Http\Requests\Install;

use App\Http\Requests\BaseFormRequest;

/**
 * Only reachable pre-install (see EnsureNotInstalled) — creates the very
 * first account, always super_admin. No `unique:users,email` rule here on
 * purpose: this request is validated BEFORE InstallController::run() gets
 * a chance to run migrations, so the `users` table may not exist yet at
 * validation time — uniqueness is instead guaranteed by
 * InstallController::hasSuperAdmin() (this is the first-ever account, so
 * there is nothing to collide with regardless).
 */
class CreateSuperAdminRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
