<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SheetsSyncLog extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['agent_scope' => 'integer', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
