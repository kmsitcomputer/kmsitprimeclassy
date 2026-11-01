<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Order / payment / shipping / return / refund e-mail. Deliberately NOT ShouldQueue: it is sent from inside
 * SendTransactionalEmailJob, which owns the ledger row and records the real outcome.
 */
class TransactionalNotificationMail extends Mailable
{
    public function __construct(public readonly string $mailSubject, public readonly string $text) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(htmlString: nl2br(e($this->text))
            .'<br><br>Salam,<br><strong>Tim PrimeClassy</strong><br><br>'
            .'<small>Pesan ini dikirim otomatis berdasarkan aktivitas transaksi atau akun Anda. '
            .'Untuk bantuan, gunakan kanal layanan pelanggan resmi PrimeClassy.</small>');
    }
}
