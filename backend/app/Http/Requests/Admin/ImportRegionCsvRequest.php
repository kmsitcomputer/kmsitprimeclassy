<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseFormRequest;

class ImportRegionCsvRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isRole('super_admin') ?? false;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:20480'],
        ];
    }
}
