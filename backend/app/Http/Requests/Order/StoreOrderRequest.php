<?php

namespace App\Http\Requests\Order;

use App\Http\Requests\BaseFormRequest;

/**
 * Deliberately does NOT accept price, fee, stock, or total fields from the
 * client — only identifiers and quantities. OrderService recomputes every
 * amount from the database. See Blueprint §Security and §Order.
 *
 * konsumen_id is only meaningful when the actor is agen/korsal/sales.
 * Omitted, they're buying for themselves (self-purchase — Blueprint: account
 * role never changes just because they check out). Supplied, they're
 * placing an order on behalf of that konsumen in their own network
 * (Blueprint: "AGEN/KORSAL/SALES dapat membuat order"). Whether that
 * konsumen actually belongs to the actor's network — and whether a
 * self-purchase is even allowed for this actor's role — is authorized in
 * the controller (OrderPolicy::create), not here — this only validates shape.
 */
class StoreOrderRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isRole('konsumen', 'agen', 'korsal', 'sales') ?? false;
    }

    public function rules(): array
    {
        return [
            'konsumen_id' => ['nullable', 'integer', 'exists:users,id'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.product_variation_id' => ['nullable', 'integer', 'exists:product_variations,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],

            'address_id' => ['nullable', 'integer', 'exists:konsumen_addresses,id'],

            // Required only when address_id is not supplied.
            'recipient_name' => ['required_without:address_id', 'string', 'max:150'],
            'recipient_phone' => ['required_without:address_id', 'string', 'max:20'],
            'address_line' => ['required_without:address_id', 'string', 'max:1000'],
            'village_id' => ['required_without:address_id', 'nullable', 'string', 'exists:villages,id'],
            'latitude' => ['required_without:address_id', 'numeric', 'between:-90,90'],
            'longitude' => ['required_without:address_id', 'numeric', 'between:-180,180'],

            'payment_method_code' => ['required', 'string', 'exists:payment_methods,code'],
            // DP / down payment only: the partial nominal the konsumen pays
            // first. Its real bounds (0 < nominal < server-computed total) are
            // enforced in OrderService against the recomputed total — this is
            // shape validation only.
            'dp_amount' => ['nullable', 'numeric', 'min:1'],
            'delivery_date' => ['nullable', 'date', 'after_or_equal:today'],
            // "Ekspedisi" (rajaongkir) or "Kurir Online" (openroute) — only
            // meaningful when more than one shipping provider is active;
            // OrderService validates it's actually active, not just a known code.
            'shipping_method' => ['nullable', 'string', 'in:rajaongkir,openroute'],
            // Which courier/service under "Ekspedisi" (RajaOngkir) the
            // konsumen picked — see QuoteCheckoutRequest for the same field.
            'courier' => ['nullable', 'required_with:service', 'string', 'max:50'],
            'service' => ['nullable', 'required_with:courier', 'string', 'max:50'],
        ];
    }

    public function selectedCourierOption(): ?array
    {
        return $this->filled('courier') && $this->filled('service')
            ? ['courier' => $this->string('courier')->toString(), 'service' => $this->string('service')->toString()]
            : null;
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
