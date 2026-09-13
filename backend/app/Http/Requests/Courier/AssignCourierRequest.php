<?php

namespace App\Http\Requests\Courier;

use App\Http\Requests\BaseFormRequest;

class AssignCourierRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // OrderPolicy::assignCourier checked explicitly in the controller.
    }

    public function rules(): array
    {
        return [
            'courier_id' => ['required', 'integer', 'exists:couriers,id'],
        ];
    }
}
