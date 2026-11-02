<?php

namespace App\Services\WhatsApp;

use App\Models\AgentProfile;
use App\Models\BankTransferVerification;
use App\Models\Order;
use App\Models\RefundObligation;
use App\Models\RefundPayout;
use App\Models\ReturnRequest;
use App\Models\Shipment;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\Notifications\AdminNotificationService;
use App\Services\Notifications\FinanceRecipientResolver;
use App\Services\Notifications\TransactionalEmailNotifier;
use App\Services\Payment\PaymentSummaryService;
use App\Support\WhatsAppNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a REAL, already-persisted business fact into at most one transactional WhatsApp message.
 *
 *  - Called from model observers, but the work is deferred with DB::afterCommit(): a rolled-back transaction sends
 *    nothing, and a gateway/DB problem here can never fail or roll back the business operation (everything is caught).
 *  - Idempotency key = event + subject id, so a duplicate trigger, a replay or a page refresh yields the same single row.
 *  - Recipient rule: the order's own ACCOUNT buyer, at the phone on that account. CRM-origin orders are never messaged
 *    (the Sales-typed CRM number is not a verified recipient) — recorded as `skipped`, not silently ignored.
 *  - Gateway disabled or the event switched off => nothing is recorded at all (default OFF everywhere).
 */
class OrderNotifier
{
    private const STATUS_LABEL = [
        'diterima' => 'Diterima', 'diproses' => 'Diproses', 'dikirim' => 'Dikirim', 'terkirim' => 'Terkirim',
        'dibatalkan' => 'Dibatalkan', 'pengembalian' => 'Pengembalian', 'kembali' => 'Dikembalikan',
    ];

    private const MONTHS = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public function __construct(
        private readonly WhatsAppSettings $settings,
        private readonly WhatsAppDelivery $delivery,
        private readonly OrderRecipientResolver $recipients,
    ) {}

    /* ---------------------------------------------------------------- entry points (from observers) */

    public function orderEvent(Order $order, string $event): void
    {
        $this->dispatch($event, 'order', $order->id, $order->id, fn () => []);
    }

    public function returnEvent(ReturnRequest $return, string $event): void
    {
        $this->dispatch($event, 'return', $return->id, $return->order_id, fn () => [
            'nomor_retur' => 'RTN-'.str_pad((string) $return->id, 6, '0', STR_PAD_LEFT),
        ]);
    }

    public function refundEvent(RefundPayout $payout): void
    {
        $this->dispatch(WhatsAppEvents::REFUND_COMPLETED, 'refund_payout', $payout->id, $payout->order_id, fn () => [
            'jumlah_refund' => self::money($payout->amount),
            'tanggal_refund' => $this->date($payout->paid_at),
        ]);
    }

    public function obligationEvent(RefundObligation $obligation, string $event): void
    {
        $this->dispatch($event, 'refund_obligation', $obligation->id, $obligation->order_id, fn () => [
            'nomor_refund' => $obligation->obligation_no,
            'jumlah_refund' => self::money($obligation->amount),
        ]);
    }

    public function paymentRejected(BankTransferVerification $verification, int $orderId): void
    {
        $this->dispatch(WhatsAppEvents::PAYMENT_FAILED, 'bank_transfer_verification', $verification->id, $orderId, fn () => [
            'jumlah_diajukan' => $verification->submitted_amount !== null ? self::money($verification->submitted_amount) : null,
            'alasan_penolakan' => $verification->rejection_reason,
        ]);
    }

