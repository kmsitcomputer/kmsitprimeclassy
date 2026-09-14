<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SheetsDestination extends Model
{
    protected $fillable = [
        'spreadsheet_id', 'agent_id', 'spreadsheet_title', 'connection_status',
        'last_tested_at', 'last_error_code',
    ];

    protected function casts(): array
    {
        return ['agent_id' => 'integer', 'last_tested_at' => 'datetime'];
    }
}
