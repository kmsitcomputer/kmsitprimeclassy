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

    /**
     * AUD-08/HA-04 partial payment: a verified DP/partial (payment_status `partially_paid`) is acknowledged WITHOUT
     * ever claiming full settlement. `payment_paid` stays the full-settlement event only.
     */
    public const PAYMENT_PARTIAL = 'payment_partial';

    public const ORDER_PROCESSED = 'order_processed';

    public const ORDER_SHIPPED = 'order_shipped';

    public const ORDER_DELIVERED = 'order_delivered';

    public const ORDER_CANCELLED = 'order_cancelled';

    public const RETURN_REQUESTED = 'return_requested';

    public const RETURN_APPROVED = 'return_approved';

    public const REFUND_COMPLETED = 'refund_completed';

    private const ORDER_VARS = ['nama_pelanggan', 'nomor_pesanan', 'status', 'total', 'tanggal', 'nama_toko'];

    /**
     * AUD-06 verified payment variables (PaymentSummaryService truth: never inferred from order status).
     * `{metode_pembayaran}` and `{tanggal_pembayaran}` come from the latest verified payment transaction;
     * amounts come from `PaymentSummaryService::summarize` (verified paid / remaining balance).
     */
    private const PAYMENT_VARS = [
        'nama_pelanggan', 'nomor_pesanan', 'status', 'total', 'tanggal', 'nama_toko',
        'daftar_produk', 'subtotal', 'diskon', 'ongkir', 'total_tagihan',
        'metode_pembayaran', 'status_pembayaran', 'jumlah_dibayarkan', 'sisa_tagihan', 'tanggal_pembayaran',
    ];

    /** AUD-06 verified shipping variables (Shipment truth: never invented, optional lines drop when unavailable). */
    private const SHIPPING_VARS = [
        'nama_pelanggan', 'nomor_pesanan', 'status', 'total', 'tanggal', 'nama_toko',
        'nama_kurir', 'layanan_pengiriman', 'nomor_resi', 'nomor_telepon_kurir', 'estimasi_tiba',
        'link_pesanan', 'link_pelacakan',
    ];

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
                'variables' => self::PAYMENT_VARS,
                'required' => ['nomor_pesanan'],
                'default' => "Halo {nama_pelanggan},\n\nPembayaran untuk pesanan {nomor_pesanan} telah kami terima.\nTotal: {total}\n\nTerima kasih.",
            ],
            self::PAYMENT_PARTIAL => [
                'label' => 'DP / pembayaran sebagian diterima',
                'variables' => self::PAYMENT_VARS,
                'required' => ['nomor_pesanan'],
                'default' => "Halo {nama_pelanggan},\n\nPembayaran sebagian (DP) untuk pesanan {nomor_pesanan} telah kami terima.\nDibayarkan: {jumlah_dibayarkan}\nSisa: {sisa_tagihan}\n\nStatus pesanan Anda masih menunggu pelunasan.",
            ],
            self::ORDER_PROCESSED => [
                'label' => 'Pesanan diproses',
                'variables' => self::ORDER_VARS,
                'required' => ['nomor_pesanan'],
                'default' => "Halo {nama_pelanggan},\n\nPesanan Anda {nomor_pesanan} sedang diproses.\nStatus: {status}",
            ],
            self::ORDER_SHIPPED => [
                'label' => 'Pesanan dikirim',
                'variables' => [...self::SHIPPING_VARS, 'kurir', 'resi'],
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
            'daftar_produk' => 'Produk Contoh x1', 'subtotal' => 'Rp 100.000', 'diskon' => 'Rp 0', 'ongkir' => 'Rp 10.000',
            'total_tagihan' => 'Rp 110.000', 'metode_pembayaran' => 'Transfer Bank', 'status_pembayaran' => 'Lunas',
            'jumlah_dibayarkan' => 'Rp 110.000', 'sisa_tagihan' => 'Rp 0', 'tanggal_pembayaran' => '29 Oktober 2026',
            'nama_kurir' => 'Kurir Contoh', 'layanan_pengiriman' => 'Reguler', 'nomor_resi' => 'RESI-CONTOH-001',
            // Preview-only placeholders: the real data() ALWAYS emits null here (no courier-phone / ETA source
            // exists; HA-06). Keeping obviously-fake values (not null) means a preview template that references
            // these vars still renders a visible line in the Super Admin console — the preview map is labelled
            // "obviously fake" and is never a data source.
            'nomor_telepon_kurir' => '(nomor kurir tidak tersedia)', 'estimasi_tiba' => '(estimasi tiba tidak tersedia)',
            'link_pesanan' => '/orders/PC-261028-000123', 'link_pelacakan' => '(tautan pelacakan tidak tersedia)',
        ];
    }
}
