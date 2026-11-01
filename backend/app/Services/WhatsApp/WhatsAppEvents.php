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

    /** A submitted manual-transfer proof was REJECTED by verification (BankTransferVerification.status -> rejected). */
    public const PAYMENT_FAILED = 'payment_failed';

    /** A return request finished with EVERY item rejected (ReturnRequest.status -> completed, no approved item). */
    public const RETURN_REJECTED = 'return_rejected';

    /** A refund obligation awaiting manual approval was recorded (RefundObligation created as `pending`). */
    public const REFUND_REQUESTED = 'refund_requested';

    /** A refund obligation was approved (created auto-approved, or pending/needs_verification -> approved). */
    public const REFUND_APPROVED = 'refund_approved';

    /** A refund obligation under verification was closed WITHOUT payment by the Owner (needs_verification -> cancelled). */
    public const REFUND_REJECTED = 'refund_rejected';

    /**
     * `{tanggal}` is always the ORDER date (order.created_at). Event-specific dates have their own variables
     * (`tanggal_pembayaran`, `tanggal_pengiriman`, `tanggal_diterima`, `tanggal_pembatalan`, `tanggal_refund`).
     * `{link_pesanan}` is an absolute order-detail URL; it is only ever rendered into FULL templates (admin/creator) —
     * restricted bodies are fixed server text and never carry a link.
     */
    private const ORDER_VARS = ['nama_pelanggan', 'nomor_pesanan', 'status', 'total', 'tanggal', 'nama_toko', 'link_pesanan'];

    /** Invoice snapshot (server-derived, never client input). Full templates only. */
    private const INVOICE_VARS = [
        'daftar_produk', 'subtotal', 'diskon', 'ongkir', 'total_tagihan',
        'metode_pembayaran', 'status_pembayaran', 'jumlah_dibayarkan', 'sisa_tagihan',
    ];

    /**
     * AUD-06 verified payment variables (PaymentSummaryService truth: never inferred from order status).
     * `{metode_pembayaran}`, `{tanggal_pembayaran}` and `{jumlah_pembayaran_ini}` come from the latest VERIFIED payment
     * transaction; `{jumlah_dibayarkan}` is the CUMULATIVE verified paid amount and `{sisa_tagihan}` the remaining
     * collectible balance (`PaymentSummaryService::summarize`).
     */
    private const PAYMENT_VARS = [...self::ORDER_VARS, ...self::INVOICE_VARS, 'tanggal_pembayaran', 'jumlah_pembayaran_ini'];

    /**
     * AUD-06 verified shipping variables (Shipment truth: never invented, optional lines drop when unavailable).
     * `{kurir}`/`{resi}` aggregate every non-cancelled shipment; `{nama_kurir}`/`{nomor_resi}` are the FIRST shipment only.
     */
    private const SHIPPING_VARS = [
        ...self::ORDER_VARS,
        'nama_kurir', 'layanan_pengiriman', 'nomor_resi', 'nomor_telepon_kurir', 'estimasi_tiba',
        'link_pelacakan', 'kurir', 'resi', 'tanggal_pengiriman',
    ];

    /** Standard WhatsApp footer (Master Template §H); the e-mail channel replaces it with the e-mail footer. */
    public const FOOTER ="\n\nPesan otomatis dari PrimeClassy. Jangan membagikan OTP atau kata sandi kepada siapa pun.";

    /** @return array<string, array{label:string, variables:array<int,string>, required:array<int,string>, default:string}> */
    public static function all(): array
    {
        return [
            self::ORDER_CREATED => [
                'label' => 'Pesanan berhasil dibuat',
                // Order-status events carry no payment variables beyond {total} (invariant pinned by
                // WhatsAppAgentScopeTest); the invoice breakdown belongs to the payment events.
                'variables' => self::ORDER_VARS,
                'required' => ['nomor_pesanan'],
                'default' => "*PRIMECLASSY — PESANAN BARU*\n\nHalo {nama_pelanggan},\n\nPesanan Anda berhasil dibuat.\n\n"
                    ."Nomor Pesanan: {nomor_pesanan}\nTanggal: {tanggal}\nTotal Pesanan: *{total}*\n\nLihat pesanan: {link_pesanan}\n\n"
                    ."Terima kasih telah berbelanja di {nama_toko}.".self::FOOTER,
            ],
            self::PAYMENT_PAID => [
                'label' => 'Pembayaran berhasil',
                'variables' => self::PAYMENT_VARS,
                'required' => ['nomor_pesanan'],
                'default' => "*PRIMECLASSY — PEMBAYARAN LUNAS*\n\nHalo {nama_pelanggan},\n\nPembayaran pesanan Anda telah dikonfirmasi.\n\n"
                    ."Nomor Pesanan: {nomor_pesanan}\nMetode Pembayaran: {metode_pembayaran}\nJumlah Diterima: *{jumlah_pembayaran_ini}*\n"
                    ."Total Pesanan: {total}\nTotal Dibayar: *{jumlah_dibayarkan}*\nSisa Tagihan: {sisa_tagihan}\nTanggal Pembayaran: {tanggal_pembayaran}\n\n"
                    ."Simpan pesan ini sebagai bukti transaksi.\nLihat pesanan: {link_pesanan}".self::FOOTER,
            ],
            self::PAYMENT_PARTIAL => [
                'label' => 'DP / pembayaran sebagian diterima',
                'variables' => self::PAYMENT_VARS,
                'required' => ['nomor_pesanan'],
                'default' => "*PRIMECLASSY — PEMBAYARAN PARSIAL*\n\nHalo {nama_pelanggan},\n\nKami telah menerima pembayaran sebagian (DP) untuk pesanan Anda.\n\n"
                    ."Nomor Pesanan: {nomor_pesanan}\nTotal Pesanan: {total}\nPembayaran Kali Ini: *{jumlah_pembayaran_ini}*\n"
                    ."Akumulasi Pembayaran: {jumlah_dibayarkan}\nSisa Tagihan: *{sisa_tagihan}*\n\nStatus Pembayaran: *Belum Lunas*\n\n"
                    ."Informasi pelunasan: {link_pesanan}".self::FOOTER,
            ],
            self::ORDER_PROCESSED => [
                'label' => 'Pesanan diproses',
                'variables' => self::ORDER_VARS,
                'required' => ['nomor_pesanan'],
                'default' => "*PRIMECLASSY — PESANAN DIPROSES*\n\nHalo {nama_pelanggan},\n\nPesanan *{nomor_pesanan}* saat ini sedang diproses.\nStatus: *{status}*\n\n"
                    ."Kami akan memberi kabar ketika status pesanan berubah.\nLihat pesanan: {link_pesanan}".self::FOOTER,
            ],
            self::ORDER_SHIPPED => [
                'label' => 'Pesanan dikirim',
                'variables' => self::SHIPPING_VARS,
                'required' => ['nomor_pesanan'],
                'default' => "*PRIMECLASSY — PESANAN DIKIRIM*\n\nHalo {nama_pelanggan},\n\nPesanan Anda {nomor_pesanan} telah dikirim dan sedang dalam perjalanan.\n\n"
                    ."Kurir: {kurir}\nNomor Resi: {resi}\nTanggal Pengiriman: {tanggal_pengiriman}\n\nPantau pesanan: {link_pesanan}\n\n"
                    ."Terima kasih telah berbelanja di {nama_toko}.".self::FOOTER,
            ],
            self::ORDER_DELIVERED => [
                'label' => 'Pesanan selesai (terkirim)',
                'variables' => [...self::ORDER_VARS, 'tanggal_diterima'],
                'required' => ['nomor_pesanan'],
                'default' => "*PRIMECLASSY — PESANAN SELESAI*\n\nHalo {nama_pelanggan},\n\nPesanan Anda {nomor_pesanan} telah sampai.\nTanggal Diterima: {tanggal_diterima}\n\n"
                    ."Terima kasih telah berbelanja di {nama_toko}.\nLihat pesanan: {link_pesanan}".self::FOOTER,
            ],
            self::ORDER_CANCELLED => [
                'label' => 'Pesanan dibatalkan',
                'variables' => [...self::ORDER_VARS, 'tanggal_pembatalan'],
                'required' => ['nomor_pesanan'],
                // Never promises a refund: refund status is its own verified event (refund_completed).
                'default' => "*PRIMECLASSY — PEMBATALAN PESANAN*\n\nHalo {nama_pelanggan},\n\nPesanan berikut telah dibatalkan:\n\n"
                    ."Nomor Pesanan: {nomor_pesanan}\nTanggal Pembatalan: {tanggal_pembatalan}\nStatus: *{status}*\n\n"
                    ."Jika Anda membutuhkan bantuan, hubungi layanan pelanggan {nama_toko}.".self::FOOTER,
            ],
            self::RETURN_REQUESTED => [
                'label' => 'Retur diajukan',
                'variables' => [...self::ORDER_VARS, 'nomor_retur'],
                'required' => ['nomor_pesanan'],
                'default' => "*PRIMECLASSY — PENGAJUAN RETUR*\n\nHalo {nama_pelanggan},\n\nPengajuan retur untuk pesanan {nomor_pesanan} telah kami terima.\n\n"
                    ."Nomor Retur: {nomor_retur}\nStatus: *MENUNGGU PENINJAUAN*\n\nTim kami akan meninjau pengajuan sesuai ketentuan PrimeClassy.".self::FOOTER,
            ],
            self::RETURN_APPROVED => [
                'label' => 'Retur disetujui',
                'variables' => [...self::ORDER_VARS, 'nomor_retur'],
                'required' => ['nomor_pesanan'],
                'default' => "*PRIMECLASSY — RETUR DISETUJUI*\n\nHalo {nama_pelanggan},\n\nPengajuan retur Anda telah disetujui.\n\n"
                    ."Nomor Retur: {nomor_retur}\nPesanan: {nomor_pesanan}\n\nIkuti petunjuk resmi sebelum mengirimkan barang.".self::FOOTER,
            ],
            self::REFUND_COMPLETED => [
                'label' => 'Refund berhasil',
                'variables' => [...self::ORDER_VARS, 'nomor_retur', 'jumlah_refund', 'tanggal_refund'],
                'required' => ['nomor_pesanan'],
                'default' => "*PRIMECLASSY — REFUND SELESAI*\n\nHalo {nama_pelanggan},\n\nPengembalian dana untuk pesanan Anda telah dicatat selesai.\n\n"
                    ."Nomor Pesanan: {nomor_pesanan}\nJumlah Refund: *{jumlah_refund}*\nTanggal Penyelesaian: {tanggal_refund}\nStatus: *SELESAI*\n\n"
                    ."Terima kasih atas kesabaran Anda.".self::FOOTER,
            ],
            self::PAYMENT_FAILED => [
                'label' => 'Pembayaran belum berhasil',
                'variables' => [...self::ORDER_VARS, 'jumlah_diajukan', 'alasan_penolakan'],
                'required' => ['nomor_pesanan'],
                'default' => "*PRIMECLASSY — PEMBAYARAN BELUM BERHASIL*\n\nHalo {nama_pelanggan},\n\nPembayaran untuk pesanan {nomor_pesanan} belum dapat dikonfirmasi.\n\n"
                    ."Jumlah Diajukan: {jumlah_diajukan}\nKeterangan: {alasan_penolakan}\n\nPeriksa informasi pembayaran: {link_pesanan}\n\n"
                    ."Jangan melakukan pembayaran ulang sebelum memastikan status transaksi sebelumnya.".self::FOOTER,
            ],
            self::RETURN_REJECTED => [
                'label' => 'Retur ditolak',
                'variables' => [...self::ORDER_VARS, 'nomor_retur'],
                'required' => ['nomor_pesanan'],
                'default' => "*PRIMECLASSY — HASIL PENINJAUAN RETUR*\n\nHalo {nama_pelanggan},\n\nSetelah peninjauan, pengajuan retur {nomor_retur} untuk pesanan {nomor_pesanan} belum dapat disetujui.\n\n"
                    ."Silakan hubungi layanan pelanggan {nama_toko} jika membutuhkan penjelasan.".self::FOOTER,
            ],
            self::REFUND_REQUESTED => [
                'label' => 'Permohonan refund diterima',
                'variables' => [...self::ORDER_VARS, 'nomor_refund', 'jumlah_refund'],
                'required' => ['nomor_pesanan'],
                // Never implies approval: a pending obligation is still under review.
                'default' => "*PRIMECLASSY — PERMOHONAN REFUND*\n\nHalo {nama_pelanggan},\n\nPermohonan pengembalian dana telah kami terima.\n\n"
                    ."Nomor Refund: {nomor_refund}\nNomor Pesanan: {nomor_pesanan}\nStatus: *DALAM PENINJAUAN*\n\n"
                    ."Permohonan ini belum berarti pengembalian dana telah disetujui.".self::FOOTER,
            ],
            self::REFUND_APPROVED => [
                'label' => 'Refund disetujui',
                'variables' => [...self::ORDER_VARS, 'nomor_refund', 'jumlah_refund'],
                'required' => ['nomor_pesanan'],
                'default' => "*PRIMECLASSY — REFUND DISETUJUI*\n\nHalo {nama_pelanggan},\n\nPermohonan refund Anda telah disetujui.\n\n"
                    ."Nomor Refund: {nomor_refund}\nJumlah Disetujui: *{jumlah_refund}*\nStatus: *MENUNGGU PENYELESAIAN*\n\n"
                    ."Kami akan memberi kabar setelah pengembalian dana tercatat selesai.".self::FOOTER,
            ],
            self::REFUND_REJECTED => [
                'label' => 'Refund ditolak',
                'variables' => [...self::ORDER_VARS, 'nomor_refund'],
                'required' => ['nomor_pesanan'],
                // The internal cancel_reason is an operator code, never shown to the customer.
                'default' => "*PRIMECLASSY — HASIL PENINJAUAN REFUND*\n\nHalo {nama_pelanggan},\n\nPermohonan refund {nomor_refund} untuk pesanan {nomor_pesanan} belum dapat disetujui.\n\n"
                    ."Jika membutuhkan penjelasan, silakan hubungi layanan pelanggan {nama_toko}.".self::FOOTER,
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
            'link_pesanan' => 'https://contoh.primeclassy.test/orders/123', 'link_pelacakan' => '(tautan pelacakan tidak tersedia)',
            'jumlah_pembayaran_ini' => 'Rp 110.000', 'tanggal_pengiriman' => '30 Oktober 2026', 'tanggal_diterima' => '31 Oktober 2026',
            'tanggal_pembatalan' => '29 Oktober 2026', 'tanggal_refund' => '2 November 2026',
            'nomor_refund' => 'RO-CONTOH-0001', 'jumlah_diajukan' => 'Rp 110.000', 'alasan_penolakan' => 'Nominal transfer tidak sesuai',
        ];
    }
}
