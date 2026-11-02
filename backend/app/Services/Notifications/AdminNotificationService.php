<?php

namespace App\Services\Notifications;

use App\Models\AdminNotification;
use App\Models\Order;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppEvents;
use Illuminate\Support\Collection;

/**
 * In-app notification centre for operators.
 *
 *  - Fan-out is one row PER RECIPIENT account, so every read is scoped to the caller by construction (no IDOR surface).
 *  - Branch facts go to that branch's operators only (its Agen account + its Admins). Platform facts (delivery failure,
 *    gateway status) additionally reach every Super Admin.
 *  - `dedupe_key` is UNIQUE: a replayed fact never yields a second row for the same person.
 *  - Bodies are short operational summaries: no phone numbers, credentials, OTPs or bank details.
 */
final class AdminNotificationService
{
    /** Order facts operators must act on (NTF-030 and its follow-ups). */
    private const ORDER_EVENTS = [
        WhatsAppEvents::ORDER_CREATED => ['order.created', AdminNotification::INFO, 'Pesanan baru'],
        WhatsAppEvents::PAYMENT_FAILED => ['payment.failed', AdminNotification::WARNING, 'Pembayaran ditolak'],
        WhatsAppEvents::RETURN_REQUESTED => ['return.requested', AdminNotification::INFO, 'Pengajuan retur baru'],
        WhatsAppEvents::REFUND_REQUESTED => ['refund.requested', AdminNotification::WARNING, 'Refund menunggu persetujuan'],
    ];

    /** @param  array<string, string|null>  $vars */
    public function orderEvent(string $event, Order $order, string $subjectType, int $subjectId, array $vars): void
    {
        if (! isset(self::ORDER_EVENTS[$event])) {
            return;
        }
        [$type, $severity, $title] = self::ORDER_EVENTS[$event];

        $parts = array_filter([
            'Pesanan '.$order->order_no,
            isset($vars['total']) ? 'Total '.$vars['total'] : null,
            $event === WhatsAppEvents::REFUND_REQUESTED && isset($vars['jumlah_refund']) ? 'Refund '.$vars['jumlah_refund'] : null,
            $event === WhatsAppEvents::RETURN_REQUESTED && isset($vars['nomor_retur']) ? 'Retur '.$vars['nomor_retur'] : null,
        ]);

        $this->record($type, $severity, $title, implode(' · ', $parts), $order->agent_id, $order->id, $subjectType, $subjectId, includeSuperAdmins: false);
    }

    /**
     * Payment proof uploaded: ONLY the given Keuangan accounts (FinanceRecipientResolver). No operator fan-out, so no other
     * role — Super Admin, Admin, Agen — is ever added. Dedupe per proof file, verification and recipient.
     *
     * @param  Collection<int, User>  $users
     */
    public function paymentProofUploaded(Order $order, \App\Models\BankTransferVerification $verification, string $proofHash, Collection $users): void
    {
        if (! AdminNotification::tableReady()) {
            return;
        }
        $title = 'Bukti Pembayaran Baru';
        $body = sprintf('Konsumen %s mengunggah bukti pembayaran untuk pesanan %s. Menunggu verifikasi Keuangan.', $order->konsumen?->name ?? 'pelanggan', $order->order_no);

        foreach ($users as $user) {
            AdminNotification::query()->createOrFirst(
                ['dedupe_key' => mb_substr("payment.proof:{$verification->id}:{$proofHash}:{$user->id}", 0, 191)],
                [
                    'recipient_user_id' => $user->id, 'agent_id' => $order->agent_id, 'type' => 'payment.proof_uploaded',
                    'severity' => AdminNotification::WARNING, 'title' => $title, 'body' => mb_substr($body, 0, 500),
                    'order_id' => $order->id, 'subject_type' => 'bank_transfer_verification', 'subject_id' => $verification->id,
                ],
            );
        }
    }

    /** NTF-033: a transactional message could not be delivered (WhatsApp failed/unknown, e-mail failed). */
    public function deliveryFailed(string $channel, string $event, ?int $agentId, ?int $orderId, string $subjectType, int $subjectId, string $status): void
    {
        $label = WhatsAppEvents::all()[$event]['label'] ?? $event;
        $order = $orderId ? Order::withoutGlobalScopes()->whereKey($orderId)->value('order_no') : null;
        $body = sprintf('Kanal %s · %s · status %s%s', $channel === 'email' ? 'Email' : 'WhatsApp', $label, $status, $order ? ' · Pesanan '.$order : '');

        $this->record('delivery.failed', $status === 'unknown' ? AdminNotification::WARNING : AdminNotification::CRITICAL,
            'Pengiriman notifikasi membutuhkan perhatian', $body, $agentId, $orderId, $subjectType, $subjectId, includeSuperAdmins: true);
    }

    /** NTF-034: an Agen's WhatsApp device lost or regained its connection. */
    public function gatewayStatusChanged(int $agentId, int $deviceRowId, string $from, string $to, ?string $phoneMasked): void
    {
        $lost = $from === 'connected';
        $body = sprintf('Perangkat %s · %s → %s', $phoneMasked ?: '(belum terhubung)', $from, $to);

        $this->record('gateway.status', $lost ? AdminNotification::WARNING : AdminNotification::INFO,
            $lost ? 'Perangkat WhatsApp terputus' : 'Perangkat WhatsApp terhubung kembali', $body, $agentId, null,
            'agent_whatsapp_device', $deviceRowId, includeSuperAdmins: true, dedupeSuffix: $to.':'.now()->format('YmdHi'));
    }

    private function record(
        string $type, string $severity, string $title, string $body, ?int $agentId, ?int $orderId,
        ?string $subjectType, ?int $subjectId, bool $includeSuperAdmins, string $dedupeSuffix = '',
    ): void {
        if (! AdminNotification::tableReady()) {
            return;
        }
        $base = $type.':'.$subjectType.':'.$subjectId.($dedupeSuffix !== '' ? ':'.$dedupeSuffix : '');

        foreach ($this->operators($agentId, $includeSuperAdmins) as $user) {
            AdminNotification::query()->createOrFirst(
                ['dedupe_key' => mb_substr($base.':'.$user->id, 0, 191)],
                [
                    'recipient_user_id' => $user->id, 'agent_id' => $agentId, 'type' => $type, 'severity' => $severity,
                    'title' => mb_substr($title, 0, 150), 'body' => mb_substr($body, 0, 500), 'order_id' => $orderId,
                    'subject_type' => $subjectType, 'subject_id' => $subjectId,
                ],
            );
        }
    }

    /** @return Collection<int, User> */
    private function operators(?int $agentId, bool $includeSuperAdmins): Collection
    {
        $users = collect();
        if ($agentId !== null) {
            $users = User::query()
                ->where(fn ($q) => $q
                    ->where(fn ($w) => $w->whereKey($agentId)->whereHas('role', fn ($r) => $r->where('slug', 'agen')))
                    ->orWhere(fn ($w) => $w->where('agent_id', $agentId)->whereHas('role', fn ($r) => $r->where('slug', 'admin'))))
                ->get();
        }
        if ($includeSuperAdmins) {
            $users = $users->merge(User::query()->whereHas('role', fn ($r) => $r->where('slug', 'super_admin'))->get());
        }

        return $users->unique('id')->values();
    }
}
