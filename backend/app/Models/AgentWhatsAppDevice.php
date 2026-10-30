<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/** The ONE WhatsApp device slot an Agent owns on the central GOWA server. Holds no secret and no full number. */
class AgentWhatsAppDevice extends Model
{
    public const NOT_PAIRED = 'not_paired';

    public const PAIRING = 'pairing';

    public const CONNECTED = 'connected';

    public const DISCONNECTED = 'disconnected';

    protected $table = 'agent_whatsapp_devices';

    protected $fillable = ['agent_id', 'device_id', 'state', 'phone_masked', 'paired_at', 'last_checked_at', 'replaced_count'];

    protected function casts(): array
    {
        return ['paired_at' => 'datetime', 'last_checked_at' => 'datetime', 'replaced_count' => 'integer'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /** False until the additive migration exists on this database: everything per-Agent stays inert, nothing 500s. */
    public static function tableReady(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('agent_whatsapp_devices');
    }

    /** A slot that has been observed logged in at least once may be used for sending; the gateway decides the rest. */
    public function hasBeenPaired(): bool
    {
        return $this->paired_at !== null;
    }
}
