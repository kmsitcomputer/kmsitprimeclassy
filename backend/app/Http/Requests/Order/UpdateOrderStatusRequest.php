<?php

namespace App\Http\Requests\Order;

use App\Http\Requests\BaseFormRequest;

class UpdateOrderStatusRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // record-level check happens via OrderPolicy::updateStatus in the controller.
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:diproses,dikirim,terkirim'],
        ];
    }
}
