<?php

namespace App\Services\Notifications;

use App\Jobs\SendTransactionalEmailJob;
use App\Models\EmailMessage;
use App\Models\Order;
use App\Models\User;
use App\Services\Mail\SmtpSettings;
use App\Services\WhatsApp\MessageTemplates;
use App\Services\WhatsApp\WhatsAppEvents;

/**
 * Transactional e-mail for the same business facts as WhatsApp (order / payment / shipping / return / refund).
 *
 *  - Gate: ledger table present AND SmtpSettings::transactionalEnabled() (dashboard SMTP ON + complete + its own switch,
 *    default OFF). Independent of the WhatsApp switches.
 *  - Recipients (privacy, same roles as the WhatsApp FULL template): branch admins and the order's creator accounts
 *    (buyer, and the recorded submitter when different), each only with a VERIFIED e-mail. The checkout recipient is
 *    never an account and never receives e-mail. CRM-origin orders are skipped entirely, exactly like WhatsApp.
 *  - Content: the system contract text (WhatsAppEvents default) rendered with the same verified variables. Agen
 *    WhatsApp templates stay WhatsApp-only; nothing financial is invented (optional lines drop).
 *  - Idempotency: `mail:{event}:{subject}:{id}:{user}` is UNIQUE; a replayed fact never creates a second row/send.
 */
final class TransactionalEmailNotifier
{
    /** Subject per event (Master Template "Judul Email"). */
    public const SUBJECTS = [
        WhatsAppEvents::ORDER_CREATED => 'Pesanan {nomor_pesanan} Berhasil Dibuat',
        WhatsAppEvents::PAYMENT_PAID => 'Pesanan {nomor_pesanan} Telah Lunas',
        WhatsAppEvents::PAYMENT_PARTIAL => 'Pembayaran Sebagian Pesanan {nomor_pesanan} Diterima',
        WhatsAppEvents::PAYMENT_FAILED => 'Pembayaran Pesanan {nomor_pesanan} Belum Berhasil',
        WhatsAppEvents::ORDER_PROCESSED => 'Pesanan {nomor_pesanan} Sedang Diproses',
        WhatsAppEvents::ORDER_SHIPPED => 'Pesanan {nomor_pesanan} Telah Dikirim',
        WhatsAppEvents::ORDER_DELIVERED => 'Pesanan {nomor_pesanan} Selesai',
        WhatsAppEvents::ORDER_CANCELLED => 'Pesanan {nomor_pesanan} Dibatalkan',
        WhatsAppEvents::RETURN_REQUESTED => 'Pengajuan Retur {nomor_retur} Diterima',
        WhatsAppEvents::RETURN_APPROVED => 'Pengajuan Retur {nomor_retur} Disetujui',
        WhatsAppEvents::RETURN_REJECTED => 'Hasil Peninjauan Retur {nomor_retur}',
        WhatsAppEvents::REFUND_REQUESTED => 'Permohonan Refund {nomor_refund} Diterima',
        WhatsAppEvents::REFUND_APPROVED => 'Refund {nomor_refund} Disetujui',
        WhatsAppEvents::REFUND_REJECTED => 'Hasil Peninjauan Refund {nomor_refund}',
        WhatsAppEvents::REFUND_COMPLETED => 'Pengembalian Dana Pesanan {nomor_pesanan} Selesai',
    ];

    private const PRECEDENCE = ['admin' => 2, 'creator' => 1];

    public function __construct(private readonly SmtpSettings $smtp) {}

    /** @param  array<string, string|null>  $vars  verified variables (OrderNotifier::data + event extras) */
    public function dispatch(string $event, Order $order, string $subjectType, int $subjectId, array $vars): void
    {
        if (! EmailMessage::tableReady() || ! isset(self::SUBJECTS[$event]) || ! $this->smtp->reload()->transactionalEnabled()) {
            return;
        }
        if ($order->isCrmOrigin() || $order->konsumen === null) {
            return;
        }

        $body = MessageTemplates::render($event, WhatsAppEvents::all()[$event]['default'], $vars);
        if ($body === null) {
            return;
        }
        $body = self::plain($body);
        $subject = self::subject($event, $vars);

        foreach ($this->recipients($order) as $r) {
            $row = EmailMessage::query()->createOrFirst(
                ['idempotency_key' => "mail:{$event}:{$subjectType}:{$subjectId}:{$r['user']->id}"],
                [
                    'event' => $event, 'agent_id' => $order->agent_id, 'order_id' => $order->id,
                    'subject_type' => $subjectType, 'subject_id' => $subjectId, 'recipient_role' => $r['role'],
                    'recipient_user_id' => $r['user']->id, 'recipient_email' => $r['email'],
                    'subject' => $subject, 'body' => $body, 'status' => EmailMessage::QUEUED,
                ],
            );
            if ($row->wasRecentlyCreated) {
                SendTransactionalEmailJob::dispatch($row->id);
            }
        }
    }

    /** @return array<int, array{role:string, user:User, email:string}> one entry per distinct verified address */
    public function recipients(Order $order): array
    {
        $candidates = [];
        if ($order->agent_id !== null) {
            $admins = User::query()->whereHas('role', fn ($q) => $q->where('slug', 'admin'))->where('agent_id', $order->agent_id)->get();
            foreach ($admins as $admin) {
                $candidates[] = ['role' => 'admin', 'user' => $admin];
            }
        }
        if ($order->konsumen !== null) {
            $candidates[] = ['role' => 'creator', 'user' => $order->konsumen];
        }
        if ($order->placed_by_user_id !== null && (int) $order->placed_by_user_id !== (int) $order->konsumen_id) {
            $submitter = User::query()->find($order->placed_by_user_id);
            if ($submitter !== null) {
                $candidates[] = ['role' => 'creator', 'user' => $submitter];
            }
        }

        $byEmail = [];
        foreach ($candidates as $c) {
            $email = self::verifiedEmail($c['user']);
            if ($email === null) {
                continue;
            }
            $prev = $byEmail[$email] ?? null;
            if ($prev === null || self::PRECEDENCE[$c['role']] > self::PRECEDENCE[$prev['role']]) {
                $byEmail[$email] = $c + ['email' => $email];
            }
        }

        return array_values($byEmail);
    }

    /** Only an address whose ownership was proven (email_verified_at) ever receives order data. */
    private static function verifiedEmail(User $user): ?string
    {
        $email = strtolower(trim((string) $user->email));

        return $user->email_verified_at !== null && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** WhatsApp markup and footer are channel-specific: e-mail gets plain text and its own footer (in the Mailable). */
    public static function plain(string $body): string
    {
        $body = str_replace(trim(WhatsAppEvents::FOOTER), '', $body);
        $body = preg_replace('/\*([^*\n]+)\*/u', '$1', $body) ?? $body;

        return trim(preg_replace("/\n{3,}/", "\n\n", $body) ?? $body);
    }

    /** @param  array<string, string|null>  $vars */
    public static function subject(string $event, array $vars): string
    {
        $subject = preg_replace_callback('/\{([a-z_]+)\}/', function (array $m) use ($vars) {
            return MessageTemplates::clean($vars[$m[1]] ?? null) ?? MessageTemplates::clean($vars['nomor_pesanan'] ?? null) ?? '';
        }, self::SUBJECTS[$event]) ?? '';

        return mb_substr(trim(preg_replace('/\s{2,}/', ' ', $subject) ?? $subject), 0, 190);
    }
}
