<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/** One authoritative per-Agen row per notification event (enabled flag and/or template override). */
class AgentWhatsAppEventSetting extends Model
{
    protected $table = 'agent_whatsapp_event_settings';

    protected $fillable = ['agent_id', 'event', 'enabled', 'template', 'updated_by'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /** False until the additive migration exists: callers fall back to the legacy global settings. */
    public static function tableReady(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('agent_whatsapp_event_settings');
    }
}
