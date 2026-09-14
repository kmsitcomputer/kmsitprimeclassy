<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Provider-supported courier master list — see the migration's docblock. */
class ShippingCourier extends Model
{
    protected $fillable = ['code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
