<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserClosure extends Model
{
    public $timestamps = false;

    protected $fillable = ['ancestor_id', 'descendant_id', 'depth'];

    public function ancestor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ancestor_id');
    }

    public function descendant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'descendant_id');
    }
}
