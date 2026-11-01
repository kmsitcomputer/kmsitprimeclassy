<?php

namespace App\Jobs;

use App\Mail\TransactionalNotificationMail;
use App\Models\EmailMessage;
use App\Services\Mail\SmtpSettings;
use App\Services\Notifications\AdminNotificationService;
use App\Services\WhatsApp\GatewayResult;
use App\Services\WhatsApp\WhatsAppSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Sends ONE transactional e-mail ledger row, once. The row is claimed with a conditional update (queued -> sending),
 * so a duplicated job or a second worker can never send it twice. No automatic retry ($tries = 1): a failure is
 * recorded on the row and raised in the in-app centre; it is never silently re-sent.
 */
class SendTransactionalEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $messageId)
    {
        $this->onQueue(WhatsAppSettings::NOTIFY_QUEUE);
    }

    public function handle(SmtpSettings $smtp, AdminNotificationService $inApp): void
    {
        $claimed = EmailMessage::query()->whereKey($this->messageId)->where('status', EmailMessage::QUEUED)->update(['status' => 'sending']);
        if ($claimed !== 1) {
            return;
        }
        $message = EmailMessage::query()->findOrFail($this->messageId);

        // Re-read the switch at send time: a message queued before e-mail was switched off is not sent.
        if (! $smtp->reload()->transactionalEnabled() || ! $smtp->applyRuntime()) {
            $message->forceFill(['status' => EmailMessage::SKIPPED, 'skip_reason' => 'mail_disabled'])->save();

            return;
        }

        try {
            Mail::to($message->recipient_email)->send(new TransactionalNotificationMail($message->subject, $message->body));
            $message->forceFill(['status' => EmailMessage::SENT, 'sent_at' => now()])->save();
        } catch (\Throwable $e) {
            $message->forceFill([
                'status' => EmailMessage::FAILED,
                'error_class' => mb_substr(class_basename($e), 0, 60),
                'error_message' => mb_substr(GatewayResult::sanitize($e->getMessage()), 0, 255),
            ])->save();
            $inApp->deliveryFailed('email', (string) $message->event, $message->agent_id, $message->order_id, 'email_message', $message->id, EmailMessage::FAILED);
        }
    }
}
