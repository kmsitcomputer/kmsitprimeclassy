<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Audit row for every per-order due-date set/change (old, new, reason, actor, time). */
class OrderDueDateChange extends Model
{
    protected $fillable = ['order_id', 'old_due_date', 'new_due_date', 'reason', 'changed_by'];

    protected function casts(): array
    {
        return ['old_due_date' => 'date', 'new_due_date' => 'date'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
