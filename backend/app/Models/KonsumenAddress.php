<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KonsumenAddress extends Model
{
    protected $fillable = [
        'user_id', 'label', 'recipient_name', 'phone', 'address_line',
        'province_id', 'regency_id', 'district_id', 'village_id',
        'latitude', 'longitude', 'is_default',
    ];

    protected function casts(): array
    {
        return [
            // See User::casts() — user_id is compared with strict !== against
            // $request->user()->id in KonsumenAddressController::authorizeOwnership;
            // uncast it returns as a string on some PDO/MySQL driver builds and
            // would wrongly 404 the owner out of their own address.
            'user_id' => 'integer',
            'province_id' => 'integer',
            'regency_id' => 'integer',
            'district_id' => 'integer',
            'village_id' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function regency(): BelongsTo
    {
        return $this->belongsTo(Regency::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class);
    }
}
