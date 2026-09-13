<?php

namespace App\Http\Requests\User;

use App\Http\Requests\BaseFormRequest;

class ReassignReferralRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // UserPolicy::update checked explicitly in the controller.
    }

    public function rules(): array
    {
        return [
            'korsal_id' => ['required_without:sales_id', 'integer', 'exists:users,id'],
            'sales_id' => ['required_without:korsal_id', 'integer', 'exists:users,id'],
        ];
    }
}
