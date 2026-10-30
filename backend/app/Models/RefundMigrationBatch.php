<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RefundMigrationBatch extends Model
{
    protected $fillable = [
        'batch_key', 'mode', 'status', 'started_at', 'finished_at', 'stats', 'created_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
