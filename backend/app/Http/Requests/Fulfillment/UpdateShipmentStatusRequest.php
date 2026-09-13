<?php

namespace App\Http\Requests\Fulfillment;

use App\Http\Requests\BaseFormRequest;

class UpdateShipmentStatusRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // record-level check happens via ShipmentPolicy::updateStatus in the controller.
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:dikirim,terkirim'],
            // Shape only — WHO must actually supply one (kurir, marking
            // 'terkirim') is a business rule enforced in CourierService.
            'proof' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }
}