    /**
     * A consumer uploaded a payment proof. Called from PaymentService INSIDE the upload transaction; everything below is
     * deferred to after-commit, so a rolled-back upload notifies nobody. Recipients: Keuangan of the order's branch only.
     * The upload never changes the payment status. `$proofHash` (sha256 of the file) is the upload identity: an identical
     * re-upload of the same file yields the same keys and therefore no duplicate.
     */
    public function paymentProofUploaded(BankTransferVerification $verification, int $orderId, string $proofHash): void
    {
        $event = WhatsAppEvents::PAYMENT_PROOF_UPLOADED;
        $vars = fn (Order $order, User $user) => [
            'nama_keuangan' => $user->name,
            'nominal_klaim' => self::money($verification->submitted_amount),
            'waktu_upload' => $verification->submitted_at ? self::dateTime($verification->submitted_at) : null,
            'link_verifikasi_pembayaran' => self::orderUrl($order),
        ];

        $this->defer($event, function () use ($event, $verification, $orderId, $proofHash, $vars) {
            $order = $this->loadOrder($orderId);
            if (! $order || ! $this->settings->eventEnabledFor($event, $order->agent_id)) {
                return;
            }
            $users = app(FinanceRecipientResolver::class)->for($order);
            if ($users->isEmpty()) {
                Log::warning('payment_proof.no_authorized_finance_recipient', ['order_id' => $orderId]);

                return;
            }
            foreach ($users as $user) {
                $base = [
                    'idempotency_key' => "txn:{$event}:bank_transfer_verification:{$verification->id}:{$proofHash}:u{$user->id}",
                    'purpose' => WhatsAppMessage::PURPOSE_TRANSACTION, 'event' => $event,
                    'agent_id' => $order->agent_id, 'order_id' => $order->id,
                    'subject_type' => 'bank_transfer_verification', 'subject_id' => $verification->id,
                    'recipient_role' => 'finance', 'recipient_user_id' => $user->id, 'template_class' => 'full',
                ];
                $msisdn = WhatsAppNumberProof::msisdnFor($user);
                if ($msisdn === null) {
                    $this->delivery->skip($base, 'number_not_verified');

                    continue;
                }
                $this->sendTo($base, $order, $event, 'finance', $msisdn, $user, 'full',
                    fn () => $vars($order, $user), primary: true);
            }
        }, $orderId);

        DB::afterCommit(function () use ($verification, $orderId, $proofHash, $vars) {
            try {
                $order = $this->loadOrder($orderId);
                if (! $order) {
                    return;
                }
                $users = app(FinanceRecipientResolver::class)->for($order);
                app(TransactionalEmailNotifier::class)->dispatchFinance(WhatsAppEvents::PAYMENT_PROOF_UPLOADED, $order, $verification, $proofHash,
                    $this->data($order), $users, fn (User $u) => $vars($order, $u));
                app(AdminNotificationService::class)->paymentProofUploaded($order, $verification, $proofHash, $users);
            } catch (\Throwable $e) {
                Log::warning('notify.payment_proof.channels_failed', ['order_id' => $orderId, 'error' => GatewayResult::sanitize($e->getMessage())]);
            }
        });
    }

    /**
     * One business fact, every channel. WhatsApp keeps its own gate (gateway ON + event switch); email and the in-app
     * centre have theirs, so switching WhatsApp off never silences email (and vice versa). All deferred to after-commit.
     */
    private function dispatch(string $event, string $subjectType, int $subjectId, ?int $orderId, \Closure $extra): void
    {
        $this->defer($event, fn () => $this->send($event, $subjectType, $subjectId, $orderId, $extra), $orderId);
        $this->deferChannels($event, $subjectType, $subjectId, $orderId, $extra);
    }

    private function deferChannels(string $event, string $subjectType, int $subjectId, ?int $orderId, \Closure $extra): void
    {
        if ($orderId === null) {
            return;
        }
        DB::afterCommit(function () use ($event, $subjectType, $subjectId, $orderId, $extra) {
            try {
                $order = $this->loadOrder($orderId);
                if (! $order) {
                    return;
                }
                $vars = $this->data($order) + $extra();
                app(TransactionalEmailNotifier::class)->dispatch($event, $order, $subjectType, $subjectId, $vars);
                app(AdminNotificationService::class)->orderEvent($event, $order, $subjectType, $subjectId, $vars);
            } catch (\Throwable $e) {
                Log::warning('notify.channels.failed', ['event' => $event, 'order_id' => $orderId, 'error' => GatewayResult::sanitize($e->getMessage())]);
            }
        });
    }

