<?php

namespace App\Http\Requests\Fulfillment;

use App\Http\Requests\BaseFormRequest;

/**
 * Package C / SC-03: shape validation for adding a NEW product/variation line to an existing order.
 *
 * Deliberately accepts NO authoritative financial values (price, fee, SKU, subtotal, totals) — the
 * server resolves and snapshots all of them from the database. SC-03 is Agent-stock only, so a
 * client-supplied stock source/location is rejected outright rather than silently ignored.
 *
 * Authorization is NOT done here — the controller enforces OrderPolicy::addLine (Admin-only,
 * same-Agent) before the service runs.
 */
class AddOrderItemRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // branch/role ownership is checked in the controller via OrderPolicy::addLine.
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_variation_id' => ['nullable', 'integer', 'exists:product_variations,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'requested_delivery_date' => ['nullable', 'date', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'max:255'],
            'additional_payment_method' => ['sometimes', 'string', 'in:transfer,cod'],

            // SC-03 does not create a Sub-stock capability — reject any Sub source/location input.
            'stock_source' => ['prohibited'],
            'sub_location_id' => ['prohibited'],
        ];
    }
}
