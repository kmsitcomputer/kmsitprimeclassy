<?php

namespace App\Http\Requests\Checkout;

use App\Http\Requests\BaseFormRequest;

/**
 * Same shape as StoreOrderRequest minus payment/delivery-date fields — this
 * only previews totals (OrderService::quote), it never creates anything.
 */
class QuoteCheckoutRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isRole('konsumen', 'agen', 'korsal', 'sales') ?? false;
    }

    public function rules(): array
    {
        return [
            'konsumen_id' => ['required_unless:__actor_role,konsumen', 'nullable', 'integer', 'exists:users,id'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.product_variation_id' => ['nullable', 'integer', 'exists:product_variations,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],

            'address_id' => ['nullable', 'integer', 'exists:konsumen_addresses,id'],

            'recipient_name' => ['required_without:address_id', 'string', 'max:150'],
            'recipient_phone' => ['required_without:address_id', 'string', 'max:20'],
            'address_line' => ['required_without:address_id', 'string', 'max:1000'],
            'village_id' => ['required_without:address_id', 'nullable', 'string', 'exists:villages,id'],
            'latitude' => ['required_without:address_id', 'numeric', 'between:-90,90'],
            'longitude' => ['required_without:address_id', 'numeric', 'between:-180,180'],
            'shipping_method' => ['nullable', 'string', 'in:rajaongkir,openroute'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['__actor_role' => $this->user()?->role?->slug]);
    }

    public function destinationInput(): array
    {
        return [
            'address_id' => $this->integer('address_id') ?: null,
            'recipient_name' => $this->input('recipient_name'),
            'recipient_phone' => $this->input('recipient_phone'),
            'address_line' => $this->input('address_line'),
            'village_id' => $this->input('village_id'),
            'latitude' => $this->input('latitude'),
            'longitude' => $this->input('longitude'),
        ];
    }
}