    private function loadOrder(int $orderId): ?Order
    {
        return Order::withoutGlobalScopes()->with(['konsumen', 'sales', 'placedBy', 'shipments.courier', 'shipments.selfDeliveredBy', 'items', 'paymentTransactions.paymentMethod', 'paymentMethod'])->find($orderId);
    }

    private static function money(mixed $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }

    /** Local date + time in the application zone (Asia/Jakarta), for upload timestamps. */
    private static function dateTime(\DateTimeInterface $at): string
    {
        $c = \Illuminate\Support\Carbon::instance($at)->setTimezone('Asia/Jakarta');

        return $c->day.' '.self::MONTHS[$c->month].' '.$c->year.', '.$c->format('H:i').' WIB';
    }

    /* ---------------------------------------------------------------- internals */

    private function defer(string $event, \Closure $work, ?int $orderId = null): void
    {
        // Cheap guards first: this runs on every order/return/refund write. reload(): never trust a stale cache here.
        if (! WhatsAppMessage::tableReady() || ! $this->settings->reload()->enabled()) {
            return;
        }

        DB::afterCommit(function () use ($work, $event, $orderId) {
            try {
                $work();
            } catch (\Throwable $e) {
                // Never propagate: notifications are best-effort side effects of an already-committed business change.
                Log::warning('whatsapp.notify.failed', ['event' => $event, 'order_id' => $orderId, 'error' => GatewayResult::sanitize($e->getMessage())]);
            }
        });
    }

    /** @param  \Closure():array<string,string|null>  $extra */
    private function send(string $event, string $subjectType, int $subjectId, ?int $orderId, \Closure $extra): void
    {
        $this->settings->reload();
        $key = "txn:{$event}:{$subjectType}:{$subjectId}";
        $order = $orderId ? $this->loadOrder($orderId) : null;
        if (! $order) {
            return;
        }
        // Event switch is the ORDERING Agen's (scoped row, else the legacy global flag). Disabled = no ledger row at all, as before.
        if (! $this->settings->eventEnabledFor($event, $order->agent_id)) {
            return;
        }

        $base = [
            'idempotency_key' => $key, 'purpose' => WhatsAppMessage::PURPOSE_TRANSACTION, 'event' => $event,
            'agent_id' => $order->agent_id, 'order_id' => $order->id, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
        ];

        if ($order->isCrmOrigin() || $order->konsumen === null) {
            $this->delivery->skip($base, 'recipient_not_verified');

            return;
        }

        // HA-03/CL-05 routing: the order's own ACCOUNT buyer keeps its exact behaviour (full template, proven number
        // or a visible skip). Admin + checkout-recipient reach uses the restricted template on the SAME event.
        $targets = $this->recipients->resolve($order);
        if ($targets === []) {
            $msisdn = WhatsAppNumberProof::msisdnFor($order->konsumen);
            $this->delivery->skip(
                $base + ['recipient_user_id' => $order->konsumen->id],
                WhatsAppNumber::msisdn($order->konsumen->phone) === null ? 'no_phone' : 'number_not_verified',
            );

            return;
        }

        foreach ($targets as $t) {
            $this->sendTo($base, $order, $event, $t['role'], $t['msisdn'], $t['user'], $t['template_class'], $extra, (bool) ($t['primary'] ?? false));
        }
    }

