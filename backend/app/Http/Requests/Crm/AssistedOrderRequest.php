<?php

namespace App\Http\Requests\Crm;

use App\Http\Requests\Order\StoreOrderRequest;

/**
 * Assisted Order from a CRM customer. Same item/destination/payment rules as checkout, minus everything
 * that only makes sense for an account buyer: no `konsumen_id`, no address book, no Sub stock source.
 * Who may place it is decided server-side by CrmAccess::canPlaceOrder, never by the body.
 */
class AssistedOrderRequest extends StoreOrderRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isRole('agen', 'korsal', 'sales', 'sales-kurir-sub') ?? false;
    }

    public function rules(): array
    {
        $rules = parent::rules();

        unset($rules['konsumen_id'], $rules['address_id'], $rules['stock_source'], $rules['sub_location_id']);

        // Without an address book every destination field is mandatory.
        foreach (['recipient_name', 'recipient_phone', 'address_line', 'village_id', 'latitude', 'longitude'] as $field) {
            $rules[$field] = array_values(array_filter(
                $rules[$field],
                fn ($rule) => $rule !== 'required_without:address_id' && $rule !== 'nullable',
            ));
            array_unshift($rules[$field], 'required');
        }

        return $rules;
    }
}
