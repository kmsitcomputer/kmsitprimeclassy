<?php

namespace App\Http\Requests\Crm;

use App\Http\Requests\BaseFormRequest;
use App\Models\CrmCustomer;
use Illuminate\Validation\Rule;

/** Create (all required fields present) and update (`sometimes`) share one rule set. */
class CrmCustomerRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isRole('sales', 'sales-kurir-sub', 'korsal') ?? false;
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'customer_type' => ['nullable', 'string', 'max:50'],
            'relation' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:1000'],
            'prospect_percent' => ['nullable', 'integer', Rule::in(CrmCustomer::PROSPECT_PERCENTS)],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
