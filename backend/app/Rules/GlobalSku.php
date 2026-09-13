<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

class GlobalSku implements ValidationRule
{
    public function __construct(private string $type, private ?int $id = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $query = DB::table('catalog_skus')->where('sku', $value);
        if ($this->id) {
            $query->where(fn ($q) => $q->where('owner_type', '!=', $this->type)->orWhere('owner_id', '!=', $this->id));
        }
        if ($query->exists()) {
            $fail('SKU sudah digunakan pada katalog.');
        }
    }
}
