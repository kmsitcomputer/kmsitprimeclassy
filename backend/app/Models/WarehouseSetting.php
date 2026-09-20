<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseSetting extends Model
{
    protected $fillable = ['agent_id', 'factory_plan_enabled'];

    protected function casts(): array
    {
        return ['agent_id' => 'integer', 'factory_plan_enabled' => 'boolean'];
    }
}
