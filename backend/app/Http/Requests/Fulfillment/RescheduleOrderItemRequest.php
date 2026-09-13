<?php

namespace App\Http\Requests\Fulfillment;

use App\Http\Requests\BaseFormRequest;

class RescheduleOrderItemRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // branch ownership is checked in the controller via OrderPolicy::updateStatus.
    }

    public function rules(): array
    {
        return [
            'requested_delivery_date' => ['required', 'date', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'max:255'],
            // Optional: move only part of the line's quantity onto the new date, splitting
            // it into a new OrderItem — see OrderFulfillmentService::rescheduleItemDeliveryDate.
            'quantity' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
