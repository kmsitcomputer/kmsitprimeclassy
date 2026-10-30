<?php

namespace App\Services\WhatsApp;

use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\RefundPayout;
use App\Models\ReturnRequest;
use App\Models\Shipment;
use App\Models\WhatsAppMessage;
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
    ) {}

    /* ---------------------------------------------------------------- entry points (from observers) */

    public function orderEvent(Order $order, string $event): void
    {
        $this->defer($event, fn () => $this->send($event, 'order', $order->id, $order->id, fn () => []), $order->id);
    }

    public function returnEvent(ReturnRequest $return, string $event): void
    {
        $this->defer($event, fn () => $this->send($event, 'return', $return->id, $return->order_id, fn () => [
            'nomor_retur' => 'RTN-'.str_pad((string) $return->id, 6, '0', STR_PAD_LEFT),
        ]));
    }

    public function refundEvent(RefundPayout $payout): void
    {
        $this->defer(WhatsAppEvents::REFUND_COMPLETED, fn () => $this->send(
            WhatsAppEvents::REFUND_COMPLETED, 'refund_payout', $payout->id, $payout->order_id,
            fn () => ['jumlah_refund' => 'Rp '.number_format((float) $payout->amount, 0, ',', '.')],
        ));
    }

    /* ---------------------------------------------------------------- internals */

    private function defer(string $event, \Closure $work, ?int $orderId = null): void
    {
        // Cheap guards first: this runs on every order/return/refund write. reload(): never trust a stale cache here.
        if (! WhatsAppMessage::tableReady() || ! $this->settings->reload()->enabled() || ! $this->settings->eventEnabled($event)) {
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
        $order = $orderId ? Order::withoutGlobalScopes()->with(['konsumen', 'shipments.courier', 'shipments.selfDeliveredBy'])->find($orderId) : null;
        if (! $order) {
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

        // Only a number whose ownership the account holder PROVED by OTP may receive order information. An unproven number
        // (typo, someone else's) never gets a message; the event is recorded as skipped so the operator can see why.
        $msisdn = WhatsAppNumberProof::msisdnFor($order->konsumen);
        if ($msisdn === null) {
            $this->delivery->skip(
                $base + ['recipient_user_id' => $order->konsumen->id],
                WhatsAppNumber::msisdn($order->konsumen->phone) === null ? 'no_phone' : 'number_not_verified',
            );

            return;
        }

        $body = MessageTemplates::render($event, $this->settings->template($event), $this->data($order) + $extra());
        if ($body === null) {
            $this->delivery->skip($base + ['recipient_user_id' => $order->konsumen->id, 'recipient_phone' => $msisdn], 'template_unrenderable');

            return;
        }

        $this->delivery->enqueue($base + ['recipient_user_id' => $order->konsumen->id, 'recipient_phone' => $msisdn, 'body' => $body]);
    }

    /** @return array<string,string|null> every value is REAL data or null — never invented */
    private function data(Order $order): array
    {
        $shipments = $order->shipments->filter(fn (Shipment $s) => $s->status !== 'cancelled');
        $couriers = $shipments->map(fn (Shipment $s) => $s->courier?->name ?? $s->selfDeliveredBy?->name)->filter()->unique()->values();
        $resi = $shipments->pluck('tracking_number')->filter()->unique()->values();
        $store = AgentProfile::query()->where('user_id', $order->agent_id)->value('store_name');

        return [
            'nama_pelanggan' => $order->konsumen?->name,
            'nomor_pesanan' => $order->order_no,
            'status' => self::STATUS_LABEL[$order->status] ?? null,
            'total' => 'Rp '.number_format((float) $order->total_amount, 0, ',', '.'),
            'tanggal' => $this->date($order->created_at),
            'nama_toko' => $store ?: 'Prime Classy',
            'kurir' => $couriers->isNotEmpty() ? $couriers->implode(', ') : null,
            'resi' => $resi->isNotEmpty() ? $resi->implode(', ') : null,
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

}