    /**
     * One role-scoped row for one verified recipient. Idempotency: the PRIMARY buyer row keeps the legacy
     * `txn:{event}:{subject}:{id}` key byte-for-byte, so replays of the pre-existing buyer-only path stay a single row;
     * non-primary roles append `:{role}` and are independent rows. Deduplication already happened in the resolver
     * (same number ⇒ highest privilege only).
     *
     * @param  \Closure():array<string,string|null>  $extra
     */
    private function sendTo(array $base, Order $order, string $event, string $role, string $msisdn, ?User $user, string $templateClass, \Closure $extra, bool $primary = false): void
    {
        // NOTE: `$base` already carries `idempotency_key`, so `$base + [...]` would keep the BASE key. Build the row
        // explicitly instead: primary keeps the legacy key, every other role gets `:{role}` as its own ledger row.
        $key = [
            'idempotency_key' => $primary ? $base['idempotency_key'] : $base['idempotency_key'].':'.$role,
            'purpose' => $base['purpose'], 'event' => $base['event'],
            'agent_id' => $base['agent_id'], 'order_id' => $base['order_id'],
            'subject_type' => $base['subject_type'], 'subject_id' => $base['subject_id'],
            'recipient_role' => $role, 'template_class' => $templateClass,
            'recipient_phone' => $msisdn,
        ] + ($user ? ['recipient_user_id' => $user->id] : []);

        if ($templateClass === RestrictedDeliveryPolicy::CLASS_RESTRICTED) {
            // R-01 closed allow-list: a restricted (possibly unproven) number only ever hears about status changes — never
            // payment/refund facts. The same policy re-validates at send time (WhatsAppDelivery::deliver).
            if (! RestrictedDeliveryPolicy::eventEligible($role, $event)) {
                $this->delivery->skip($key, 'restricted_event_not_allowed');

                return;
            }
            $body = MessageTemplates::render($event, $this->restrictedTemplate($event, $order), ['nama_pelanggan' => $user?->name, 'nomor_pesanan' => $order->order_no]);
        } else {
            $body = MessageTemplates::render($event, $this->settings->templateFor($event, $order->agent_id), $this->data($order) + $extra());
        }
        if ($body === null) {
            $this->delivery->skip($key, 'template_unrenderable');

            return;
        }

        $this->delivery->enqueue($key + ['body' => $body]);
    }

    /**
     * CL-05 restricted variant: limited, non-sensitive; names the event, names nothing financial, carries no OTP,
     * token or link — and always says the same thing (no per-recipient disclosure through wording).
     */
    private function restrictedTemplate(string $event, Order $order): string
    {
        $label = WhatsAppEvents::all()[$event]['label'] ?? 'pesanan';

        return "Halo Pelanggan,\n\nAda pembaruan {$label} untuk pesanan {$order->order_no}.\n\nVerifikasi nomor WhatsApp Anda di aplikasi PrimeClassy untuk melihat detail lengkap.";
    }

