<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OtpChallengeMail extends Mailable
{
    public function __construct(#[\SensitiveParameter] public readonly string $code,public readonly int $ttlMinutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('messages.consent.otp_mail_subject'));
    }

    public function content(): Content
    {
        return new Content(htmlString: e(__('messages.consent.otp_mail_body', [
            'code' => $this->code, 'minutes' => $this->ttlMinutes,
        ])));
    }
}
