<?php

namespace App\Models\Concerns;

use App\Models\Product;
use App\Rules\GlobalSku;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

trait HasGlobalSku
{
    /** Reserve the global namespace in the same transaction as the catalog write. */
    public function save(array $options = [])
    {
        $type = $this instanceof Product ? 'product' : 'variant';
        $required = $type === 'variant' || ! $this->has_variations;
        Validator::make(['sku' => $this->sku], ['sku' => [
            $required ? 'required' : 'prohibited', 'nullable', 'string', 'max:60',
            new GlobalSku($type, $this->exists ? $this->id : null),
        ]])->validate();
        try {
            return DB::transaction(function () use ($options, $type) {
                $saved = parent::save($options);
                if ($saved && $this->sku !== null) {
                    DB::table('catalog_skus')->updateOrInsert(
                        ['owner_type' => $type, 'owner_id' => $this->id], ['sku' => $this->sku]
                    );
                }

                return $saved;
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062 && str_contains($e->getMessage(), 'sku')) {
                throw ValidationException::withMessages(['sku' => 'SKU sudah digunakan pada katalog.']);
            }
            throw $e;
        }
    }
}
