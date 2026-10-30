<?php

namespace App\Http\Requests\Consent;

use App\Http\Requests\BaseFormRequest;

/** Reason body shared by withdraw / dispute / reinstate; authority is checked in the service. */
class ConsentReasonRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }
}
