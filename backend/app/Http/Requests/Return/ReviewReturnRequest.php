<?php

namespace App\Http\Requests\Return;

use App\Http\Requests\BaseFormRequest;

class ReviewReturnRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isRole('super_admin', 'agen', 'admin') ?? false;
    }

    public function rules(): array
    {
        return [
            'approved' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
