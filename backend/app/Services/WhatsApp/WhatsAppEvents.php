<?php

namespace App\Services\WhatsApp;

/**
 * The business events that may produce a TRANSACTIONAL WhatsApp message. Every entry maps to a lifecycle fact that
 * actually exists in PrimeClassy (no invented status). There is no "dikemas" status in the order lifecycle, so there
 * is deliberately no event for it. Transactional and promotional traffic are separate purposes: nothing here is a
 * promotion, and a promotion may never reuse these events' recipients or consent.
 *
 * `variables` is the allow-list for that event's template; `required` ones must resolve or the message is skipped,
 * every other variable is optional (a template line whose optional variable is unavailable is dropped, never faked).
 */
final class WhatsAppEvents
{
    public const ORDER_CREATED = 'order_created';

    public const PAYMENT_PAID = 'payment_paid';

    public const ORDER_PROCESSED = 'order_processed';

    public const ORDER_SHIPPED = 'order_shipped';

    public const ORDER_DELIVERED = 'order_delivered';

    public const ORDER_CANCELLED = 'order_cancelled';

    public const RETURN_REQUESTED = 'return_requested';

    public const RETURN_APPROVED = 'return_approved';

    public const REFUND_COMPLETED = 'refund_completed';

    private const ORDER_VARS = ['nama_pelanggan', 'nomor_pesanan', 'status', 'total', 'tanggal', 'nama_toko'];

    /** @return array<string, array{label:string, variables:array<int,string>, required:array<int,string>, default:string}> */
    public static function all(): array
    {
        return [
            self::ORDER_CREATED => [
                'label' => 'Pesanan berhasil dibuat',
                'variables' => self::ORDER_VARS,
                'required' => ['nomor_pesanan'],
                'default' => "Halo {nama_pelanggan},\n\nPesanan Anda {nomor_pesanan} berhasil dibuat.\nTotal: {total}\n\nTerima kasih telah berbelanja di {nama_toko}.",
            ],
            self::PAYMENT_PAID => [
                'label' => 'Pembayaran berhasil',
                'variables' => self::ORDER_VARS,
                'required' => ['nomor_pesanan'],
                'default' => "Halo {nama_pelanggan},\n\nPembayaran untuk pesanan {nomor_pesanan} telah kami terima.\nTotal: {total}\n\nTerima kasih.",
            ],
            self::ORDER_PROCESSED => [
                'label' => 'Pesanan diproses',
                'variables' => self::ORDER_VARS,
                'required' => ['nomor_pesanan'],
                'default' => "Halo {nama_pelanggan},\n\nPesanan Anda {nomor_pesanan} sedang diproses.\nStatus: {status}",
            ],
            self::ORDER_SHIPPED => [
                'label' => 'Pesanan dikirim',
                'variables' => [...self::ORDER_VARS, 'kurir', 'resi'],
                'required' => ['nomor_pesanan'],
                'default' => "Halo {nama_pelanggan},\n\nPesanan Anda {nomor_pesanan} telah dikirim.\n\nKurir: {kurir}\nNomor Resi: {resi}\n\nTerima kasih telah berbelanja di {nama_toko}.",
            ],
            self::ORDER_DELIVERED => [
                'label' => 'Pesanan selesai (terkirim)',
                'variables' => self::ORDER_VARS,
                'required' => ['nomor_pesanan'],
                'default' => "Halo {nama_pelanggan},\n\nPesanan Anda {nomor_pesanan} telah sampai. Terima kasih telah berbelanja di {nama_toko}.",
            ],
            self::ORDER_CANCELLED => [
                'label' => 'Pesanan dibatalkan',
                'variables' => self::ORDER_VARS,
                'required' => ['nomor_pesanan'],
                'default' => "Halo {nama_pelanggan},\n\nPesanan Anda {nomor_pesanan} telah dibatalkan.\nStatus: {status}",
            ],
            self::RETURN_REQUESTED => [
                'label' => 'Retur diajukan',
                'variables' => [...self::ORDER_VARS, 'nomor_retur'],
                'required' => ['nomor_pesanan'],
                'default' => "Halo {nama_pelanggan},\n\nPermintaan retur {nomor_retur} untuk pesanan {nomor_pesanan} telah kami terima dan akan ditinjau.",
            ],
            self::RETURN_APPROVED => [
                'label' => 'Retur disetujui',
                'variables' => [...self::ORDER_VARS, 'nomor_retur'],
                'required' => ['nomor_pesanan'],
                'default' => "Halo {nama_pelanggan},\n\nRetur {nomor_retur} untuk pesanan {nomor_pesanan} telah disetujui.",
            ],
            self::REFUND_COMPLETED => [
                'label' => 'Refund berhasil',
                'variables' => [...self::ORDER_VARS, 'nomor_retur', 'jumlah_refund'],
                'required' => ['nomor_pesanan'],
                'default' => "Halo {nama_pelanggan},\n\nRefund untuk pesanan {nomor_pesanan} telah diproses.\nJumlah: {jumlah_refund}",
            ],
        ];
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $event): bool
    {
        return array_key_exists($event, self::all());
    }

    /** @return array<string, string> sample data for previews (obviously fake). */
    public static function sample(): array
    {
        return [
            'nama_pelanggan' => 'Budi Santoso', 'nomor_pesanan' => 'PC-261028-000123', 'status' => 'Dikirim',
            'total' => 'Rp 110.000', 'tanggal' => '29 Oktober 2026', 'nama_toko' => 'Prime Classy',
            'kurir' => 'Kurir Contoh', 'resi' => 'RESI-CONTOH-001', 'nomor_retur' => 'RTN-000045', 'jumlah_refund' => 'Rp 55.000',
        ];
    }
}
