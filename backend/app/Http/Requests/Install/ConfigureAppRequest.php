<?php

namespace App\Http\Requests\Install;

use App\Http\Requests\BaseFormRequest;

class ConfigureAppRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'app_name' => ['required', 'string', 'max:100'],
            'app_url' => ['required', 'url', 'max:255'],
            'frontend_url' => ['required', 'url', 'max:255'],
        ];
    }
}
