<?php

namespace App\Http\Requests\Fulfillment;

use App\Http\Requests\BaseFormRequest;

class AdjustOrderItemQuantityRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // branch ownership is checked in the controller via OrderPolicy::updateStatus.
    }

    public function rules(): array
    {
        return [
            'fulfilled_quantity' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:255'],
            'additional_payment_method' => ['sometimes', 'string', 'in:transfer,cod'],
        ];
    }
}
