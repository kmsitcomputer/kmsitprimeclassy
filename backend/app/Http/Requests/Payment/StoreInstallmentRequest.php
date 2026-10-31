<?php

namespace App\Http\Requests\Payment;

use App\Http\Requests\BaseFormRequest;

/** Improvement 05/06/08. Amount is validated against the real outstanding balance in PaymentService. */
class StoreInstallmentRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // order ownership / payOnBehalf scope is enforced in the controller.
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'channel' => ['sometimes', 'in:transfer,cash'],
            'received_by_user_id' => ['nullable', 'integer'],
            'received_at' => ['nullable', 'date', 'before_or_equal:now'],
            // Cash needs a receipt reference or a photo of the receipt (enforced in the controller).
            'receipt_reference' => ['nullable', 'string', 'max:120'],
            'receipt_photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
