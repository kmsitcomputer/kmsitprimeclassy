<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SheetsDestination extends Model
{
    protected $fillable = ['spreadsheet_id', 'agent_id'];

    protected function casts(): array
    {
        return ['agent_id' => 'integer'];
    }
}
