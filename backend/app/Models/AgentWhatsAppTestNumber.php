<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/** A number an Agen (or its Admin) registered as an allowed WhatsApp test destination for THAT Agen only. */
class AgentWhatsAppTestNumber extends Model
{
    protected $table = 'agent_whatsapp_test_numbers';

    protected $fillable = ['agent_id', 'msisdn', 'registered_by'];

    public static function tableReady(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('agent_whatsapp_test_numbers');
    }
}
