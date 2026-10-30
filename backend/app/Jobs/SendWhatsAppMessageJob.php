<?php

namespace App\Jobs;

use App\Services\WhatsApp\WhatsAppDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends ONE WhatsApp message row. The job carries only the row id (never a phone number, OTP or credential), keeps
 * Laravel's own retry off (`$tries = 1`) because WhatsAppDelivery owns the duplicate-safe retry policy, and is a no-op
 * if the row is not QUEUED any more (a replayed job cannot double-send).
 */
class SendWhatsAppMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $messageId) {}

    public function handle(WhatsAppDelivery $delivery): void
    {
        $delivery->deliver($this->messageId);
    }
}
