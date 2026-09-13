<?php

namespace App\Http\Requests\Return;

use App\Http\Requests\BaseFormRequest;

class StoreReturnRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isRole('konsumen') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
            // "Konsumen ketika merubah status terkirim ke request refund
            // produk harus memberikan bukti foto produk" — no longer optional.
            'evidence' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer', 'exists:order_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.restock' => ['sometimes', 'boolean'],
        ];
    }
}
