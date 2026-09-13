<?php

namespace App\Http\Requests\Install;

use App\Http\Requests\BaseFormRequest;

/** Used for both the "Test Connection" and "Save & Continue" installer steps — same shape either way. */
class SaveDatabaseConfigRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'database' => ['required', 'string', 'max:64'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
        ];
    }
}
