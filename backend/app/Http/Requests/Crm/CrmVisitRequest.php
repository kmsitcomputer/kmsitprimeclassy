<?php

namespace App\Http\Requests\Crm;

use App\Http\Requests\BaseFormRequest;
use App\Models\CrmCustomer;
use App\Models\CrmVisit;
use Illuminate\Validation\Rule;

/** `status` accepts only N,1..7 — FU/D are rejected until the Owner defines them (CI-07). */
class CrmVisitRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isRole('sales', 'sales-kurir-sub', 'korsal') ?? false;
    }

    /**
     * Owner decision 2026-10-30: `D` = Deal, which already IS status `7` — accepted as an input alias and
     * normalized, never stored as a duplicate status. `FU` = Follow Up is NOT a visit status: it is the
     * existing follow-up mechanism (`plan_next_date`), so a `FU` status is refused with a pointer to it.
     */
    protected function prepareForValidation(): void
    {
        $status = $this->input('status');

        if (is_string($status) && strtoupper($status) === 'D') {
            $this->merge(['status' => '7']);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if (is_string($this->input('status')) && strtoupper($this->input('status')) === 'FU') {
                $v->errors()->add('status', __('messages.crm.fu_use_plan_next_date'));
            }
        });
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'visit_date' => [$presence, 'date'],
            'status' => [$presence, 'string', Rule::in(CrmVisit::STATUSES)],
            'prospect_percent' => ['nullable', 'integer', Rule::in(CrmCustomer::PROSPECT_PERCENTS)],
            'note' => ['nullable', 'string', 'max:2000'],
            'plan_next_date' => ['nullable', 'date', 'after_or_equal:visit_date'],
        ];
    }
}
