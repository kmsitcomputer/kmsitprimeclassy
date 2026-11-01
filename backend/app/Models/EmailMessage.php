<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/** Transactional e-mail ledger row (one per event x recipient account). */
class EmailMessage extends Model
{
    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    protected $fillable = [
        'idempotency_key', 'event', 'agent_id', 'order_id', 'subject_type', 'subject_id', 'recipient_role',
        'recipient_user_id', 'recipient_email', 'subject', 'body', 'status', 'error_class', 'error_message',
        'skip_reason', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    /** Transitional guard: inert until migration 2026_11_05_100000 is applied (Owner-gated on production). */
    public static function tableReady(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('email_messages');
    }

    public function maskedEmail(): string
    {
        [$local, $domain] = array_pad(explode('@', (string) $this->recipient_email, 2), 2, '');

        return mb_substr($local, 0, 1).str_repeat('*', max(1, mb_strlen($local) - 1)).'@'.$domain;
    }
}
