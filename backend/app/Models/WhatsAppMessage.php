<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/** One WhatsApp message attempt-ledger row. See the migration for the status vocabulary and secrecy rules. */
class WhatsAppMessage extends Model
{
    public const QUEUED = 'queued';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const UNKNOWN = 'unknown';

    public const SKIPPED = 'skipped';

    public const PURPOSE_TRANSACTION = 'transaction';

    public const PURPOSE_OTP = 'otp';

    public const PURPOSE_TEST = 'test';

    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'idempotency_key', 'purpose', 'event', 'template_class', 'recipient_role', 'agent_id', 'order_id', 'subject_type', 'subject_id',
        'recipient_user_id', 'recipient_phone', 'sender_device_id', 'body', 'secret_body', 'status', 'attempts', 'max_attempts',
        'provider_message_id', 'error_class', 'error_message', 'skip_reason', 'created_by',
        'queued_at', 'next_attempt_at', 'last_attempt_at', 'sent_at',
    ];

    protected $hidden = ['secret_body'];

    protected function casts(): array
    {
        return [
            'secret_body' => 'encrypted',
            'queued_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * Transitional guard: the table arrives with migration 2026_10_31_100000 (Owner-gated). Until it exists on a
     * database (e.g. DEV) the notifier stays inert and the dashboard reports "migration pending".
     */
    public static function tableReady(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('whatsapp_messages');
    }

    /** Ledger columns added by migration 2026_11_03_100000 (additive; Owner-gated on DEV/production). */
    public const LEDGER_COLUMNS = ['template_class', 'recipient_role', 'sender_device_id'];

    /**
     * Has migration 2026_11_03_100000 been applied? Until it has, the new columns must never be written (an INSERT naming a
     * missing column would throw) and a restricted row — whose authorization lives in those columns — must not be created at all.
     */
    public static function ledgerReady(): bool
    {
        static $ready = null;

        return $ready ??= self::tableReady() && Schema::hasColumns('whatsapp_messages', self::LEDGER_COLUMNS);
    }

    /** Last 3 digits only — enough for an operator to recognise a number, never enough to harvest it. */
    public function maskedPhone(): ?string
    {
        $p = (string) $this->recipient_phone;

        return $p === '' ? null : substr($p, 0, 2).'••••'.substr($p, -3);
    }
}
