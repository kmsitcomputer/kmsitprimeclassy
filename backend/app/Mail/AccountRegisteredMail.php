<?php

namespace App\Mail;

use App\Services\WhatsApp\WhatsAppSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * R-02 registration notice: informational only. Carries NO password, OTP, reset link, role hierarchy or order/finance data,
 * and it is not a verification. Queued on the notification lane with bounded retries; a failure never touches the account.
 */
class AccountRegisteredMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly string $name)
    {
        $this->onQueue(WhatsAppSettings::NOTIFY_QUEUE);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('messages.auth.registered_mail_subject'));
    }

    public function content(): Content
    {
        return new Content(htmlString: e(__('messages.auth.registered_mail_subject')).'<br><br>'.e($this->name).',<br><br>'
            .e(__('messages.auth.registered_mail_body')));
    }
}
