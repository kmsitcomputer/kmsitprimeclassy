<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SheetsConfig extends Model
{
    protected $fillable = ['destination_id', 'name', 'tab', 'dataset', 'columns', 'filters'];

    protected function casts(): array
    {
        return ['columns' => 'array', 'filters' => 'array'];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(SheetsDestination::class);
    }
}
