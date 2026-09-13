<?php

namespace App\Http\Requests\Stock;

use App\Http\Requests\BaseFormRequest;

/**
 * Deliberately does NOT accept a resulting/target quantity from the client —
 * only a signed delta and a reason. The current on_hand value is always read
 * fresh from the database (under a row lock) inside StockService, never
 * trusted from whatever the client last saw on screen.
 */
class AdjustStockRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        // super_admin can browse/oversee stock (StockController::products/variations)
        // but never mutate it — "stok hanya untuk agen dan admin di bawah jaringan agen tersebut."
        return $this->user()?->isRole('agen', 'admin') ?? false;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required_without:product_variation_id', 'nullable', 'integer', 'exists:products,id'],
            'product_variation_id' => ['required_without:product_id', 'nullable', 'integer', 'exists:product_variations,id'],
            'delta' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
