# RECON-02 — BUSINESS FLOW & RBAC

Tanggal pemeriksaan: 2026-11-11T21:08:06+07:00 (WIB). Repository: `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy`. Branch: `fix/transaction-reset-financial-dependencies`. HEAD: `6a14041c06e6258c9f011fcb11271c7d34d65d17`.

**RECON ONLY · proposal untuk Owner Review · bukan Architecture Lock.** Source, DB, konfigurasi, dan production tidak diubah; tidak ada test/build/migration/commit/push/deploy. Bukti static tidak berarti runtime PASS.

Paket: [RECON-01](RECON-01-EXISTING-SYSTEM-INVENTORY.md) · [RECON-02](RECON-02-BUSINESS-FLOW-RBAC.md) · [RECON-03](RECON-03-DATABASE-API-MAPPING.md) · [RECON-04](RECON-04-GAP-ANALYSIS.md) · [RECON-05](RECON-05-REMEDIATION-ARCHITECTURE-PROPOSAL.md) · [RECON-06](RECON-06-RISK-TEST-STRATEGY.md) · [RECON-07](RECON-07-OWNER-DECISION-REGISTER.md)

## Identitas organisasi

Satu role per user melalui role_id; “Korsal adalah Agen” pada POS berarti Agen menjalankan fungsi pilihan Korsal, bukan membuat role gabungan/akun Sales palsu. Katalog global; ownership transaksi/inventory per agent_id. users.parent_id, agent_id, korsal_id, sales_id dan user_closures adalah struktur jaringan existing. Evidence EV-X-004, EV-11-002/005/006/007 dan docs/04.

| Role | Scope existing | Create order existing | POS locked / implikasi |
|---|---|---|---|
| super_admin | Semua cabang sesuai endpoint | Tidak pada StoreOrderRequest | Diizinkan POS; sumber/Admin branch perlu keputusan |
| agen | Cabang sendiri; agent_id=id | Self dan konsumen cabang; CRM assisted | POS diizinkan; stok Agen sendiri saja |
| admin | Cabang sendiri, operasional/financial read sesuai proyeksi | Tidak pada StoreOrderRequest | POS diizinkan dengan request/action khusus, bukan membuka checkout umum |
| keuangan | Cabang finansial, verifikasi pembayaran | Tidak | POS tidak diizinkan; tetap pemverifikasi pembayaran |
| korsal | Downline historis dan current referral scope | Self/konsumen jaringan; assisted CRM | Tidak boleh POS; checkout existing tetap |
| sales | Konsumen referral sendiri | Self/konsumen jaringan; CRM sendiri | Tidak boleh POS; hak referral/order tetap |
| sales-kurir-sub (SKS) | Referral sendiri + lokasi Sub miliknya | Self/referral: Agent atau Sub server-resolved | Tidak boleh POS; REM-09 dua sumber tetap |
| koordinator-kurir | Dispatch cabang; executor jika assigned sah | Tidak | Tidak POS; kewenangan penugasan existing dipertahankan |
| kurir | Shipment dan retur assigned saja | Tidak | Tidak POS; gunakan /kurir/orders, bukan generic List Order |
| gudang | Inventory/fulfilment operasional tanpa uang | Tidak | Tidak POS |
| konsumen | Order milik sendiri | Self; saat ini full bank transfer | Tidak POS; akun pemesan hanya sesuai scope yang disetujui |

## Permission matrix yang harus dipertahankan

| Aksi | Otoritas existing | Evidence |
|---|---|---|
| Create/update product & variation | super_admin, agen, admin | EV-04-005, EV-X-002 |
| Delete product/variation | super_admin, agen; admin dilarang | EV-X-002; REM-04 tidak mengotorisasi Admin delete |
| Category write/CMS | super_admin | EV-X-002; api_v1 CMS role group |
| Create regular order | konsumen, agen, korsal, sales, SKS + policy jaringan | EV-11-001; OrderPolicy::create |
| Upload payment proof/cash request | scope owner/on-behalf/office sesuai policy | EV-X-001, EV-11-008 |
| Verify payment/COD | super_admin, keuangan; bukan pembuat cash record | EV-X-001; PaymentService; MasterV2PaymentCoreTest:152,245 |
| Assign courier | super_admin, agen, admin, koordinator-kurir via dispatch existing | docs/05 §11/12; ShipmentPolicy/CourierService; bukan aksi otomatis POS |
| SC-03 add line | admin saja | docs/05 §22; tidak diperluas oleh POS |
| Delivery verification | super_admin/admin cabang | docs/05 §12; terpisah payment |
| Invoice financial | Pemesan order + financial roles cabang + scoped Sales/Korsal | EV-11-007; SKS as courier tidak otomatis punya invoice |
| Generic List Order | Branch/owner/referral; kurir route tersendiri | EV-11-006/007 |

## Flow katalog → cart → order

