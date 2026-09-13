<?php

namespace App\Http\Requests\Order;

use App\Http\Requests\BaseFormRequest;

class CancelOrderRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // record-level check happens via OrderPolicy in the controller.
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
