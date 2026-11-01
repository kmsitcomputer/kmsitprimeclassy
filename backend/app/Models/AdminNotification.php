<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/** One in-app notification for ONE operator account (personal read state). */
class AdminNotification extends Model
{
    public const INFO = 'info';

    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    protected $fillable = [
        'dedupe_key', 'recipient_user_id', 'agent_id', 'type', 'severity', 'title', 'body', 'order_id',
        'subject_type', 'subject_id', 'read_at',
    ];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    /** Transitional guard: inert until migration 2026_11_05_100100 is applied (Owner-gated on production). */
    public static function tableReady(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('admin_notifications');
    }

    public function scopeOwnedBy(Builder $q, User $user): Builder
    {
        return $q->where('recipient_user_id', $user->id);
    }
}