    /** @return array<string,string|null> every value is REAL data or null — never invented */
    private function data(Order $order): array
    {
        $shipments = $order->shipments->filter(fn (Shipment $s) => $s->status !== 'cancelled');
        $couriers = $shipments->map(fn (Shipment $s) => $s->courier?->name ?? $s->selfDeliveredBy?->name)->filter()->unique()->values();
        $resi = $shipments->pluck('tracking_number')->filter()->unique()->values();
        $store = AgentProfile::query()->where('user_id', $order->agent_id)->value('store_name');
        $first = $shipments->first();

        // HA-04/HA-06 payment truth: verified summary only (PaymentSummaryService reads paid_amount backfilled by
        // real money events); a submitted-but-unverified DP never appears as paid. Methods/dates come from the latest
        // VERIFIED payment transaction, never from order status.
        $summary = PaymentSummaryService::summarize($order);
        $latestVerified = $order->paymentTransactions
            ->filter(fn ($t) => $t->status === 'paid' && $t->paid_at !== null)
            ->sortByDesc('id')->first() ?? $order->paymentTransactions->filter(fn ($t) => $t->status === 'paid')->sortByDesc('id')->first();

        $items = $order->items->map(fn ($i) => trim(($i->product_name_snapshot ?? '').($i->variation_label_snapshot ? ' ('.$i->variation_label_snapshot.')' : '').' x'.($i->original_quantity ?? $i->quantity ?? 1)))->filter()->values();

        return [
            'nama_pelanggan' => $order->konsumen?->name,
            'nomor_pesanan' => $order->order_no,
            'status' => self::STATUS_LABEL[$order->status] ?? null,
            'total' => 'Rp '.number_format((float) $order->total_amount, 0, ',', '.'),
            'tanggal' => $this->date($order->created_at),
            'nama_toko' => $store ?: 'Prime Classy',
            'kurir' => $couriers->isNotEmpty() ? $couriers->implode(', ') : null,
            'resi' => $resi->isNotEmpty() ? $resi->implode(', ') : null,
            // AUD-06 invoice variables (server-derived snapshots, never client input).
            'daftar_produk' => $items->isNotEmpty() ? $items->implode(', ') : null,
            'subtotal' => 'Rp '.number_format((float) $order->subtotal_amount, 0, ',', '.'),
            'diskon' => 'Rp '.number_format((float) $order->discount_amount, 0, ',', '.'),
            'ongkir' => 'Rp '.number_format((float) $order->shipping_fee_amount, 0, ',', '.'),
            'total_tagihan' => 'Rp '.number_format((float) $order->total_amount, 0, ',', '.'),
            'metode_pembayaran' => $order->paymentMethod?->name ?? $latestVerified?->paymentMethod?->name,
            'status_pembayaran' => $order->payment_status,
            'jumlah_dibayarkan' => 'Rp '.number_format((float) $summary['total_paid'], 0, ',', '.'),
            'sisa_tagihan' => 'Rp '.number_format((float) $summary['remaining_balance'], 0, ',', '.'),
            'tanggal_pembayaran' => $this->date($latestVerified?->paid_at),
            // D-10: the amount of THIS verified payment (latest verified transaction), distinct from the cumulative total.
            'jumlah_pembayaran_ini' => $latestVerified ? 'Rp '.number_format((float) $latestVerified->amount, 0, ',', '.') : null,
            'tanggal_pengiriman' => $this->date($shipments->pluck('shipped_at')->filter()->max()),
            'tanggal_diterima' => $this->date($shipments->pluck('delivered_at')->filter()->max()),
            'tanggal_pembatalan' => $this->date($order->cancelled_at),
            // AUD-06 shipping variables (Shipment truth only; courier phone is deliberately NEVER exposed).
            'nama_kurir' => $first?->courier?->name ?? $first?->selfDeliveredBy?->name,
            'layanan_pengiriman' => $first?->shipping_provider_code ?? $first?->courier?->type,
            'nomor_resi' => $first?->tracking_number,
            'nomor_telepon_kurir' => null, // HA-06: no courier-phone source exists; always dropped, never invented.
            'estimasi_tiba' => null, // No ETA source exists; always dropped, never invented.
            // D-09: absolute URL of the SPA order-detail route, which takes the numeric id (`/orders/:id`, Number()).
            // The old `/orders/{order_no}` was relative and resolved to NaN. No configured base => the line drops.
            'link_pesanan' => self::orderUrl($order),
            // R-03: no verified tracking-URL source exists in the system (no config, no courier/provider URL template), so
            // the link is NEVER composed from a guessed domain. Null => the template line drops; `nomor_resi` still carries
            // the real tracking number.
            'link_pelacakan' => null,
        ];
    }

    private function date(?\DateTimeInterface $d): ?string
    {
        if (! $d) {
            return null;
        }
        $c = \Illuminate\Support\Carbon::instance($d)->setTimezone('Asia/Jakarta');

        return $c->day.' '.self::MONTHS[$c->month].' '.$c->year;
    }

    /** Absolute SPA order-detail URL (`/orders/:id`), or null when no frontend base is configured. */
    public static function orderUrl(Order $order): ?string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        return $base === '' ? null : $base.'/orders/'.$order->id;
    }
}