1. ProductListingView/ProductDetailView memakai public `/products`; index active dan primary-only; detail slug active dan semua images. Availability hanya Agen viewer dari server. Cart menyimpan product/variation id, quantity dan display snapshot lokal; bukan price authority (EV-03-002, EV-09-002).
2. CheckoutView mengirim ids/qty/destination/payment/datedelivery; belum mengirim sumber stok (EV-09-003). Server StoreOrderRequest memvalidasi bentuk; OrderController resolve buyer dan authorize create. Idempotency-Key wajib (EV-11-001 dan OrderController:130–169).
3. OrderService resolve origin, agent dan source satu kali untuk order; transaction membuat order, prelock canonical inventory, reserve setiap item, snapshot harga/fee/label/SKU; create commissions sekali. Source disimpan per item tetapi satu source dipilih di batas order (EV-09-004, EV-11-004/005).
4. COD mulai diproses dengan payment unpaid; transfer mulai diterima. DP bukan toggle lunas. PricingService dan shipping quote menghitung ulang server; voucher existing mengikuti izin/config, tidak manual price.
5. ShipmentCanonicalizationService mengelompokkan Order + requested_delivery_date + mode + pemilik kompatibel. Ongkir snapshot satu kali pada shipment pertama yang sesuai. Jangan membuat shipment per barang tanpa grouping.
6. OrderNotifier/event setelah commit; order yang rollback tidak boleh mengirim notifikasi. Jangan create order per dashboard.

## Inventory, fulfilment, cancel dan reschedule

Agent Sellable = Transit + Factory Plan jika aktif − Agent Reserved; fallback legacy hanya target tanpa warehouse rows. Sub Sellable = fisik Sub − active Sub Reserved. Stok Sub tidak ditambahkan ke Agent. Reserve saat create/increase; Agent handover yang diterima koordinator menggeser Transit→Shipping dan melepas reserved atomik. Sub self shipment consume reserve dan deduct fisik sekali. Cancel sebelum shipment release reserve; reversal setelah fulfilment idempotent; barang terkirim kembali hanya lewat retur. EV-09-004/005/006; docs/05 §8–12.

Urutan lock: Order→Stock Request→inventory; multi-target memakai StockService::canonicalReservationTargets. Domain Sub: WarehouseSubLocation parent→WarehouseStock targets→reservation rows. Tidak memakai sleeps/menelan 1213. Audit static menemukan mekanisme existing, **bukan jaminan concurrency runtime terbaru**.

Partial fulfilment bergrain item; sisa kurang bisa di-reschedule/cancel tanpa menyentuh handed-over units. Split mempertahankan lineage dan conservation kuantitas/fee. Date boleh dijadwal ulang hanya provider openroute atau self_sub, bukan expedition/pickup. Kurir ditugaskan sah dan bukti foto delivery wajib; tanggal rencana tidak mengubah shipped status. Self_sub memiliki owner SKS dan courier_id NULL; self pickup harus SKS yang juga placed_by. EV-X-005/006; docs/05 §11/12/21.

## Payment lifecycle

Order create → payment transaction pending → proof/cash receipt → finance verification → PaymentService canonical recomputation → invoice issuance/snapshot. Pending/rejected/submitted DP tidak menambah paid. Installment cash tersedia sebagai kanal penerimaan, bukan metode awal tunai bebas yang otomatis paid. Metode active global DB: COD/transfer/down_payment; branch availability dan shipping compatibility tetap harus diperiksa. QRIS tidak ditemukan sebagai metode active; jangan membuat gateway baru terselubung. EV-DB-002, EV-11-008/009/010.

Refund berasal overpayment nyata dan ledger existing, bukan membalik status frontend. Return request menghitung returned_quantity lebih awal; rejected return dapat menurunkannya kembali. refund_quantity bertambah saat processed refund. Karena itu kandidat bestseller tidak boleh mengurangi keduanya tanpa definisi tahap/event (EV-07-003). Commission sales adjustment existing memakai snapshot unit fee, bukan persentase nominal refund; courier fee terpisah.

## Referral, konsumen, pemesan dan POS

Actor placed_by tidak berarti buyer/referrer/beneficiary. Pada regular account order buyer adalah konsumen_id; sales/korsal snapshot mengikuti buyer. Pada CRM-assisted order buyer crm_customer_id dengan name/phone snapshot, account konsumen_id NULL, attribution carrier mengikuti owner seller, bukan actor. Existing tidak menyimpan optional consumer PLUS requester account/manual independen; ini gap REM-11, bukan alasan menyalahgunakan notes/raw_payload. EV-11-002/003/004/005.

POS harus memvalidasi pilihan Agen→fungsi Korsal→Sales/Referral→Konsumen optional server-side. Perubahan referral membersihkan konsumen di UI dan menolak konsumen mismatch di backend. Atribusi direct Agen harus mengikuti fallback Agen existing, tanpa Sales fiktif. Kasus requester beda dari consumer/referral masih memerlukan kontrak data aditif yang direview Owner.

List Order sudah memakai satu primary orders record, snapshot/current referral scope untuk Sales/SKS/Korsal, branch scope office, buyer scope konsumen. POS harus menghasilkan attributive fields yang benar sehingga List existing bekerja; jangan menambahkan placed_by ke semua visibility secara otomatis atau membuat personal sales Admin. Kurir baru melihat pekerjaan setelah assignment sah melalui proyeksi operasional (EV-11-006/007).

## Status verifikasi flow

Seluruh uraian flow bersifat source-verified atau aturan kanonis yang disebutkan; tidak ada end-to-end transaksi dijalankan. POS belum ada. Rincian requirement runtime/visual yang partial/blocked ada RECON-04. Tidak ditemukan alasan untuk membuat sistem inventory/payment/referral/invoice paralel. Pemenuhan seluruh field POS tanpa tabel baru masih tergantung keputusan identitas dan sumber Admin; belum diberi verdict final.
