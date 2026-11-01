<?php

namespace App\Observers;

use App\Models\AgentWhatsAppDevice;
use App\Models\WhatsAppMessage;
use App\Services\Notifications\AdminNotificationService;
use Illuminate\Support\Facades\Log;

/**
 * Raises operator in-app notifications from facts that already exist:
 *   WhatsAppMessage (transaction) -> failed | unknown          -> delivery.failed (NTF-033)
 *   AgentWhatsAppDevice state into / out of `connected`        -> gateway.status (NTF-034)
 * No constructor dependencies and everything caught: an observer can never break the write it observes.
 */
class AdminNotificationObserver
{
    public function updated(object $model): void
    {
        try {
            match (true) {
                $model instanceof WhatsAppMessage => $this->message($model),
                $model instanceof AgentWhatsAppDevice => $this->device($model),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::warning('admin_notification.observer.failed', ['error' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    private function message(WhatsAppMessage $m): void
    {
        if ($m->purpose !== WhatsAppMessage::PURPOSE_TRANSACTION || ! $m->wasChanged('status')
            || ! in_array($m->status, [WhatsAppMessage::FAILED, WhatsAppMessage::UNKNOWN], true)) {
            return;
        }
        app(AdminNotificationService::class)->deliveryFailed('whatsapp', (string) $m->event, $m->agent_id, $m->order_id, 'whatsapp_message', $m->id, $m->status);
    }

    private function device(AgentWhatsAppDevice $d): void
    {
        if (! $d->wasChanged('state')) {
            return;
        }
        $from = (string) $d->getOriginal('state');
        $to = (string) $d->state;
        // Only connection loss / restore is operationally relevant; pairing steps are not.
        if (($from === AgentWhatsAppDevice::CONNECTED) === ($to === AgentWhatsAppDevice::CONNECTED)) {
            return;
        }
        app(AdminNotificationService::class)->gatewayStatusChanged((int) $d->agent_id, $d->id, $from, $to, $d->phone_masked);
    }
}
